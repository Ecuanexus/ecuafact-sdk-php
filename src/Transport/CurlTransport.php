<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Transport;

use Ecuafact\Sdk\EcuafactConfigurationException;
use Ecuafact\Sdk\EcuafactConnectionException;
use Ecuafact\Sdk\EcuafactTimeoutException;

/**
 * Transporte HTTP basado en cURL. Reutiliza el mismo handle entre solicitudes (keep-alive).
 *
 * - `timeout`: tiempo maximo por solicitud, en segundos (admite fracciones; se aplica en ms).
 * - `connectTimeout`: tiempo maximo para conectar, en segundos; null usa el default de libcurl.
 * - `proxy`: URL del proxy (`http://usuario:clave@host:puerto`); null usa las variables de entorno de libcurl.
 * - `caInfo`: ruta del bundle de CA (PEM) para validar el certificado del servidor.
 * - `verifyPeer`: false desactiva la validacion del certificado y del nombre del host. Solo para pruebas locales.
 * - `curlOptions`: opciones CURLOPT_* extra; se aplican al final y pueden reemplazar las anteriores.
 */
final class CurlTransport implements TimeoutOverrideTransportInterface
{
    private ?\CurlHandle $handle = null;

    /** @param array<int, mixed> $curlOptions */
    public function __construct(
        private readonly float $timeout = 100.0,
        private readonly ?float $connectTimeout = null,
        #[\SensitiveParameter] private readonly ?string $proxy = null,
        private readonly ?string $caInfo = null,
        private readonly bool $verifyPeer = true,
        private readonly array $curlOptions = [],
    ) {
        if (!is_finite($timeout) || $timeout <= 0) {
            throw new EcuafactConfigurationException('timeout debe ser mayor que 0 segundos.');
        }
        if ($connectTimeout !== null && (!is_finite($connectTimeout) || $connectTimeout <= 0)) {
            throw new EcuafactConfigurationException('connectTimeout debe ser mayor que 0 segundos.');
        }
    }

    public function request(string $method, string $url, #[\SensitiveParameter] array $headers, ?string $body): TransportResponse
    {
        return $this->requestWithTimeout($method, $url, $headers, $body, $this->timeout);
    }

    public function requestWithTimeout(
        string $method,
        string $url,
        #[\SensitiveParameter] array $headers,
        ?string $body,
        float $timeoutSeconds
    ): TransportResponse {
        return $this->execute($method, $url, $headers, $body, min($timeoutSeconds, $this->timeout));
    }

    /** Igual que requestWithTimeout, pero `$timeoutSeconds` reemplaza al timeout configurado. */
    public function requestWithAttemptTimeout(
        string $method,
        string $url,
        #[\SensitiveParameter] array $headers,
        ?string $body,
        float $timeoutSeconds
    ): TransportResponse {
        if (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0) {
            throw new EcuafactConfigurationException('timeout debe ser mayor que 0 segundos.');
        }
        return $this->execute($method, $url, $headers, $body, $timeoutSeconds);
    }

    /** @param string[] $headers */
    private function execute(
        string $method,
        string $url,
        #[\SensitiveParameter] array $headers,
        ?string $body,
        float $timeoutSeconds
    ): TransportResponse {
        $handle = $this->handle();
        $responseHeaders = [];
        $options = $this->curlOptions($timeoutSeconds);
        $options[CURLOPT_URL] = $url;
        $options[CURLOPT_CUSTOMREQUEST] = $method;
        $options[CURLOPT_HTTPHEADER] = $headers;
        $options[CURLOPT_HEADERFUNCTION] = static function ($curl, string $line) use (&$responseHeaders): int {
            if (str_starts_with($line, 'HTTP/')) {
                // Nueva respuesta (100-continue o similar): se descartan las cabeceras previas.
                $responseHeaders = [];
                return strlen($line);
            }
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[trim($parts[0])] = trim($parts[1]);
            }
            return strlen($line);
        };
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        foreach ($this->curlOptions as $option => $value) {
            $options[$option] = $value;
        }
        if (!curl_setopt_array($handle, $options)) {
            throw new EcuafactConfigurationException('Una de las opciones de cURL (curlOptions) no es valida.');
        }
        $result = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        if ($result === false || $errno !== 0) {
            $this->handle = null;
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                throw new EcuafactTimeoutException('Se agoto el tiempo de espera de la solicitud. ' . $error, $errno);
            }
            throw new EcuafactConnectionException('No hay conexion con el API. ' . $error, $errno);
        }
        return new TransportResponse($status, (string) $result, $responseHeaders);
    }

    /**
     * Opciones cURL base que aplica este transporte (sin URL, metodo, cabeceras ni cuerpo).
     * Util para diagnostico y pruebas.
     *
     * @return array<int, mixed>
     */
    public function curlOptions(?float $timeoutSeconds = null): array
    {
        $timeout = $timeoutSeconds ?? $this->timeout;
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => max(1, (int) ceil($timeout * 1000)),
            CURLOPT_SSL_VERIFYPEER => $this->verifyPeer,
            CURLOPT_SSL_VERIFYHOST => $this->verifyPeer ? 2 : 0,
        ];
        if ($this->connectTimeout !== null) {
            $options[CURLOPT_CONNECTTIMEOUT_MS] = max(1, (int) ceil($this->connectTimeout * 1000));
        }
        if ($this->proxy !== null && $this->proxy !== '') {
            $options[CURLOPT_PROXY] = $this->proxy;
        }
        if ($this->caInfo !== null && $this->caInfo !== '') {
            $options[CURLOPT_CAINFO] = $this->caInfo;
        }
        return $options;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'timeout' => $this->timeout,
            'connectTimeout' => $this->connectTimeout,
            'proxy' => $this->proxy === null ? null : '***',
            'caInfo' => $this->caInfo,
            'verifyPeer' => $this->verifyPeer,
            'curlOptions' => array_keys($this->curlOptions),
        ];
    }

    private function handle(): \CurlHandle
    {
        if ($this->handle === null) {
            $handle = curl_init();
            if ($handle === false) {
                throw new EcuafactConnectionException('No fue posible iniciar la solicitud HTTP.');
            }
            $this->handle = $handle;
        } else {
            curl_reset($this->handle);
        }
        return $this->handle;
    }
}
