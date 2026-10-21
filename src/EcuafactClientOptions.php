<?php

declare(strict_types=1);

namespace Ecuafact\Sdk;

use Ecuafact\Sdk\Transport\TransportInterface;

/**
 * Configuracion del cliente Ecuafact.
 *
 * - `baseAddress`: URL base del despliegue, sin `/v1` (por ejemplo `https://staging-api.mynexusapi.com/`).
 *   Si no la pasas, se lee de la variable de entorno `ECUAFACT_BASE_URL`.
 * - `apiKey`: credencial de la integracion (cabecera `X-Api-Key`). Si no la pasas, se lee de
 *   `ECUAFACT_API_KEY`. Nunca aparece en `var_dump`, `print_r`, `json_encode` ni en las trazas.
 * - Reintentos: `retryTransientFailures` (los desactiva todos), `maxAttempts`, `respectRetryAfter`,
 *   `maxRetryDelaySeconds`, `retryRateLimited` (429/104; el 429/501 de cupo nunca se reintenta),
 *   `retryableStatusCodes`, `retryBaseDelaySeconds`, `retryBackoff`, `retryJitterSeconds` y
 *   `totalTimeoutSeconds` (tope de toda la llamada, incluidos reintentos).
 * - Conexion (solo con el transporte cURL por defecto): `timeout` (por intento; 30 s por defecto),
 *   `connectTimeout`, `proxy`, `caInfo`, `verifyPeer` y `curlOptions`. Con `transport` usas tu
 *   propio transporte.
 * - `additionalInfo`: valor de la cabecera `X-Additional-Info` en todas las solicitudes. ASCII
 *   imprimible, sin espacios al inicio ni al final, maximo 256 caracteres. Null o vacio no la envia.
 *   No aparece en `var_dump` ni en las excepciones.
 *
 * Una opcion invalida lanza {@see EcuafactConfigurationException}.
 *
 * @property-read string $apiKey Credencial X-Api-Key (solo lectura).
 */
final class EcuafactClientOptions
{
    /** Codigos HTTP reintentables por defecto. El 429 se controla con retryRateLimited. */
    public const DEFAULT_RETRYABLE_STATUS_CODES = [408, 425, 500, 502, 503, 504];

    public readonly string $baseAddress;
    /** Devuelve la credencial; un closure no la expone en var_dump, print_r, (array), var_export ni json_encode. */
    private readonly \Closure $credencial;
    /** @var int[] */
    public readonly array $retryableStatusCodes;
    public readonly RetryBackoff $retryBackoff;
    /** Valor de la cabecera X-Additional-Info en todas las solicitudes; null no la envia. */
    public readonly ?string $additionalInfo;

    /** Longitud maxima de X-Additional-Info. */
    public const ADDITIONAL_INFO_MAX = 256;

    /**
     * @param int[]|null $retryableStatusCodes
     * @param array<int, mixed> $curlOptions
     */
    public function __construct(
        string|false|null $baseAddress = null,
        #[\SensitiveParameter] string|false|null $apiKey = null,
        public readonly ?string $identificacion = null,
        public readonly float $timeout = 30.0,
        public readonly string $userAgent = 'Ecuafact.Sdk/1.0',
        public readonly bool $retryTransientFailures = true,
        public readonly int $maxAttempts = 3,
        public readonly ?TransportInterface $transport = null,
        public readonly bool $respectRetryAfter = true,
        public readonly float $maxRetryDelaySeconds = 60.0,
        public readonly bool $retryRateLimited = false,
        ?array $retryableStatusCodes = null,
        public readonly float $retryBaseDelaySeconds = 0.2,
        RetryBackoff|string $retryBackoff = RetryBackoff::Linear,
        public readonly float $retryJitterSeconds = 0.05,
        public readonly ?float $totalTimeoutSeconds = null,
        public readonly ?float $connectTimeout = null,
        #[\SensitiveParameter] public readonly ?string $proxy = null,
        public readonly ?string $caInfo = null,
        public readonly bool $verifyPeer = true,
        public readonly array $curlOptions = [],
        ?string $additionalInfo = null,
    ) {
        $this->additionalInfo = self::validarAdditionalInfo($additionalInfo);
        $resolved = self::resolveApiKey($apiKey);
        $this->credencial = static fn (): string => $resolved;
        $this->baseAddress = self::resolveBaseAddress($baseAddress);

        if (preg_match('/[\x00-\x1F\x7F]/', $this->userAgent) === 1) {
            throw new EcuafactConfigurationException('userAgent no puede tener caracteres de control.');
        }
        if (!is_finite($this->timeout) || $this->timeout <= 0) {
            throw new EcuafactConfigurationException('timeout debe ser mayor que 0 segundos.');
        }
        if ($this->maxAttempts < 1) {
            throw new EcuafactConfigurationException('maxAttempts debe ser 1 o mayor.');
        }
        self::requireNonNegative('maxRetryDelaySeconds', $this->maxRetryDelaySeconds);
        self::requireNonNegative('retryBaseDelaySeconds', $this->retryBaseDelaySeconds);
        self::requireNonNegative('retryJitterSeconds', $this->retryJitterSeconds);
        if ($this->totalTimeoutSeconds !== null && (!is_finite($this->totalTimeoutSeconds) || $this->totalTimeoutSeconds <= 0)) {
            throw new EcuafactConfigurationException('totalTimeoutSeconds debe ser mayor que 0 segundos o null (sin limite).');
        }
        if ($this->connectTimeout !== null && (!is_finite($this->connectTimeout) || $this->connectTimeout <= 0)) {
            throw new EcuafactConfigurationException('connectTimeout debe ser mayor que 0 segundos o null.');
        }

        $codes = $retryableStatusCodes ?? self::DEFAULT_RETRYABLE_STATUS_CODES;
        foreach ($codes as $code) {
            if (!is_int($code) || $code < 100 || $code > 599) {
                throw new EcuafactConfigurationException('retryableStatusCodes solo admite codigos HTTP enteros entre 100 y 599.');
            }
        }
        $this->retryableStatusCodes = array_values(array_unique($codes));

        if (is_string($retryBackoff)) {
            $parsed = RetryBackoff::tryFrom(strtolower(trim($retryBackoff)));
            if ($parsed === null) {
                throw new EcuafactConfigurationException('retryBackoff debe ser "linear" o "exponential".');
            }
            $retryBackoff = $parsed;
        }
        $this->retryBackoff = $retryBackoff;
    }

    public function __get(string $name): mixed
    {
        if ($name === 'apiKey') {
            return ($this->credencial)();
        }
        trigger_error('Propiedad no definida: ' . self::class . '::$' . $name, E_USER_WARNING);
        return null;
    }

    public function __isset(string $name): bool
    {
        return $name === 'apiKey';
    }

    public function __set(string $name, mixed $value): void
    {
        throw new \Error('No se puede modificar ' . self::class . '::$' . $name . '.');
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        $info = get_object_vars($this);
        unset($info['credencial']);
        $info['apiKey'] = '***';
        if ($this->proxy !== null) {
            $info['proxy'] = '***';
        }
        if ($this->additionalInfo !== null) {
            $info['additionalInfo'] = '***';
        }
        return $info;
    }

    private static function resolveApiKey(#[\SensitiveParameter] string|false|null $apiKey): string
    {
        if ($apiKey === null || $apiKey === false) {
            $env = getenv('ECUAFACT_API_KEY');
            if ($env === false || trim($env) === '') {
                throw new EcuafactConfigurationException(
                    'Falta la credencial (apiKey / cabecera X-Api-Key): pasa apiKey o define la variable de entorno ECUAFACT_API_KEY.'
                );
            }
            $apiKey = $env;
            $origen = 'La variable de entorno ECUAFACT_API_KEY';
        } else {
            $origen = 'La credencial (apiKey)';
        }
        if (trim($apiKey) === '') {
            throw new EcuafactConfigurationException('Falta la credencial (apiKey / cabecera X-Api-Key).');
        }
        if (trim($apiKey) !== $apiKey) {
            throw new EcuafactConfigurationException($origen . ' tiene espacios al inicio o al final.');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $apiKey) === 1) {
            throw new EcuafactConfigurationException($origen . ' tiene caracteres de control.');
        }
        return $apiKey;
    }

    private static function resolveBaseAddress(string|false|null $baseAddress): string
    {
        $origen = 'baseAddress';
        if ($baseAddress === null || $baseAddress === false) {
            $env = getenv('ECUAFACT_BASE_URL');
            if ($env === false || trim($env) === '') {
                throw new EcuafactConfigurationException(
                    'Falta la direccion base del API (baseAddress): pasa baseAddress o define la variable de entorno ECUAFACT_BASE_URL.'
                );
            }
            $baseAddress = $env;
            $origen = 'ECUAFACT_BASE_URL';
        }
        $baseAddress = trim($baseAddress);
        if ($baseAddress === '') {
            throw new EcuafactConfigurationException('Falta la direccion base del API (baseAddress).');
        }
        $parts = parse_url($baseAddress);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        if (!is_array($parts) || ($scheme !== 'http' && $scheme !== 'https') || ($parts['host'] ?? '') === ''
            || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new EcuafactConfigurationException(
                $origen . ' debe ser una URL absoluta http o https, sin consulta ni credenciales (por ejemplo https://staging-api.mynexusapi.com/).'
            );
        }
        if (preg_match('#/v1/?$#i', $baseAddress) === 1) {
            throw new EcuafactConfigurationException(
                $origen . ' no debe terminar en /v1: el SDK agrega /v1 a cada ruta. Usa la raiz del despliegue (por ejemplo https://staging-api.mynexusapi.com/).'
            );
        }
        return $baseAddress;
    }

    /**
     * Valida el valor de X-Additional-Info. Null o vacio devuelve null (no se envia la cabecera).
     *
     * @internal Lo usan EcuafactClientOptions y RequestOptions.
     */
    public static function validarAdditionalInfo(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (strlen($value) > self::ADDITIONAL_INFO_MAX) {
            throw new EcuafactConfigurationException(
                'additionalInfo (cabecera X-Additional-Info) admite como maximo ' . self::ADDITIONAL_INFO_MAX . ' caracteres.'
            );
        }
        if (preg_match('/^[\x20-\x7E]+$/D', $value) !== 1) {
            throw new EcuafactConfigurationException(
                'additionalInfo (cabecera X-Additional-Info) solo admite caracteres ASCII imprimibles, sin tildes, enes ni saltos de linea.'
            );
        }
        if (trim($value) !== $value) {
            throw new EcuafactConfigurationException(
                'additionalInfo (cabecera X-Additional-Info) no puede tener espacios al inicio ni al final.'
            );
        }
        return $value;
    }

    private static function requireNonNegative(string $name, float $value): void
    {
        if (!is_finite($value) || $value < 0) {
            throw new EcuafactConfigurationException($name . ' no puede ser negativo.');
        }
    }
}
