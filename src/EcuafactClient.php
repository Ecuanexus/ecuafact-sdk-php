<?php

declare(strict_types=1);

namespace Ecuafact\Sdk;

use Ecuafact\Sdk\Contracts\ArchivoComprobante;
use Ecuafact\Sdk\Contracts\CatalogoItem;
use Ecuafact\Sdk\Contracts\CorreoEnviado;
use Ecuafact\Sdk\Contracts\CorreoSolicitud;
use Ecuafact\Sdk\Contracts\Admission;
use Ecuafact\Sdk\Contracts\BusquedaContribuyente;
use Ecuafact\Sdk\Contracts\ClaveAccesoDecodificada;
use Ecuafact\Sdk\Contracts\ComprobanteRequest;
use Ecuafact\Sdk\Contracts\Contexto;
use Ecuafact\Sdk\Contracts\ContribuyenteConsulta;
use Ecuafact\Sdk\Contracts\EstablecimientosContribuyente;
use Ecuafact\Sdk\Contracts\Operation;
use Ecuafact\Sdk\Contracts\PaginaComprobantes;
use Ecuafact\Sdk\Contracts\PerfilEmisor;
use Ecuafact\Sdk\Contracts\PerfilEmisorUpdate;
use Ecuafact\Sdk\Contracts\QuotaBucket;
use Ecuafact\Sdk\Contracts\ValidacionIdentificacion;
use Ecuafact\Sdk\Support\Hydrator;
use Ecuafact\Sdk\Support\Serialization;
use Ecuafact\Sdk\Transport\CurlTransport;
use Ecuafact\Sdk\Transport\TransportInterface;
use Ecuafact\Sdk\Transport\TransportResponse;

/** Cliente HTTP del API publico Ecuafact para una credencial de integracion. */
final class EcuafactClient
{
    private const TRANSIENT = [408, 425, 429, 500, 502, 503, 504];

    private readonly TransportInterface $transport;

    public function __construct(private readonly EcuafactClientOptions $options)
    {
        $this->transport = $options->transport ?? new CurlTransport($options->timeout);
    }

    public function options(): EcuafactClientOptions
    {
        return $this->options;
    }

    /** Crea una vista fija para un contribuyente (multi-RUC). */
    public function para(string $identificacion): EcuafactContribuyente
    {
        return new EcuafactContribuyente($this, $identificacion);
    }

    public function emitir(ComprobanteRequest $comprobante, ?string $idempotencyKey = null): EmisionResultado
    {
        return $this->emitirEn($this->requireIdentificacion(), $comprobante, $idempotencyKey);
    }

    public function emitirEn(
        string $identificacion,
        ComprobanteRequest $comprobante,
        ?string $idempotencyKey = null
    ): EmisionResultado {
        $key = $idempotencyKey !== null && $idempotencyKey !== '' ? $idempotencyKey : bin2hex(random_bytes(16));
        $this->validateIdempotencyKey($key);
        $path = 'v1/contribuyentes/' . rawurlencode($identificacion) . '/comprobantes';
        $correlation = null;
        /** @var Admission $admission */
        $admission = $this->send('POST', $path, $comprobante, $key, true, Admission::class, false, $correlation);
        return new EmisionResultado($admission, $key, $correlation);
    }

    public function getOperacion(string $idOperacion): Operation
    {
        return $this->send('GET', 'v1/operaciones/' . $idOperacion, null, null, false, Operation::class);
    }

    /** Igual que getOperacion. El API no expone un POST de consulta. */
    public function consultarOperacion(string $idOperacion): Operation
    {
        return $this->getOperacion($idOperacion);
    }

    public function getContexto(): Contexto
    {
        return $this->send('GET', 'v1/contexto', null, null, false, Contexto::class);
    }

    public function enviarCorreo(string $identificacion, string $claveAcceso, string $destinatario): CorreoEnviado
    {
        $cuerpo = new CorreoSolicitud();
        $cuerpo->destinatario = $destinatario;
        $path = 'v1/contribuyentes/' . rawurlencode($identificacion) . '/comprobantes/' . rawurlencode($claveAcceso) . '/correo';
        return $this->send('POST', $path, $cuerpo, null, false, CorreoEnviado::class);
    }

    /** @return CatalogoItem[] */
    public function listarCatalogo(string $nombre): array
    {
        return $this->send('GET', 'v1/catalogos/' . rawurlencode($nombre), null, null, false, CatalogoItem::class, true);
    }

    public function descargarRide(string $identificacion, string $claveAcceso): ArchivoComprobante
    {
        return $this->descargarArchivo($identificacion, $claveAcceso, 'ride');
    }

    public function descargarXml(string $identificacion, string $claveAcceso): ArchivoComprobante
    {
        return $this->descargarArchivo($identificacion, $claveAcceso, 'xml');
    }

    public function getPerfil(string $identificacion): PerfilEmisor
    {
        return $this->send('GET', $this->rutaPerfil($identificacion), null, null, false, PerfilEmisor::class);
    }

    public function actualizarPerfil(string $identificacion, PerfilEmisorUpdate $cambios): PerfilEmisor
    {
        return $this->send('PUT', $this->rutaPerfil($identificacion), $cambios, null, false, PerfilEmisor::class);
    }

    public function getConsumo(): QuotaBucket
    {
        return $this->send('GET', 'v1/consumo', null, null, true, QuotaBucket::class);
    }

    public function listarEmitidos(?ListadoRequest $filtros = null): PaginaComprobantes
    {
        return $this->listar($this->requireIdentificacion(), false, $filtros);
    }

    public function listarRecibidos(?ListadoRequest $filtros = null): PaginaComprobantes
    {
        return $this->listar($this->requireIdentificacion(), true, $filtros);
    }

    public function listarEmitidosEn(string $identificacion, ?ListadoRequest $filtros = null): PaginaComprobantes
    {
        return $this->listar($identificacion, false, $filtros);
    }

    public function listarRecibidosEn(string $identificacion, ?ListadoRequest $filtros = null): PaginaComprobantes
    {
        return $this->listar($identificacion, true, $filtros);
    }

    public function consultarContribuyente(string $identificacion, bool $fusionar = true, ?string $fuente = null): ContribuyenteConsulta
    {
        if (trim($identificacion) === '') {
            throw new EcuafactSdkException('La identificacion es obligatoria.');
        }
        $path = 'v1/consultas/contribuyentes/' . rawurlencode($identificacion)
            . '?fusionar=' . ($fusionar ? 'true' : 'false');
        if ($fuente !== null && trim($fuente) !== '') {
            $path .= '&fuente=' . rawurlencode($fuente);
        }
        /** @var ContribuyenteConsulta $resultado */
        $resultado = $this->send('GET', $path, null, null, true, ContribuyenteConsulta::class);
        return $resultado;
    }

    /** @return BusquedaContribuyente[] */
    public function buscarContribuyentes(
        string $apellidos,
        ?string $nombres = null,
        ?string $clase = null,
        ?int $max = null
    ): array {
        if (trim($apellidos) === '') {
            throw new EcuafactSdkException('apellidos es obligatorio.');
        }
        $parts = ['apellidos=' . rawurlencode($apellidos)];
        if ($nombres !== null && trim($nombres) !== '') {
            $parts[] = 'nombres=' . rawurlencode($nombres);
        }
        if ($clase !== null && trim($clase) !== '') {
            $parts[] = 'clase=' . rawurlencode($clase);
        }
        if ($max !== null) {
            $parts[] = 'max=' . $max;
        }
        $path = 'v1/consultas/contribuyentes?' . implode('&', $parts);
        /** @var BusquedaContribuyente[] $resultado */
        $resultado = $this->send('GET', $path, null, null, true, BusquedaContribuyente::class, true);
        return $resultado;
    }

    public function listarEstablecimientos(string $ruc, ?string $filtro = null): EstablecimientosContribuyente
    {
        if (trim($ruc) === '') {
            throw new EcuafactSdkException('El RUC es obligatorio.');
        }
        $path = 'v1/consultas/establecimientos/' . rawurlencode($ruc);
        if ($filtro !== null && trim($filtro) !== '') {
            $path .= '?filtro=' . rawurlencode($filtro);
        }
        /** @var EstablecimientosContribuyente $resultado */
        $resultado = $this->send('GET', $path, null, null, true, EstablecimientosContribuyente::class);
        return $resultado;
    }

    public function validarIdentificacion(string $numero): ValidacionIdentificacion
    {
        if (trim($numero) === '') {
            throw new EcuafactSdkException('El numero a validar es obligatorio.');
        }
        $path = 'v1/consultas/identificacion/validar/' . rawurlencode($numero);
        /** @var ValidacionIdentificacion $resultado */
        $resultado = $this->send('GET', $path, null, null, true, ValidacionIdentificacion::class);
        return $resultado;
    }

    public function decodificarClaveAcceso(string $claveAcceso): ClaveAccesoDecodificada
    {
        if (trim($claveAcceso) === '') {
            throw new EcuafactSdkException('La clave de acceso es obligatoria.');
        }
        $path = 'v1/consultas/comprobantes/decodificar/' . rawurlencode($claveAcceso);
        /** @var ClaveAccesoDecodificada $resultado */
        $resultado = $this->send('GET', $path, null, null, true, ClaveAccesoDecodificada::class);
        return $resultado;
    }

    private function rutaPerfil(string $identificacion): string
    {
        return 'v1/contribuyentes/' . rawurlencode($identificacion) . '/perfil';
    }

    private function descargarArchivo(string $identificacion, string $claveAcceso, string $formato): ArchivoComprobante
    {
        $path = 'v1/contribuyentes/' . rawurlencode($identificacion)
            . '/comprobantes/' . rawurlencode($claveAcceso) . '/' . $formato;
        $url = $this->buildUrl($path);
        $headers = [
            'X-Api-Key: ' . $this->options->apiKey,
            'User-Agent: ' . $this->options->userAgent,
        ];
        $response = $this->requestWithRetry('GET', $url, $headers, null, true);
        if ($response->status >= 400) {
            throw $this->buildApiException($response->status, $response->body, $response->header('X-Correlation-Id'), null);
        }
        $archivo = new ArchivoComprobante();
        $archivo->contenido = $response->body;
        $tipo = $response->header('Content-Type');
        $archivo->contentType = $tipo !== null && $tipo !== '' ? explode(';', $tipo)[0] : 'application/octet-stream';
        $marca = $response->header('X-Contenido');
        $archivo->provisional = $marca !== null && strcasecmp($marca, 'provisional') === 0;
        return $archivo;
    }

    private function listar(string $identificacion, bool $recibidos, ?ListadoRequest $filtros): PaginaComprobantes
    {
        $path = 'v1/contribuyentes/' . rawurlencode($identificacion)
            . '/comprobantes/' . ($recibidos ? 'recibidos' : 'emitidos') . $this->buildQuery($filtros);
        return $this->send('GET', $path, null, null, false, PaginaComprobantes::class);
    }

    private function send(
        string $method,
        string $path,
        ?object $body,
        ?string $idempotencyKey,
        bool $flat,
        string $modelClass,
        bool $list = false,
        ?string &$correlationId = null
    ): mixed {
        $url = $this->buildUrl($path);
        $json = $body !== null ? Serialization::encode($body) : null;
        $headers = [
            'X-Api-Key: ' . $this->options->apiKey,
            'Accept: application/json',
            'User-Agent: ' . $this->options->userAgent,
        ];
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json; charset=utf-8';
        }
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        // Solo se reintentan solicitudes idempotentes: GET, o POST/PUT con Idempotency-Key.
        $retryable = $method === 'GET' || ($idempotencyKey !== null && ($method === 'POST' || $method === 'PUT'));
        $response = $this->requestWithRetry($method, $url, $headers, $json, $retryable);
        $correlation = $response->header('X-Correlation-Id');
        if ($response->status >= 400) {
            throw $this->buildApiException($response->status, $response->body, $correlation, $idempotencyKey);
        }
        $correlationId = $correlation;
        return $this->parse($response->body, $flat, $modelClass, $list);
    }

    /** @param string[] $headers */
    private function requestWithRetry(string $method, string $url, array $headers, ?string $json, bool $retryable): TransportResponse
    {
        $maxAttempts = $this->options->retryTransientFailures && $retryable ? max(1, $this->options->maxAttempts) : 1;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = $this->transport->request($method, $url, $headers, $json);
            } catch (EcuafactSdkException $exception) {
                if ($attempt < $maxAttempts) {
                    $this->delay($this->computeDelay($attempt, null));
                    continue;
                }
                throw $exception;
            }

            if ($response->status >= 400
                && in_array($response->status, self::TRANSIENT, true)
                && $attempt < $maxAttempts) {
                $this->delay($this->computeDelay($attempt, $response));
                continue;
            }
            return $response;
        }

        throw new EcuafactSdkException('No fue posible completar la solicitud con el API.');
    }

    private function parse(string $text, bool $flat, string $modelClass, bool $list = false): mixed
    {
        if ($text === '') {
            throw new EcuafactSdkException('El API no devolvio datos.');
        }
        $data = json_decode($text);
        if ($data === null && trim($text) !== 'null') {
            throw new EcuafactSdkException('Respuesta del API no reconocida.');
        }
        if (!$flat) {
            $data = is_object($data) && property_exists($data, 'datos') ? $data->datos : null;
            if ($data === null) {
                throw new EcuafactSdkException('El API no devolvio datos.');
            }
        }
        if ($list) {
            $items = is_array($data) ? $data : [];
            $resultado = [];
            foreach ($items as $item) {
                $resultado[] = Hydrator::hydrate($modelClass, $item);
            }
            return $resultado;
        }
        return Hydrator::hydrate($modelClass, $data);
    }

    private function buildApiException(int $status, string $text, ?string $correlation, ?string $idempotencyKey): EcuafactApiException
    {
        $error = null;
        if (trim($text) !== '') {
            $decoded = json_decode($text, true);
            if (is_array($decoded)) {
                $error = $decoded;
            }
        }
        return new EcuafactApiException($error, $status, $correlation, $idempotencyKey);
    }

    private function requireIdentificacion(): string
    {
        $value = $this->options->identificacion;
        if ($value === null || trim($value) === '') {
            throw new EcuafactSdkException(
                'Falta el RUC por defecto (identificacion). Configurelo para integraciones de un solo RUC o use '
                . 'emitirEn(ruc, ...) o client->para(ruc).'
            );
        }
        return $value;
    }

    private function buildUrl(string $path): string
    {
        $base = str_ends_with($this->options->baseAddress, '/') ? $this->options->baseAddress : $this->options->baseAddress . '/';
        return $base . $path;
    }

    private function buildQuery(?ListadoRequest $filtros): string
    {
        if ($filtros === null) {
            return '';
        }
        $parts = [];
        $this->append($parts, 'desde', $filtros->desde);
        $this->append($parts, 'hasta', $filtros->hasta);
        $this->append($parts, 'codDoc', $filtros->codDoc);
        $this->append($parts, 'buscar', $filtros->buscar);
        $this->append($parts, 'pagina', $filtros->pagina === null ? null : (string) $filtros->pagina);
        $this->append($parts, 'tamanoPagina', $filtros->tamanoPagina === null ? null : (string) $filtros->tamanoPagina);
        return $parts === [] ? '' : '?' . implode('&', $parts);
    }

    /** @param string[] $parts */
    private function append(array &$parts, string $name, ?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $parts[] = $name . '=' . rawurlencode($value);
    }

    private function validateIdempotencyKey(string $key): void
    {
        if (strlen($key) > 128) {
            throw new EcuafactSdkException('Idempotency-Key no puede exceder 128 caracteres.');
        }
        for ($index = 0, $length = strlen($key); $index < $length; $index++) {
            if (ord($key[$index]) > 127) {
                throw new EcuafactSdkException('Idempotency-Key debe ser ASCII.');
            }
        }
    }

    private function computeDelay(int $attempt, ?TransportResponse $response): int
    {
        $millis = (200 * $attempt) + random_int(0, 50 * $attempt);
        if ($response !== null && $this->options->respectRetryAfter) {
            $retryAfter = $this->readRetryAfter($response);
            if ($retryAfter !== null && $retryAfter > $millis) {
                $millis = $retryAfter;
            }
        }
        $cap = (int) round($this->options->maxRetryDelaySeconds * 1000);
        if ($cap > 0 && $millis > $cap) {
            $millis = $cap;
        }
        return $millis;
    }

    private function readRetryAfter(TransportResponse $response): ?int
    {
        $value = $response->header('Retry-After');
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (ctype_digit($value)) {
            return ((int) $value) * 1000;
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }
        $delta = ($timestamp - time()) * 1000;
        return $delta > 0 ? $delta : null;
    }

    private function delay(int $millis): void
    {
        if ($millis > 0) {
            usleep($millis * 1000);
        }
    }
}
