<?php

declare(strict_types=1);

namespace Ecuafact\Sdk;

use Ecuafact\Sdk\Contracts\ArchivoComprobante;
use Ecuafact\Sdk\Contracts\Admission;
use Ecuafact\Sdk\Contracts\BusquedaContribuyente;
use Ecuafact\Sdk\Contracts\ClaveAccesoDecodificada;
use Ecuafact\Sdk\Contracts\CatalogoDetalle;
use Ecuafact\Sdk\Contracts\Catalogos;
use Ecuafact\Sdk\Contracts\CodEstado;
use Ecuafact\Sdk\Contracts\Contexto;
use Ecuafact\Sdk\Contracts\ContribuyenteConsulta;
use Ecuafact\Sdk\Contracts\EstablecimientosContribuyente;
use Ecuafact\Sdk\Contracts\EstadoServicio;
use Ecuafact\Sdk\Contracts\Operation;
use Ecuafact\Sdk\Contracts\PaginaComprobantes;
use Ecuafact\Sdk\Contracts\PerfilEmisor;
use Ecuafact\Sdk\Contracts\PerfilEmisorUpdate;
use Ecuafact\Sdk\Contracts\QuotaBucket;
use Ecuafact\Sdk\Contracts\SolicitudComprobante;
use Ecuafact\Sdk\Contracts\ValidacionIdentificacion;
use Ecuafact\Sdk\Support\Hydrator;
use Ecuafact\Sdk\Support\Serialization;
use Ecuafact\Sdk\Transport\CurlTransport;
use Ecuafact\Sdk\Transport\ResponseLimitAwareTransportInterface;
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
 * tope total, X-Correlation-Id, X-Additional-Info y reintentos solo para esa llamada).
 */
final class EcuafactClient
{
    private const IDEMPOTENCY_KEY_PATTERN = '/^[A-Za-z0-9\-_.:\/]{1,128}$/D';
    private const IDEMPOTENCY_KEY_MESSAGE = 'Idempotency-Key: solo letras, digitos y - _ . : /, maximo 128 caracteres.';
    private const UUID_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D';
    private const TIPO_INDICAR = 'Usa el codigo (01 factura, 03 liquidacion de compra, 04 nota de credito, '
        . '05 nota de debito, 06 guia de remision, 07 retencion) o su nombre (por ejemplo factura).';
    private const IDENTIFICACION_PATTERN = '/^[0-9]{5,13}$/D';
    private const IDENTIFICACION_MESSAGE = 'La identificacion del contribuyente debe tener de 5 a 13 digitos: usa el '
        . 'valor identificacion de GET /v1/contexto, el mismo de infoTributaria.ruc.';
    private const CODIGO_POR_NOMBRE = [
        'factura' => '01', 'facturas' => '01',
        'liquidacion' => '03', 'liquidacion-compra' => '03', 'liquidaciones-compra' => '03',
        'nota-credito' => '04', 'notas-credito' => '04',
        'nota-debito' => '05', 'notas-debito' => '05',
        'guia' => '06', 'guia-remision' => '06', 'guias-remision' => '06',
        'retencion' => '07', 'retenciones' => '07',
    ];
    private const RUTAS_POR_TIPO = [
        '01' => 'facturas',
        '03' => 'liquidaciones-compra',
        '04' => 'notas-credito',
        '05' => 'notas-debito',
        '06' => 'guias-remision',
        '07' => 'retenciones',
    ];

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
        SolicitudComprobante $comprobante,
        ?string $idempotencyKey = null,
        ?RequestOptions $opciones = null
    ): EmisionResultado {
        return $this->emitirEn($this->requireIdentificacion(), $comprobante, $idempotencyKey, $opciones);
    }

    /**
     * Emite un comprobante (202) en la ruta de su tipo: la clase de la solicitud (`FacturaRequest`,
     * `LiquidacionCompraRequest`, `NotaCreditoRequest`, `NotaDebitoRequest`, `GuiaRemisionRequest` o
     * `RetencionRequest`) fija el endpoint y el cuerpo es exactamente el de ese tipo.
     * Con `validarAntesDeEmitir` (cliente) o `validarAntes` (llamada) llama antes a `/validar`: si no es valido lanza
     * {@see EcuafactValidationException} sin emitir y las advertencias quedan en `EmisionResultado::$advertencias`.
     * Si no pasas `$idempotencyKey`, el SDK la genera y la reutiliza en los reintentos; la devuelve en
     * `EmisionResultado::$idempotencyKey`.
     *
     * @throws EcuafactApiException|EcuafactSdkException
     */
    public function emitirEn(
        string $identificacion,
        SolicitudComprobante $comprobante,
        ?string $idempotencyKey = null,
        ?RequestOptions $opciones = null
    ): EmisionResultado {
        $key = $idempotencyKey !== null && $idempotencyKey !== '' ? $idempotencyKey : bin2hex(random_bytes(16));
        $this->validateIdempotencyKey($key);
        $path = 'v1/contribuyentes/' . $this->identificacionRuta($identificacion) . '/'
            . $this->rutaPorTipo($comprobante);
        $advertencias = [];
        if ($opciones?->validarAntes ?? $this->options->validarAntesDeEmitir) {
            $previa = $this->validarEn($identificacion, $comprobante, $opciones);
            if (!$previa->valido || $previa->errores !== []) {
                throw new EcuafactValidationException([
                    'estado' => 'rechazado',
                    'codEstado' => $previa->codEstado ?? CodEstado::COMPROBANTE_INVALIDO,
                    'msgError' => $previa->msgError ?? 'El comprobante no cumple las validaciones.',
                    'errores' => $previa->errores,
                ], 422, $previa->correlationId, $key);
            }
            $advertencias = $previa->advertencias;
        }
        $correlation = null;
        /** @var Admission $admission */
        $admission = $this->send(
            'POST',
            $path,
            $comprobante,
            $key,
            Admission::class,
            false,
            $correlation,
            $opciones
        );
        return new EmisionResultado($admission, $key, $correlation, $advertencias);
    }

    public function validar(SolicitudComprobante $comprobante, ?RequestOptions $opciones = null): ValidacionResultado
    {
        return $this->validarEn($this->requireIdentificacion(), $comprobante, $opciones);
    }

    /**
     * Valida un comprobante sin emitirlo (`POST` a la ruta de su tipo + `/validar`):
     * mismas reglas que la emision, sin `Idempotency-Key`, sin consumir cupo ni emitir. Con 200 devuelve
     * `valido = true` y las `advertencias` que traiga el cuerpo; con 422 y `codEstado` 3002 (`comprobante_invalido`) devuelve `valido = false` y los
     * `errores` (no lanza). Cualquier otro error lanza como el resto de llamadas (por ejemplo 403
     * `acceso_denegado` con {@see EcuafactAuthException}). Se reintenta como un GET.
     *
     * @throws EcuafactApiException|EcuafactSdkException
     */
    public function validarEn(
        string $identificacion,
        SolicitudComprobante $comprobante,
        ?RequestOptions $opciones = null
    ): ValidacionResultado {
        $path = 'v1/contribuyentes/' . $this->identificacionRuta($identificacion) . '/'
            . $this->rutaPorTipo($comprobante) . '/validar';
        $headers = $this->buildJsonHeaders('application/json; charset=utf-8', null, $opciones);
        // Sin efectos: se reintenta como un GET aunque no lleve Idempotency-Key.
        $response = $this->requestWithRetry(
            'POST',
            $this->buildUrl($path),
            $headers,
            Serialization::encode($comprobante),
            true,
            null,
            $opciones
        );
        if ($response->status === 422 && $this->codEstado($response->body) === CodEstado::COMPROBANTE_INVALIDO) {
            return $this->parseValidacionInvalida($response, $opciones);
        }
        if ($response->status >= 400) {
            throw $this->buildApiException($response, null, $opciones);
        }
        $cuerpo = json_decode($response->body, true);
        $cuerpo = is_array($cuerpo) ? $cuerpo : [];
        return new ValidacionResultado(
            ($cuerpo['valido'] ?? true) !== false,
            null,
            null,
            $this->listaTextos($cuerpo['errores'] ?? null),
            $response->header('X-Correlation-Id') ?? $opciones?->correlationId,
            $this->listaTextos($cuerpo['advertencias'] ?? null)
        );
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
        return $this->send('GET', 'v1/operaciones/' . rawurlencode($idOperacion), null, null, Operation::class, false, $correlation, $opciones);
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
        return $this->send('GET', 'v1/contexto', null, null, Contexto::class, false, $correlation, $opciones);
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
        return $this->send('GET', $this->rutaPerfil($identificacion), null, null, PerfilEmisor::class, false, $correlation, $opciones);
    }

    public function actualizarPerfil(
        string $identificacion,
        PerfilEmisorUpdate $cambios,
        ?RequestOptions $opciones = null
    ): PerfilEmisor {
        $correlation = null;
        return $this->send('PUT', $this->rutaPerfil($identificacion), $cambios, null, PerfilEmisor::class, false, $correlation, $opciones);
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
        return $this->send('GET', 'v1/consumo', null, null, QuotaBucket::class, false, $correlation, $opciones);
    }

    /**
     * Comprueba que el servicio responde (`GET /salud`, no requiere API Key y no consume cupo). Devuelve true
     * con una respuesta 2xx; con cualquier otro estado lanza {@see EcuafactApiException}.
     *
     * @throws EcuafactApiException|EcuafactSdkException
     */
    public function obtenerSalud(?RequestOptions $opciones = null): bool
    {
        $headers = ['Accept: application/json', 'User-Agent: ' . $this->options->userAgent];
        if ($opciones?->correlationId !== null) {
            $headers[] = 'X-Correlation-Id: ' . $opciones->correlationId;
        }
        $response = $this->requestWithRetry('GET', $this->buildUrl('salud'), $headers, null, true, null, $opciones);
        if ($response->status >= 400) {
            throw $this->buildApiException($response, null, $opciones);
        }
        return true;
    }

    /**
     * Consulta el estado publico del servicio (`GET /estado`): estado general, componentes, disponibilidad de los
     * ultimos 90 dias e incidentes. No requiere API Key y no consume cupo.
     *
     * @throws EcuafactApiException|EcuafactSdkException
     */
    public function obtenerEstadoServicio(?RequestOptions $opciones = null): EstadoServicio
    {
        $headers = ['Accept: application/json', 'User-Agent: ' . $this->options->userAgent];
        if ($opciones?->correlationId !== null) {
            $headers[] = 'X-Correlation-Id: ' . $opciones->correlationId;
        }
        $response = $this->requestWithRetry('GET', $this->buildUrl('estado'), $headers, null, true, null, $opciones);
        if ($response->status >= 400) {
            throw $this->buildApiException($response, null, $opciones);
        }
        /** @var EstadoServicio $estado */
        $estado = $this->parse($response->body, EstadoServicio::class);
        return $estado;
    }

    /**
     * Lista los catalogos del SRI (`GET v1/catalogos`): la clave de cada uno y una descripcion breve. Usa la clave
     * en {@see obtenerCatalogo()}. No consume cupo.
     *
     * @throws EcuafactApiException|EcuafactSdkException
     */
    public function listarCatalogos(?RequestOptions $opciones = null): Catalogos
    {
        /** @var Catalogos $resultado */
        $correlation = null;
        $resultado = $this->send('GET', 'v1/catalogos', null, null, Catalogos::class, false, $correlation, $opciones);
        return $resultado;
    }

    /**
     * Obtiene el detalle de un catalogo del SRI (`GET v1/catalogos/{nombre}`), por ejemplo `tarifas-iva`. `$nombre`
     * es la clave que devuelve {@see listarCatalogos()}. Si el catalogo no existe lanza
     * {@see EcuafactNotFoundException}. No consume cupo.
     *
     * @throws EcuafactApiException|EcuafactSdkException
     */
    public function obtenerCatalogo(string $nombre, ?RequestOptions $opciones = null): CatalogoDetalle
    {
        if (trim($nombre) === '') {
            throw new EcuafactSdkException('El nombre del catalogo es obligatorio.');
        }
        /** @var CatalogoDetalle $resultado */
        $correlation = null;
        $resultado = $this->send('GET', 'v1/catalogos/' . $this->segmento($nombre), null, null, CatalogoDetalle::class, false, $correlation, $opciones);
        return $resultado;
    }

    /**
     * Lista una pagina de los comprobantes de un contribuyente (`GET v1/operaciones`; no consume cupo).
     * `$desde` y `$hasta` van en formato `yyyy-MM-dd`; `$tipoDocumento` acepta el codigo (`01`) o el nombre
     * (`factura`); `$buscar` filtra por numero, clave de acceso, contraparte o referencia; `$pagina` empieza
     * en 1 y `$tamanoPagina` va de 1 a 100. `PaginaComprobantes::$hayMas` indica si existe otra pagina.
     *
     * @throws EcuafactApiException|EcuafactSdkException
     */
    public function listarOperaciones(
        string $identificacion,
        ?string $desde = null,
        ?string $hasta = null,
        ?string $tipoDocumento = null,
        ?string $buscar = null,
        int $pagina = 1,
        int $tamanoPagina = 20,
        ?RequestOptions $opciones = null
    ): PaginaComprobantes {
        $parts = ['identificacion=' . rawurlencode($this->identificacionRuta($identificacion))];
        foreach (['desde' => $desde, 'hasta' => $hasta] as $nombre => $valor) {
            if ($valor !== null && trim($valor) !== '') {
                $parts[] = $nombre . '=' . rawurlencode(trim($valor));
            }
        }
        if ($tipoDocumento !== null && trim($tipoDocumento) !== '') {
            $codigo = $this->codigoTipo($tipoDocumento);
            if ($codigo === null) {
                throw new EcuafactTipoComprobanteException('El tipo de comprobante no es valido. ' . self::TIPO_INDICAR);
            }
            $parts[] = 'tipoDocumento=' . $codigo;
        }
        if ($buscar !== null && trim($buscar) !== '') {
            $parts[] = 'buscar=' . rawurlencode($buscar);
        }
        if ($pagina < 1) {
            throw new EcuafactSdkException('pagina empieza en 1.');
        }
        if ($tamanoPagina < 1 || $tamanoPagina > 100) {
            throw new EcuafactSdkException('tamanoPagina va de 1 a 100.');
        }
        $parts[] = 'pagina=' . $pagina;
        $parts[] = 'tamanoPagina=' . $tamanoPagina;
        $correlation = null;
        /** @var PaginaComprobantes $resultado */
        $resultado = $this->send(
            'GET',
            'v1/operaciones?' . implode('&', $parts),
            null,
            null,
            PaginaComprobantes::class,
            false,
            $correlation,
            $opciones
        );
        return $resultado;
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
        $path = 'v1/consultas/contribuyentes/' . $this->segmento($identificacion)
            . '?fusionar=' . ($fusionar ? 'true' : 'false');
        if ($fuente !== null && trim($fuente) !== '') {
            $path .= '&fuente=' . rawurlencode($fuente);
        }
        /** @var ContribuyenteConsulta $resultado */
        $correlation = null;
        $resultado = $this->send('GET', $path, null, null, ContribuyenteConsulta::class, false, $correlation, $opciones);
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
        $resultado = $this->send('GET', $path, null, null, BusquedaContribuyente::class, true, $correlation, $opciones);
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
        $path = 'v1/consultas/establecimientos/' . $this->segmento($ruc);
        if ($filtro !== null && trim($filtro) !== '') {
            $path .= '?filtro=' . rawurlencode($filtro);
        }
        /** @var EstablecimientosContribuyente $resultado */
        $correlation = null;
        $resultado = $this->send('GET', $path, null, null, EstablecimientosContribuyente::class, false, $correlation, $opciones);
        return $resultado;
    }

    public function validarIdentificacion(string $numero, ?RequestOptions $opciones = null): ValidacionIdentificacion
    {
        if (trim($numero) === '') {
            throw new EcuafactSdkException('El numero a validar es obligatorio.');
        }
        $path = 'v1/consultas/identificacion/validar/' . $this->segmento($numero);
        /** @var ValidacionIdentificacion $resultado */
        $correlation = null;
        $resultado = $this->send('GET', $path, null, null, ValidacionIdentificacion::class, false, $correlation, $opciones);
        return $resultado;
    }

    public function decodificarClaveAcceso(string $claveAcceso, ?RequestOptions $opciones = null): ClaveAccesoDecodificada
    {
        if (trim($claveAcceso) === '') {
            throw new EcuafactSdkException('La clave de acceso es obligatoria.');
        }
        $path = 'v1/consultas/comprobantes/decodificar/' . $this->segmento($claveAcceso);
        /** @var ClaveAccesoDecodificada $resultado */
        $correlation = null;
        $resultado = $this->send('GET', $path, null, null, ClaveAccesoDecodificada::class, false, $correlation, $opciones);
        return $resultado;
    }

    private function rutaPerfil(string $identificacion): string
    {
        return 'v1/contribuyentes/' . $this->identificacionRuta($identificacion) . '/perfil';
    }

    private function descargarArchivo(
        string $identificacion,
        string $claveAcceso,
        string $formato,
        ?RequestOptions $opciones
    ): ArchivoComprobante {
        $path = 'v1/contribuyentes/' . $this->identificacionRuta($identificacion)
            . '/comprobantes/' . $this->segmento($claveAcceso) . '/' . $formato;
        $url = $this->buildUrl($path);
        $headers = [
            'X-Api-Key: ' . $this->options->apiKey,
            'User-Agent: ' . $this->options->userAgent,
        ];
        if ($opciones?->correlationId !== null) {
            $headers[] = 'X-Correlation-Id: ' . $opciones->correlationId;
        }
        $this->agregarAdditionalInfo($headers, $opciones);
        $response = $this->requestWithRetry('GET', $url, $headers, null, true, null, $opciones, true);
        if ($response->status >= 400) {
            throw $this->buildApiException($response, null, $opciones);
        }
        $archivo = new ArchivoComprobante();
        $archivo->contenido = $response->body;
        $tipo = $response->header('Content-Type');
        $archivo->contentType = $tipo !== null && $tipo !== '' ? explode(';', $tipo)[0] : 'application/octet-stream';
        return $archivo;
    }

    private function send(
        string $method,
        string $path,
        ?object $body,
        ?string $idempotencyKey,
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
        string $modelClass,
        bool $list,
        ?string &$correlationId,
        ?RequestOptions $opciones = null
    ): mixed {
        $url = $this->buildUrl($path);
        $headers = $this->buildJsonHeaders($contentType, $idempotencyKey, $opciones);

        // Solo se reintentan solicitudes idempotentes: GET, o POST/PUT con Idempotency-Key.
        $retryable = $method === 'GET' || ($idempotencyKey !== null && ($method === 'POST' || $method === 'PUT'));
        $response = $this->requestWithRetry($method, $url, $headers, $payload, $retryable, $idempotencyKey, $opciones);
        if ($response->status >= 400) {
            throw $this->buildApiException($response, $idempotencyKey, $opciones);
        }
        $correlationId = $response->header('X-Correlation-Id') ?? $opciones?->correlationId;
        try {
            return $this->parse($response->body, $modelClass, $list);
        } catch (EcuafactSdkException $error) {
            $detalle = $idempotencyKey !== null
                ? ' La solicitud pudo haberse registrado: repite la llamada con la misma Idempotency-Key (' . $idempotencyKey
                    . ') o consulta el resultado; no emitas de nuevo con otra clave.'
                : '';
            throw new EcuafactInvalidResponseException(
                'El API respondio HTTP ' . $response->status . ' con un cuerpo que no se pudo leer (' . $error->getMessage() . ').'
                    . $detalle . ($correlationId !== null ? ' X-Correlation-Id: ' . $correlationId . '.' : ''),
                $response->status,
                $idempotencyKey,
                $correlationId,
                $error
            );
        }
    }

    /** @return string[] */
    private function buildJsonHeaders(?string $contentType, ?string $idempotencyKey, ?RequestOptions $opciones): array
    {
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
        $this->agregarAdditionalInfo($headers, $opciones);
        return $headers;
    }

    /** @return string[] */
    private function listaTextos(mixed $valor): array
    {
        $textos = [];
        if (is_array($valor)) {
            foreach ($valor as $item) {
                if (is_scalar($item)) {
                    $textos[] = (string) $item;
                }
            }
        }
        return $textos;
    }

    private function parseValidacionInvalida(TransportResponse $response, ?RequestOptions $opciones): ValidacionResultado
    {
        $data = json_decode($response->body, true);
        if (!is_array($data)) {
            throw new EcuafactSdkException('Respuesta del API no reconocida.');
        }
        $errores = $this->listaTextos($data['errores'] ?? null);
        $msgError = isset($data['msgError']) && is_scalar($data['msgError']) ? (string) $data['msgError'] : null;
        return new ValidacionResultado(
            false,
            EcuafactApiException::codEstadoDe($data),
            $msgError,
            $errores,
            $response->header('X-Correlation-Id') ?? $opciones?->correlationId,
            $this->listaTextos($data['advertencias'] ?? null)
        );
    }

    /**
     * Agrega X-Additional-Info: el valor de la llamada reemplaza al del cliente; null no la envia.
     *
     * @param string[] $headers
     */
    private function agregarAdditionalInfo(#[\SensitiveParameter] array &$headers, ?RequestOptions $opciones): void
    {
        $valor = $opciones?->additionalInfo ?? $this->options->additionalInfo;
        if ($valor !== null && $valor !== '') {
            $headers[] = 'X-Additional-Info: ' . $valor;
        }
    }

    /** @param string[] $headers */
    private function requestWithRetry(
        string $method,
        string $url,
        #[\SensitiveParameter] array $headers,
        ?string $payload,
        bool $retryable,
        ?string $idempotencyKey,
        ?RequestOptions $opciones = null,
        bool $descarga = false
    ): TransportResponse {
        $options = $this->options;
        $maxBytes = $descarga ? $options->maxDownloadBytes : $options->maxResponseBytes;
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
                if ($this->transport instanceof ResponseLimitAwareTransportInterface) {
                    $this->transport->limitarRespuesta($maxBytes);
                }
                $response = $this->transportRequest($method, $url, $headers, $payload, $remaining, $opciones?->timeout);
                if ($maxBytes !== null && strlen($response->body) > $maxBytes) {
                    throw new EcuafactResponseTooLargeException($maxBytes, strlen($response->body));
                }
            } catch (EcuafactResponseTooLargeException $exception) {
                throw $exception->conContexto($idempotencyKey, $correlacion);
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
            // Solo el 429 con codEstado 5005 (limite_solicitudes), segun retryRateLimited. El 4004 (cupo_agotado),
            // un 429 sin cuerpo o con otro codigo no se reintentan.
            return $this->options->retryRateLimited
                && $this->codEstado($response->body) === CodEstado::LIMITE_SOLICITUDES;
        }
        return in_array($response->status, $this->options->retryableStatusCodes, true);
    }

    /** `codEstado` de un cuerpo de error; 0 si no es JSON o no lo trae. */
    private function codEstado(string $body): int
    {
        $decoded = json_decode($body, true);
        return EcuafactApiException::codEstadoDe(is_array($decoded) ? $decoded : null);
    }

    private function parse(string $text, string $modelClass, bool $list = false): mixed
    {
        if (trim($text) === '') {
            throw new EcuafactSdkException('El API devolvio una respuesta vacia.');
        }
        $data = json_decode($text);
        if ($data === null && trim($text) !== 'null') {
            throw new EcuafactSdkException('Respuesta del API no reconocida.');
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

    /** Segmento de la ruta de emision: sale del tipo de la solicitud. */
    private function rutaPorTipo(SolicitudComprobante $comprobante): string
    {
        return self::RUTAS_POR_TIPO[$comprobante->tipoComprobante()->value];
    }

    private function codigoTipo(string $valor): ?string
    {
        $texto = strtolower(trim($valor));
        $texto = strtr($texto, ['ó' => 'o', 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ú' => 'u']);
        $texto = preg_replace('/[_\s]+/', '-', $texto) ?? $texto;
        if (isset(self::RUTAS_POR_TIPO[$texto])) {
            return $texto;
        }
        return self::CODIGO_POR_NOMBRE[$texto] ?? null;
    }

    private function identificacionRuta(string $identificacion): string
    {
        if (preg_match(self::IDENTIFICACION_PATTERN, $identificacion) !== 1) {
            throw new EcuafactSdkException(self::IDENTIFICACION_MESSAGE);
        }
        return $identificacion;
    }

    /** Codifica un segmento de ruta. Rechaza vacios y los segmentos `.` y `..`, que el cliente HTTP colapsaria. */
    private function segmento(string $valor): string
    {
        $recortado = trim($valor);
        if ($recortado === '' || $recortado === '.' || $recortado === '..') {
            throw new EcuafactSdkException("El valor de la ruta no puede estar vacio ni ser '.' o '..'.");
        }
        return rawurlencode($valor);
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

    private function validateIdempotencyKey(string $key): void
    {
        if (preg_match(self::IDEMPOTENCY_KEY_PATTERN, $key) !== 1) {
            throw new EcuafactSdkException(self::IDEMPOTENCY_KEY_MESSAGE);
        }
    }

    private function validateUuid(string $valor, string $nombre = 'idOperacion'): void
    {
        if (preg_match(self::UUID_PATTERN, $valor) !== 1) {
            throw new EcuafactSdkException(
                $nombre . ' debe ser un UUID (por ejemplo 3f2b8c1e-7a4d-4e2b-9c11-5d6f7a8b9c0d).'
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
