<?php

declare(strict_types=1);

namespace Ecuafact\Sdk;

use Ecuafact\Sdk\Contracts\ArchivoComprobante;
use Ecuafact\Sdk\Contracts\CatalogoItem;
use Ecuafact\Sdk\Contracts\Comprobante;
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
use Ecuafact\Sdk\Transport\TimeoutAwareTransportInterface;
use Ecuafact\Sdk\Transport\TimeoutOverrideTransportInterface;
use Ecuafact\Sdk\Transport\TransportInterface;
use Ecuafact\Sdk\Transport\TransportResponse;

/**
 * Cliente HTTP del API publico Ecuafact para una credencial de integracion.
 *
 * En todos los metodos, `$identificacion` es el valor `identificacion` que devuelve
 * `GET /v1/contexto` para el contribuyente (por ejemplo `0123456789`).
 *
 * Errores: {@see EcuafactApiException} y sus subclases cuando el API responde con error;
 * {@see EcuafactConnectionException} o {@see EcuafactTimeoutException} cuando no hay respuesta;
 * {@see EcuafactSdkException} para argumentos invalidos o respuestas no reconocidas.
 *
 * Cada metodo que llama al API acepta como ultimo argumento `?RequestOptions $opciones` (timeout,
 * tope total, X-Correlation-Id y reintentos solo para esa llamada).
 */
final class EcuafactClient
{
    /** Ultima pagina que recorre iterarEmitidos. */
    public const MAX_PAGINA = 10000;

    private const IDEMPOTENCY_KEY_PATTERN = '/^[A-Za-z0-9\-_.:\/]{1,128}$/D';
    private const IDEMPOTENCY_KEY_MESSAGE = 'Idempotency-Key: solo letras, digitos y - _ . : /, maximo 128 caracteres.';
    private const UUID_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D';

    private readonly TransportInterface $transport;

    public function __construct(private readonly EcuafactClientOptions $options)
    {
        $this->transport = $options->transport ?? new CurlTransport(
            $options->timeout,
            $options->connectTimeout,
            $options->proxy,
            $options->caInfo,
            $options->verifyPeer,
            $options->curlOptions,
        );
    }

    public function options(): EcuafactClientOptions
    {
        return $this->options;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['options' => $this->options, 'transport' => $this->transport::class];
    }

    /** Crea una vista fija para un contribuyente (multi-RUC). */
    public function para(string $identificacion): EcuafactContribuyente
    {
        return new EcuafactContribuyente($this, $identificacion);
    }

    public function emitir(
        ComprobanteRequest $comprobante,
        ?string $idempotencyKey = null,
        ?RequestOptions $opciones = null
    ): EmisionResultado {
        return $this->emitirEn($this->requireIdentificacion(), $comprobante, $idempotencyKey, $opciones);
    }

    /**
     * Emite un comprobante (202). Si no pasas `$idempotencyKey`, el SDK la genera y la reutiliza en
     * los reintentos; la devuelve en `EmisionResultado::$idempotencyKey`.
     *
     * @throws EcuafactApiException|EcuafactSdkException
     */
    public function emitirEn(
        string $identificacion,
        ComprobanteRequest $comprobante,
        ?string $idempotencyKey = null,
        ?RequestOptions $opciones = null
    ): EmisionResultado {
        $key = $idempotencyKey !== null && $idempotencyKey !== '' ? $idempotencyKey : bin2hex(random_bytes(16));
        $this->validateIdempotencyKey($key);
        $path = 'v1/contribuyentes/' . rawurlencode($identificacion) . '/comprobantes';
        $correlation = null;
        /** @var Admission $admission */
        $admission = $this->send('POST', $path, $comprobante, $key, true, Admission::class, false, $correlation, $opciones);
        return new EmisionResultado($admission, $key, $correlation);
    }

    /**
     * Lee la operacion. `$idOperacion` debe ser un UUID (el `idOperacion` de la admision).
     *
     * @throws EcuafactApiException|EcuafactSdkException
     */
    public function getOperacion(string $idOperacion, ?RequestOptions $opciones = null): Operation
    {
        $this->validateUuid($idOperacion);
        $correlation = null;
        return $this->send('GET', 'v1/operaciones/' . rawurlencode($idOperacion), null, null, false, Operation::class, false, $correlation, $opciones);
    }

    /** Igual que getOperacion. El API no expone un POST de consulta. */
    public function consultarOperacion(string $idOperacion, ?RequestOptions $opciones = null): Operation
    {
        return $this->getOperacion($idOperacion, $opciones);
    }

    /**
     * Consulta la operacion cada `$intervalo` segundos hasta que sea final (`Operation::esFinal()`)
     * o hasta agotar `$maximo` segundos. Devuelve la ultima operacion leida: revisa `esFinal()`.
     * `$opciones` se aplica a cada consulta.
     *
     * @throws EcuafactApiException|EcuafactSdkException
     */
    public function esperarResultado(
        string $idOperacion,
        float $intervalo = 5.0,
        float $maximo = 120.0,
        ?RequestOptions $opciones = null
    ): Operation {
        $this->validateUuid($idOperacion);
        if (!is_finite($intervalo) || $intervalo < 0) {
            throw new EcuafactSdkException('El intervalo no puede ser negativo.');
        }
        if (!is_finite($maximo) || $maximo < 0) {
            throw new EcuafactSdkException('El maximo no puede ser negativo.');
        }
        $inicio = self::now();
        while (true) {
            $operacion = $this->getOperacion($idOperacion, $opciones);
            if ($operacion->esFinal()) {
                return $operacion;
            }
            $restante = $maximo - (self::now() - $inicio);
            if ($restante <= 0) {
                return $operacion;
            }
            $this->delay((int) round(min($intervalo, $restante) * 1000));
        }
    }

    public function getContexto(?RequestOptions $opciones = null): Contexto
    {
        $correlation = null;
        return $this->send('GET', 'v1/contexto', null, null, false, Contexto::class, false, $correlation, $opciones);
    }

    public function enviarCorreo(
        string $identificacion,
        string $claveAcceso,
        string $destinatario,
        ?RequestOptions $opciones = null
    ): CorreoEnviado {
        $cuerpo = new CorreoSolicitud();
        $cuerpo->destinatario = $destinatario;
        $path = 'v1/contribuyentes/' . rawurlencode($identificacion) . '/comprobantes/' . rawurlencode($claveAcceso) . '/correo';
        $correlation = null;
        return $this->send('POST', $path, $cuerpo, null, false, CorreoEnviado::class, false, $correlation, $opciones);
    }

    /** @return CatalogoItem[] */
    public function listarCatalogo(string $nombre, ?RequestOptions $opciones = null): array
    {
        $correlation = null;
        return $this->send('GET', 'v1/catalogos/' . rawurlencode($nombre), null, null, false, CatalogoItem::class, true, $correlation, $opciones);
    }

    public function descargarRide(string $identificacion, string $claveAcceso, ?RequestOptions $opciones = null): ArchivoComprobante
    {
        return $this->descargarArchivo($identificacion, $claveAcceso, 'ride', $opciones);
    }

    public function descargarXml(string $identificacion, string $claveAcceso, ?RequestOptions $opciones = null): ArchivoComprobante
    {
        return $this->descargarArchivo($identificacion, $claveAcceso, 'xml', $opciones);
    }

    public function getPerfil(string $identificacion, ?RequestOptions $opciones = null): PerfilEmisor
    {
        $correlation = null;
        return $this->send('GET', $this->rutaPerfil($identificacion), null, null, false, PerfilEmisor::class, false, $correlation, $opciones);
    }

    public function actualizarPerfil(
        string $identificacion,
        PerfilEmisorUpdate $cambios,
        ?RequestOptions $opciones = null
    ): PerfilEmisor {
        $correlation = null;
        return $this->send('PUT', $this->rutaPerfil($identificacion), $cambios, null, false, PerfilEmisor::class, false, $correlation, $opciones);
    }

    /**
     * Pone o cambia el logo del RIDE (`POST .../perfil/logo`, multipart, campo `logo`).
     * PNG o JPEG de hasta 512 KB; el API valida el formato y el tamano. No se reintenta.
     *
     * @param string $contenido Bytes del archivo (por ejemplo `file_get_contents('logo.png')`).
     * @param string $contentType `image/png` o `image/jpeg`.
     * @throws EcuafactApiException|EcuafactSdkException
     */
    public function actualizarLogo(
        string $identificacion,
        string $contenido,
        string $contentType,
        string $nombreArchivo = 'logo',
        ?RequestOptions $opciones = null
    ): PerfilEmisor {
        if ($contenido === '') {
            throw new EcuafactSdkException('El contenido del logo esta vacio.');
        }
        $contentType = trim($contentType);
        if ($contentType === '' || preg_match('/[\x00-\x1F\x7F";]/', $contentType) === 1) {
            throw new EcuafactSdkException('contentType no es valido (usa image/png o image/jpeg).');
        }
        $nombre = trim(preg_replace('/[\x00-\x1F\x7F"\\\\\/]/', '_', $nombreArchivo) ?? '');
        if ($nombre === '') {
            $nombre = 'logo';
        }
        $boundary = '----EcuafactSdk' . bin2hex(random_bytes(12));
        $cuerpo = '--' . $boundary . "\r\n"
            . 'Content-Disposition: form-data; name="logo"; filename="' . $nombre . "\"\r\n"
            . 'Content-Type: ' . $contentType . "\r\n\r\n"
            . $contenido . "\r\n"
            . '--' . $boundary . "--\r\n";
        $correlation = null;
        /** @var PerfilEmisor $perfil */
        $perfil = $this->sendRaw(
            'POST',
            $this->rutaPerfil($identificacion) . '/logo',
            $cuerpo,
            'multipart/form-data; boundary=' . $boundary,
            null,
            false,
            PerfilEmisor::class,
            false,
            $correlation,
            $opciones
        );
        return $perfil;
    }

    public function getConsumo(?RequestOptions $opciones = null): QuotaBucket
    {
        $correlation = null;
        return $this->send('GET', 'v1/consumo', null, null, true, QuotaBucket::class, false, $correlation, $opciones);
    }

    public function listarEmitidos(?ListadoRequest $filtros = null, ?RequestOptions $opciones = null): PaginaComprobantes
    {
        return $this->listar($this->requireIdentificacion(), $filtros, $opciones);
    }

    public function listarEmitidosEn(
        string $identificacion,
        ?ListadoRequest $filtros = null,
        ?RequestOptions $opciones = null
    ): PaginaComprobantes {
        return $this->listar($identificacion, $filtros, $opciones);
    }

    /**
     * Recorre todas las paginas de emitidos del RUC por defecto siguiendo `hayMas` (perezoso).
     *
     * @return \Generator<int, Comprobante>
     */
    public function iterarEmitidos(?ListadoRequest $filtros = null, ?RequestOptions $opciones = null): \Generator
    {
        return $this->iterar($this->requireIdentificacion(), $filtros, $opciones);
    }

    /**
     * Recorre todas las paginas de emitidos siguiendo `hayMas`, desde `filtros->pagina` (o 1) hasta
     * la pagina 10000. Pide cada pagina solo cuando la necesitas. `$opciones` se aplica a cada pagina.
     *
     * @return \Generator<int, Comprobante>
     */
    public function iterarEmitidosEn(
        string $identificacion,
        ?ListadoRequest $filtros = null,
        ?RequestOptions $opciones = null
    ): \Generator {
        return $this->iterar($identificacion, $filtros, $opciones);
    }

    public function consultarContribuyente(
        string $identificacion,
        bool $fusionar = true,
        ?string $fuente = null,
        ?RequestOptions $opciones = null
    ): ContribuyenteConsulta {
        if (trim($identificacion) === '') {
            throw new EcuafactSdkException('La identificacion es obligatoria.');
        }
        $path = 'v1/consultas/contribuyentes/' . rawurlencode($identificacion)
            . '?fusionar=' . ($fusionar ? 'true' : 'false');
        if ($fuente !== null && trim($fuente) !== '') {
            $path .= '&fuente=' . rawurlencode($fuente);
        }
        /** @var ContribuyenteConsulta $resultado */
        $correlation = null;
        $resultado = $this->send('GET', $path, null, null, true, ContribuyenteConsulta::class, false, $correlation, $opciones);
        return $resultado;
    }

    /** @return BusquedaContribuyente[] */
    public function buscarContribuyentes(
        string $apellidos,
        ?string $nombres = null,
        ?string $clase = null,
        ?int $max = null,
        ?RequestOptions $opciones = null
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
        $correlation = null;
        $resultado = $this->send('GET', $path, null, null, true, BusquedaContribuyente::class, true, $correlation, $opciones);
        return $resultado;
    }

    public function listarEstablecimientos(
        string $ruc,
        ?string $filtro = null,
        ?RequestOptions $opciones = null
    ): EstablecimientosContribuyente {
        if (trim($ruc) === '') {
            throw new EcuafactSdkException('El RUC es obligatorio.');
        }
        $path = 'v1/consultas/establecimientos/' . rawurlencode($ruc);
        if ($filtro !== null && trim($filtro) !== '') {
            $path .= '?filtro=' . rawurlencode($filtro);
        }
        /** @var EstablecimientosContribuyente $resultado */
        $correlation = null;
        $resultado = $this->send('GET', $path, null, null, true, EstablecimientosContribuyente::class, false, $correlation, $opciones);
        return $resultado;
    }

    public function validarIdentificacion(string $numero, ?RequestOptions $opciones = null): ValidacionIdentificacion
    {
        if (trim($numero) === '') {
            throw new EcuafactSdkException('El numero a validar es obligatorio.');
        }
        $path = 'v1/consultas/identificacion/validar/' . rawurlencode($numero);
        /** @var ValidacionIdentificacion $resultado */
        $correlation = null;
        $resultado = $this->send('GET', $path, null, null, true, ValidacionIdentificacion::class, false, $correlation, $opciones);
        return $resultado;
    }

    public function decodificarClaveAcceso(string $claveAcceso, ?RequestOptions $opciones = null): ClaveAccesoDecodificada
    {
        if (trim($claveAcceso) === '') {
            throw new EcuafactSdkException('La clave de acceso es obligatoria.');
        }
        $path = 'v1/consultas/comprobantes/decodificar/' . rawurlencode($claveAcceso);
        /** @var ClaveAccesoDecodificada $resultado */
        $correlation = null;
        $resultado = $this->send('GET', $path, null, null, true, ClaveAccesoDecodificada::class, false, $correlation, $opciones);
        return $resultado;
    }

    private function rutaPerfil(string $identificacion): string
    {
        return 'v1/contribuyentes/' . rawurlencode($identificacion) . '/perfil';
    }

    /** @return \Generator<int, Comprobante> */
    private function iterar(string $identificacion, ?ListadoRequest $filtros, ?RequestOptions $opciones): \Generator
    {
        $actual = $filtros !== null ? clone $filtros : new ListadoRequest();
        $pagina = $actual->pagina ?? 1;
        if ($pagina < 1 || $pagina > self::MAX_PAGINA) {
            throw new EcuafactSdkException('La pagina inicial debe estar entre 1 y ' . self::MAX_PAGINA . '.');
        }
        return (function () use ($identificacion, $actual, $pagina, $opciones): \Generator {
            while (true) {
                $actual->pagina = $pagina;
                $resultado = $this->listar($identificacion, $actual, $opciones);
                $comprobantes = $resultado->comprobantes ?? [];
                foreach ($comprobantes as $comprobante) {
                    yield $comprobante;
                }
                if ($resultado->hayMas !== true || $comprobantes === [] || $pagina >= self::MAX_PAGINA) {
                    return;
                }
                $pagina++;
            }
        })();
    }

    private function descargarArchivo(
        string $identificacion,
        string $claveAcceso,
        string $formato,
        ?RequestOptions $opciones
    ): ArchivoComprobante {
        $path = 'v1/contribuyentes/' . rawurlencode($identificacion)
            . '/comprobantes/' . rawurlencode($claveAcceso) . '/' . $formato;
        $url = $this->buildUrl($path);
        $headers = [
            'X-Api-Key: ' . $this->options->apiKey,
            'User-Agent: ' . $this->options->userAgent,
        ];
        if ($opciones?->correlationId !== null) {
            $headers[] = 'X-Correlation-Id: ' . $opciones->correlationId;
        }
        $response = $this->requestWithRetry('GET', $url, $headers, null, true, null, $opciones);
        if ($response->status >= 400) {
            throw $this->buildApiException($response, null, $opciones);
        }
        $archivo = new ArchivoComprobante();
        $archivo->contenido = $response->body;
        $tipo = $response->header('Content-Type');
        $archivo->contentType = $tipo !== null && $tipo !== '' ? explode(';', $tipo)[0] : 'application/octet-stream';
        $marca = $response->header('X-Contenido');
        $archivo->provisional = $marca !== null && strcasecmp($marca, 'provisional') === 0;
        return $archivo;
    }

    private function listar(string $identificacion, ?ListadoRequest $filtros, ?RequestOptions $opciones): PaginaComprobantes
    {
        $path = 'v1/contribuyentes/' . rawurlencode($identificacion)
            . '/comprobantes/emitidos' . $this->buildQuery($filtros);
        $correlation = null;
        return $this->send('GET', $path, null, null, false, PaginaComprobantes::class, false, $correlation, $opciones);
    }

    private function send(
        string $method,
        string $path,
        ?object $body,
        ?string $idempotencyKey,
        bool $flat,
        string $modelClass,
        bool $list = false,
        ?string &$correlationId = null,
        ?RequestOptions $opciones = null
    ): mixed {
        $json = $body !== null ? Serialization::encode($body) : null;
        return $this->sendRaw(
            $method,
            $path,
            $json,
            $json !== null ? 'application/json; charset=utf-8' : null,
            $idempotencyKey,
            $flat,
            $modelClass,
            $list,
            $correlationId,
            $opciones
        );
    }

    private function sendRaw(
        string $method,
        string $path,
        ?string $payload,
        ?string $contentType,
        ?string $idempotencyKey,
        bool $flat,
        string $modelClass,
        bool $list,
        ?string &$correlationId,
        ?RequestOptions $opciones = null
    ): mixed {
        $url = $this->buildUrl($path);
        $headers = [
            'X-Api-Key: ' . $this->options->apiKey,
            'Accept: application/json',
            'User-Agent: ' . $this->options->userAgent,
        ];
        if ($contentType !== null) {
            $headers[] = 'Content-Type: ' . $contentType;
        }
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }
        if ($opciones?->correlationId !== null) {
            $headers[] = 'X-Correlation-Id: ' . $opciones->correlationId;
        }

        // Solo se reintentan solicitudes idempotentes: GET, o POST/PUT con Idempotency-Key.
        $retryable = $method === 'GET' || ($idempotencyKey !== null && ($method === 'POST' || $method === 'PUT'));
        $response = $this->requestWithRetry($method, $url, $headers, $payload, $retryable, $idempotencyKey, $opciones);
        if ($response->status >= 400) {
            throw $this->buildApiException($response, $idempotencyKey, $opciones);
        }
        $correlationId = $response->header('X-Correlation-Id') ?? $opciones?->correlationId;
        return $this->parse($response->body, $flat, $modelClass, $list);
    }

    /** @param string[] $headers */
    private function requestWithRetry(
        string $method,
        string $url,
        #[\SensitiveParameter] array $headers,
        ?string $payload,
        bool $retryable,
        ?string $idempotencyKey,
        ?RequestOptions $opciones = null
    ): TransportResponse {
        $options = $this->options;
        $totalTimeout = $opciones?->totalTimeout ?? $options->totalTimeoutSeconds;
        $deadline = $totalTimeout !== null ? self::now() + $totalTimeout : null;
        $reintentar = $options->retryTransientFailures && $retryable && ($opciones === null || $opciones->reintentos);
        $maxAttempts = $reintentar ? $options->maxAttempts : 1;
        $correlacion = $opciones?->correlationId;
        for ($attempt = 1; ; $attempt++) {
            $remaining = $deadline !== null ? $deadline - self::now() : null;
            if ($remaining !== null && $remaining <= 0) {
                throw new EcuafactTimeoutException(
                    'Se agoto el tiempo total de la llamada (totalTimeoutSeconds).',
                    null,
                    $idempotencyKey,
                    null,
                    $correlacion
                );
            }
            $failure = null;
            try {
                $response = $this->transportRequest($method, $url, $headers, $payload, $remaining, $opciones?->timeout);
            } catch (EcuafactConfigurationException | EcuafactApiException $exception) {
                throw $exception;
            } catch (EcuafactConnectionException | EcuafactTimeoutException $exception) {
                $failure = $exception->withIdempotencyKey($idempotencyKey, $correlacion);
            } catch (EcuafactSdkException $exception) {
                $failure = new EcuafactConnectionException($exception->getMessage(), null, $idempotencyKey, $exception, $correlacion);
            } catch (\Exception $exception) {
                $failure = new EcuafactConnectionException('No hay conexion con el API.', null, $idempotencyKey, $exception, $correlacion);
            }

            if ($failure !== null) {
                if ($attempt < $maxAttempts) {
                    $delay = $this->computeDelay($attempt, null);
                    if ($this->fitsDeadline($deadline, $delay)) {
                        $this->delay($delay);
                        continue;
                    }
                }
                throw $failure;
            }

            if ($response->status >= 400 && $attempt < $maxAttempts && $this->shouldRetry($response)) {
                $delay = $this->computeDelay($attempt, $response);
                if ($this->fitsDeadline($deadline, $delay)) {
                    $this->delay($delay);
                    continue;
                }
            }
            return $response;
        }
    }

    /** @param string[] $headers */
    private function transportRequest(
        string $method,
        string $url,
        #[\SensitiveParameter] array $headers,
        ?string $payload,
        ?float $remaining,
        ?float $attemptTimeout = null
    ): TransportResponse {
        if ($attemptTimeout !== null && $this->transport instanceof TimeoutOverrideTransportInterface) {
            $timeout = $remaining !== null ? min($attemptTimeout, $remaining) : $attemptTimeout;
            return $this->transport->requestWithAttemptTimeout($method, $url, $headers, $payload, $timeout);
        }
        if ($remaining !== null && $this->transport instanceof TimeoutAwareTransportInterface) {
            return $this->transport->requestWithTimeout($method, $url, $headers, $payload, $remaining);
        }
        return $this->transport->request($method, $url, $headers, $payload);
    }

    private function fitsDeadline(?float $deadline, int $delayMillis): bool
    {
        return $deadline === null || self::now() + ($delayMillis / 1000) < $deadline;
    }

    private function shouldRetry(TransportResponse $response): bool
    {
        if ($response->status === 429) {
            // 429/501 (cupo agotado) nunca se reintenta; 429/104 solo con retryRateLimited.
            if ($this->errorCode($response->body) === '501') {
                return false;
            }
            return $this->options->retryRateLimited;
        }
        return in_array($response->status, $this->options->retryableStatusCodes, true);
    }

    private function errorCode(string $body): ?string
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['codigo']) || !is_scalar($decoded['codigo'])) {
            return null;
        }
        return (string) $decoded['codigo'];
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
                $resultado[] = $this->hydrateModel($modelClass, $item);
            }
            return $resultado;
        }
        return $this->hydrateModel($modelClass, $data);
    }

    private function hydrateModel(string $modelClass, mixed $data): object
    {
        $model = Hydrator::hydrate($modelClass, $data);
        if (!$model instanceof $modelClass) {
            throw new EcuafactSdkException('Respuesta del API no reconocida.');
        }
        return $model;
    }

    private function buildApiException(
        TransportResponse $response,
        ?string $idempotencyKey,
        ?RequestOptions $opciones = null
    ): EcuafactApiException {
        $error = null;
        if (trim($response->body) !== '') {
            $decoded = json_decode($response->body, true);
            if (is_array($decoded)) {
                $error = $decoded;
            }
        }
        $retryAfter = $this->readRetryAfter($response);
        return EcuafactApiException::crear(
            $error,
            $response->status,
            $response->header('X-Correlation-Id') ?? $opciones?->correlationId,
            $idempotencyKey,
            $retryAfter === null ? null : (int) ceil($retryAfter / 1000)
        );
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
        if (preg_match(self::IDEMPOTENCY_KEY_PATTERN, $key) !== 1) {
            throw new EcuafactSdkException(self::IDEMPOTENCY_KEY_MESSAGE);
        }
    }

    private function validateUuid(string $idOperacion): void
    {
        if (preg_match(self::UUID_PATTERN, $idOperacion) !== 1) {
            throw new EcuafactSdkException(
                'idOperacion debe ser un UUID (por ejemplo 3f2b8c1e-7a4d-4e2b-9c11-5d6f7a8b9c0d).'
            );
        }
    }

    private function computeDelay(int $attempt, ?TransportResponse $response): int
    {
        $options = $this->options;
        $base = $options->retryBaseDelaySeconds * 1000;
        if ($options->retryBackoff === RetryBackoff::Exponential) {
            $millis = $base * (2 ** ($attempt - 1));
            $jitterMax = $options->retryJitterSeconds * 1000;
        } else {
            $millis = $base * $attempt;
            $jitterMax = $options->retryJitterSeconds * 1000 * $attempt;
        }
        $millis = (int) round($millis);
        $jitter = (int) round($jitterMax);
        if ($jitter > 0) {
            $millis += random_int(0, $jitter);
        }
        if ($response !== null && $options->respectRetryAfter) {
            $retryAfter = $this->readRetryAfter($response);
            if ($retryAfter !== null && $retryAfter > $millis) {
                $millis = $retryAfter;
            }
        }
        $cap = (int) round($options->maxRetryDelaySeconds * 1000);
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
            return strlen($value) > 9 ? null : ((int) $value) * 1000;
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
        if ($millis <= 0) {
            return;
        }
        // usleep puede volver antes en algunas plataformas: se completa la espera con un reloj monotono.
        $fin = hrtime(true) + ($millis * 1_000_000);
        while (($restante = $fin - hrtime(true)) > 0) {
            usleep(max(1, intdiv($restante, 1000)));
        }
    }

    private static function now(): float
    {
        return hrtime(true) / 1e9;
    }
}
