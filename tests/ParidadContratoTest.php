<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Tests;

use Ecuafact\Sdk\Contracts\FacturaRequest;
use Ecuafact\Sdk\Contracts\GuiaRemisionRequest;
use Ecuafact\Sdk\Contracts\InfoTributaria;
use Ecuafact\Sdk\Contracts\LiquidacionCompraRequest;
use Ecuafact\Sdk\Contracts\NotaCreditoRequest;
use Ecuafact\Sdk\Contracts\NotaDebitoRequest;
use Ecuafact\Sdk\Contracts\RetencionRequest;
use Ecuafact\Sdk\Contracts\PerfilEmisorUpdate;
use Ecuafact\Sdk\EcuafactClient;
use Ecuafact\Sdk\EcuafactClientOptions;
use Ecuafact\Sdk\RequestOptions;
use Ecuafact\Sdk\Transport\TransportInterface;
use Ecuafact\Sdk\Transport\TransportResponse;
use PHPUnit\Framework\TestCase;

/**
 * Paridad del SDK con el contrato publico (`examples/openapi-v1.json`), en ambos sentidos:
 *
 *  - ningun campo del SDK que el contrato no tenga (salvo la lista explicita de excepciones);
 *  - ningun campo del contrato que falte en el SDK;
 *  - mismo tipo, y nulabilidad no mas estricta que la del contrato;
 *  - cada operacion del contrato tiene metodo y cada metodo apunta a una operacion.
 *
 * El snapshot se lee de `../../examples` (el repo). El paquete publicado no lo incluye: alli la prueba se omite.
 *
 * Criterios de comparacion:
 *  - Tipos: number = `float|string|float`, integer = `int`, string/uuid/date-time = `string`, boolean = `bool`,
 *    array = `array` con elemento (`@var X[]`), objeto = clase del SDK que corresponde a ese esquema.
 *  - Nulabilidad: todos los modelos del SDK admiten null (leen payloads parciales); se reporta solo el caso
 *    contrario, una propiedad no nullable en el SDK que el contrato declara nullable.
 *  - Obligatoriedad: el SDK no la valida (cada propiedad es opcional, el API valida y responde 422); la documenta: cada
 *    propiedad que el contrato exige lleva `Obligatorio.` en su docblock, y la prueba exige que coincidan exactamente.
 *  - Un modelo por esquema: cada tipo de comprobante tiene su request, su Info y sus lineas, sin modelos union.
 */
final class ParidadContratoTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private static ?array $spec = null;

    /**
     * Clase del SDK => esquemas del contrato que modela.
     *
     * @var array<string, string[]>
     */
    private const ESQUEMAS = [
        // Solicitud.
        'FacturaRequest' => ['FacturaRequestDto'],
        'LiquidacionCompraRequest' => ['LiquidacionCompraRequestDto'],
        'NotaCreditoRequest' => ['NotaCreditoRequestDto'],
        'NotaDebitoRequest' => ['NotaDebitoRequestDto'],
        'GuiaRemisionRequest' => ['GuiaRemisionRequestDto'],
        'RetencionRequest' => ['ComprobanteRetencionRequestDto'],
        'InfoTributaria' => ['InfoTributariaDto'],
        'InfoFactura' => ['InfoFacturaDto'],
        'InfoLiquidacionCompra' => ['InfoLiquidacionCompraDto'],
        'InfoNotaCredito' => ['InfoNotaCreditoDto'],
        'InfoNotaDebito' => ['InfoNotaDebitoDto'],
        'InfoGuiaRemision' => ['InfoGuiaRemisionDto'],
        'InfoComprobanteRetencion' => ['InfoComprobanteRetencionDto'],
        'DetalleCompraVenta' => ['DetalleCompraVentaDto'],
        'DetalleNotaCredito' => ['DetalleNotaCreditoDto'],
        'DetalleGuia' => ['DetalleGuiaDto'],
        'Impuesto' => ['ImpuestoDto'],
        'Pago' => ['PagoDto'],
        'CampoAdicional' => ['CampoAdicionalDto'],
        'Motivo' => ['MotivoDto'],
        'Destinatario' => ['DestinatarioDto'],
        'DocsSustento' => ['DocsSustentoDto'],
        'ImpuestoDocSustento' => ['ImpuestoDocSustentoDto'],
        'RetencionLinea' => ['RetencionLineaDto'],
        'PerfilEmisorUpdate' => ['PerfilEmisorUpdateDto'],
        // Respuesta.
        'Admission' => ['OperationDto'],
        'Operation' => ['OperationDto'],
        'ApiError' => ['ErrorDto'],
        'Contexto' => ['ContextoDto'],
        'Contribuyente' => ['ContribuyenteDto'],
        'QuotaBucket' => ['QuotaBucketDto'],
        'PerfilEmisor' => ['PerfilEmisorDto'],
        'Comprobante' => ['ComprobanteDto'],
        'PaginaComprobantes' => ['PaginaComprobantesDto'],
        'ContribuyenteConsulta' => ['ContribuyenteConsultaDto'],
        'BusquedaContribuyente' => ['BusquedaContribuyenteDto'],
        'RepresentanteLegalConsulta' => ['RepresentanteLegalDto'],
        'EstablecimientoConsulta' => ['EstablecimientoConsultaDto'],
        'EstablecimientosContribuyente' => ['EstablecimientosConsultaDto'],
        'EstadoTributario' => ['EstadoTributarioDto'],
        'ValidacionIdentificacion' => ['ValidacionIdentificacionDto'],
        'ClaveAccesoDecodificada' => ['ClaveAccesoDecodificadaDto'],
        'EstadoServicio' => ['EstadoPublicoDto'],
        'ComponenteEstado' => ['ComponenteEstadoDto'],
        'IncidentePublico' => ['IncidentePublicoDto'],
        'Catalogos' => ['CatalogosDto'],
        'CatalogoResumen' => ['CatalogoResumenDto'],
        'CatalogoDetalle' => ['CatalogoDetalleDto'],
        'CatalogoItem' => ['CatalogoItemDto'],
    ];

    /** Clase (con espacio de nombres corto) => espacio de nombres. */
    private const NAMESPACE_DE = [
        'ValidacionResultado' => 'Ecuafact\\Sdk\\',
    ];

    /** ValidacionResultado: resultado de validar, une 200 (ValidacionComprobanteDto) y 422 (ErrorDto). */
    private const ESQUEMAS_RAIZ = [
        'ValidacionResultado' => ['ValidacionComprobanteDto', 'ErrorDto'],
    ];

    /**
     * Propiedades del SDK que el contrato no tiene, a proposito. Cada una con su justificacion.
     *
     * @var array<string, array<string, string>>
     */
    private const EXCEPCIONES = [
        'Admission' => [
            // `extra` no es campo del contrato: guarda lo no declarado al leer.
        ],
        'ValidacionResultado' => [
            'correlationId' => 'Cabecera X-Correlation-Id de la respuesta, no un campo del cuerpo.',
        ],
    ];

    /** Nulabilidad que el SDK normaliza a proposito (clase::propiedad => motivo). */
    private const NULABILIDAD_ACEPTADA = [
        'ValidacionResultado::advertencias' => 'El contrato la admite null; el SDK la entrega siempre como lista (vacia si falta).',
    ];

    /** Propiedades del contrato que el SDK modela con otra forma, a proposito. */
    private const OMISIONES = [
        'ValidacionResultado' => [
            'estado' => 'ErrorDto.estado: el 422 de validar siempre es comprobante_invalido (3002); el SDK expone solo codEstado/msgError/errores.',
        ],
    ];

    public static function setUpBeforeClass(): void
    {
        $ruta = __DIR__ . '/../../../examples/openapi-v1.json';
        if (is_file($ruta)) {
            $json = json_decode((string) file_get_contents($ruta), true);
            self::$spec = is_array($json) ? $json : null;
        }
    }

    protected function setUp(): void
    {
        if (self::$spec === null) {
            $this->markTestSkipped('No esta examples/openapi-v1.json (paquete publicado fuera del repo).');
        }
    }

    // ------------------------------------------------------------------ modelos

    public function testLosModelosCoincidenConElContratoEnAmbosSentidos(): void
    {
        $diferencias = [];
        $mapa = self::ESQUEMAS + self::ESQUEMAS_RAIZ;
        foreach ($mapa as $corta => $esquemas) {
            $clase = $this->claseSdk($corta);
            $diferencias = array_merge($diferencias, $this->compararClase($corta, $clase, $esquemas));
        }
        $this->assertSame([], $diferencias, "Brechas SDK vs contrato:\n - " . implode("\n - ", $diferencias));
    }

    public function testCadaEsquemaDelContratoTieneUnaClaseOUnaExcepcion(): void
    {
        $cubiertos = [];
        foreach (self::ESQUEMAS + self::ESQUEMAS_RAIZ as $esquemas) {
            foreach ($esquemas as $e) {
                $cubiertos[$e] = true;
            }
        }
        // Enumeraciones/primitivos y el archivo del logo (multipart): no son modelos JSON.
        $noModelos = [
            'CodEstado' => 'int validado por CodEstado::class',
            'EstadoPublico' => 'cadena validada por EstadoOperacion',
            'FiscalEnvironment' => 'int (ambiente)',
            'IFormFile' => 'multipart del logo: actualizarLogo(contenido, contentType)',
            // Gestion de destinos de webhooks: no la ofrece el SDK por diseno (portal, CLI, MCP y API REST).
            'WebhookEndpointDto' => 'gestion de destinos fuera del SDK',
            'WebhookEndpointCreatedDto' => 'gestion de destinos fuera del SDK',
            'WebhookDeliveryDto' => 'gestion de destinos fuera del SDK',
            'WebhookDeliveryAttemptDto' => 'gestion de destinos fuera del SDK',
            'WebhookDestinoCrearRequest' => 'gestion de destinos fuera del SDK',
            'WebhookDestinoEventosRequest' => 'gestion de destinos fuera del SDK',
        ];
        $faltan = [];
        foreach (array_keys(self::$spec['components']['schemas']) as $nombre) {
            if (!isset($cubiertos[$nombre]) && !isset($noModelos[$nombre])) {
                $faltan[] = $nombre;
            }
        }
        $this->assertSame([], $faltan, 'Esquemas del contrato sin modelo en el SDK: ' . implode(', ', $faltan));
    }

    public function testElContratoDelSdkNoTieneDtosDeclaradosQueYaNoExistan(): void
    {
        $existentes = array_keys(self::$spec['components']['schemas']);
        foreach (self::ESQUEMAS + self::ESQUEMAS_RAIZ as $esquemas) {
            foreach ($esquemas as $e) {
                $this->assertContains($e, $existentes, "El esquema $e ya no existe en el contrato.");
            }
        }
    }

    public function testCodEstadoYEstadosCoincidenConLosEnumsDelContrato(): void
    {
        $schemas = self::$spec['components']['schemas'];
        $this->assertEqualsCanonicalizing(
            $schemas['EstadoPublico']['enum'],
            array_map(
                static fn (\Ecuafact\Sdk\Contracts\EstadoOperacion $e): string => $e->value,
                array_values(array_filter(
                    \Ecuafact\Sdk\Contracts\EstadoOperacion::cases(),
                    static fn ($e): bool => $e !== \Ecuafact\Sdk\Contracts\EstadoOperacion::Desconocido
                ))
            ),
            'EstadoOperacion difiere de EstadoPublico.'
        );
        $codigos = [];
        $ref = new \ReflectionClass(\Ecuafact\Sdk\Contracts\CodEstado::class);
        foreach ($ref->getReflectionConstants() as $constante) {
            if ($constante->isPublic() && is_int($constante->getValue())) {
                $codigos[] = $constante->getValue();
            }
        }
        $this->assertEqualsCanonicalizing(
            $schemas['CodEstado']['enum'],
            array_values(array_unique($codigos)),
            'CodEstado del SDK difiere del contrato.'
        );
        $tipos = array_map(
            static fn (\Ecuafact\Sdk\Contracts\TipoComprobante $t): string => $t->value,
            \Ecuafact\Sdk\Contracts\TipoComprobante::cases()
        );
        $this->assertEqualsCanonicalizing($schemas['OperationDto']['properties']['tipoDocumento']['enum'], $tipos);
    }

    /** Lo que viaja en el cuerpo: con todas las propiedades llenas, cada clave existe en el contrato. */
    public function testElCuerpoSerializadoSoloLlevaClavesDelContrato(): void
    {
        $claves = [];
        foreach ($this->solicitudesLlenas() as $comprobante) {
            $json = json_decode(\Ecuafact\Sdk\Support\Serialization::encode($comprobante), true);
            $this->assertIsArray($json);
            $claves += $this->clavesDe($json);
        }
        $conocidas = [];
        foreach (self::$spec['components']['schemas'] as $schema) {
            foreach (array_keys($schema['properties'] ?? []) as $p) {
                $conocidas[$p] = true;
            }
        }
        $desconocidas = array_diff(array_keys($claves), array_keys($conocidas));
        $this->assertSame([], array_values($desconocidas), 'Claves del cuerpo que el contrato no tiene.');
    }

    // --------------------------------------------------------------- operaciones

    public function testCadaOperacionDelContratoTieneMetodoYCadaMetodoApuntaAUnaOperacion(): void
    {
        $operaciones = [];
        foreach (self::$spec['paths'] as $ruta => $metodos) {
            foreach ($metodos as $verbo => $op) {
                $operaciones[$op['operationId']] = [strtoupper($verbo), $ruta, $op];
            }
        }

        $transporte = new class implements TransportInterface {
            /** @var array<int, array{method: string, url: string, headers: string[], body: ?string}> */
            public array $peticiones = [];

            public function request(string $method, string $url, array $headers, ?string $body): TransportResponse
            {
                $this->peticiones[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
                $ruta = (string) parse_url($url, PHP_URL_PATH);
                $lista = $method === 'GET' && $ruta === '/v1/consultas/contribuyentes';
                return new TransportResponse(
                    str_contains($ruta, '/v1/contribuyentes/') && str_ends_with($ruta, 's') && $method === 'POST' && !str_contains($ruta, '/perfil') ? 202 : 200,
                    $lista ? '[]' : '{}',
                    ['Content-Type' => 'application/json']
                );
            }
        };
        $cliente = new EcuafactClient(new EcuafactClientOptions(
            baseAddress: 'https://api.example/',
            apiKey: 'clave.secreta',
            identificacion: '0921357232001',
            transport: $transporte,
            retryBaseDelaySeconds: 0.0,
            retryJitterSeconds: 0.0,
        ));

        $uuid = '3f2b8c1e-7a4d-4e2b-9c11-5d6f7a8b9c0d';
        $entrega = '0a1b2c3d-1111-4222-8333-444455556666';
        $ruc = '0921357232001';
        $clave = '1211202601179999999900110010010000001231234567813';

        $cr = static function (string $tipo): \Ecuafact\Sdk\Contracts\SolicitudComprobante {
            $c = match ($tipo) {
                '01' => new FacturaRequest(),
                '03' => new LiquidacionCompraRequest(),
                '04' => new NotaCreditoRequest(),
                '05' => new NotaDebitoRequest(),
                '06' => new GuiaRemisionRequest(),
                '07' => new RetencionRequest(),
            };
            $c->infoTributaria = new InfoTributaria();
            return $c;
        };

        // operationId => [closure, query esperada (nombres) si el SDK debe poder enviarlos todos].
        $llamadas = [
            'obtenerSalud' => static fn () => $cliente->obtenerSalud(),
            'obtenerEstadoServicio' => static fn () => $cliente->obtenerEstadoServicio(),
            'listarCatalogos' => static fn () => $cliente->listarCatalogos(),
            'obtenerCatalogo' => static fn () => $cliente->obtenerCatalogo('tarifas-iva'),
            'obtenerContexto' => static fn () => $cliente->getContexto(),
            'consultarContribuyente' => static fn () => $cliente->consultarContribuyente($ruc, true, 'sri'),
            'buscarContribuyentes' => static fn () => $cliente->buscarContribuyentes('PEREZ', 'JUAN', 'A', 5),
            'listarEstablecimientos' => static fn () => $cliente->listarEstablecimientos($ruc, 'matriz'),
            'validarIdentificacion' => static fn () => $cliente->validarIdentificacion($ruc),
            'decodificarClaveAcceso' => static fn () => $cliente->decodificarClaveAcceso($clave),
            'descargarRide' => static fn () => $cliente->descargarRide($ruc, $clave),
            'descargarXml' => static fn () => $cliente->descargarXml($ruc, $clave),
            'listarOperaciones' => static fn () => $cliente->listarOperaciones($ruc, '2026-11-01', '2026-11-12', '01', 'FAC', 2, 50),
            'consultarOperacion' => static fn () => $cliente->consultarOperacion($uuid),
            'obtenerConsumo' => static fn () => $cliente->getConsumo(),
            'emitirFactura' => static fn () => $cliente->emitir($cr('01'), 'k1'),
            'emitirLiquidacionCompra' => static fn () => $cliente->emitir($cr('03'), 'k1'),
            'emitirNotaCredito' => static fn () => $cliente->emitir($cr('04'), 'k1'),
            'emitirNotaDebito' => static fn () => $cliente->emitir($cr('05'), 'k1'),
            'emitirGuiaRemision' => static fn () => $cliente->emitir($cr('06'), 'k1'),
            'emitirRetencion' => static fn () => $cliente->emitir($cr('07'), 'k1'),
            'obtenerPerfil' => static fn () => $cliente->getPerfil($ruc),
            'actualizarPerfil' => static fn () => $cliente->actualizarPerfil($ruc, new PerfilEmisorUpdate()),
            'actualizarLogoPerfil' => static fn () => $cliente->actualizarLogo($ruc, 'png', 'image/png'),
            'validarFactura' => static fn () => $cliente->validar($cr('01')),
            'validarLiquidacionCompra' => static fn () => $cliente->validar($cr('03')),
            'validarNotaCredito' => static fn () => $cliente->validar($cr('04')),
            'validarNotaDebito' => static fn () => $cliente->validar($cr('05')),
            'validarGuiaRemision' => static fn () => $cliente->validar($cr('06')),
            'validarRetencion' => static fn () => $cliente->validar($cr('07')),
        ];

        // Operaciones del contrato que el SDK NO cubre por diseno (decision del propietario, 2026-11-13): la
        // administracion de destinos de webhooks se hace por portal, CLI, MCP y API REST. El SDK solo ayuda a
        // RECIBIR webhooks (verificacion de firma, tipos de evento y payload). Cualquier otra operacion sin
        // metodo hace fallar esta prueba.
        $noCubiertas = [
            'listarDestinosWebhook', 'crearDestinoWebhook', 'obtenerDestinoWebhook', 'actualizarEventosWebhook',
            'rotarSecretoWebhook', 'probarWebhook', 'pausarWebhook', 'reanudarWebhook', 'deshabilitarWebhook',
            'listarEntregasWebhook', 'listarIntentosEntregaWebhook', 'reintentarEntregaWebhook',
        ];
        $this->assertCount(12, $noCubiertas);
        $this->assertSame([], array_diff($noCubiertas, array_keys($operaciones)), 'Una operacion excluida ya no existe en el contrato.');
        $this->assertSame([], array_values(array_intersect($noCubiertas, array_keys($llamadas))), 'Una operacion excluida tiene metodo.');
        $this->assertEqualsCanonicalizing(
            array_values(array_diff(array_keys($operaciones), $noCubiertas)),
            array_keys($llamadas),
            'Operaciones del contrato sin metodo del SDK (o metodos de operaciones que ya no existen).'
        );

        $problemas = [];
        foreach ($llamadas as $operationId => $llamada) {
            $antes = count($transporte->peticiones);
            try {
                $llamada();
            } catch (\Throwable $e) {
                $problemas[] = "$operationId: la llamada fallo ($e)";
                continue;
            }
            $hechas = array_slice($transporte->peticiones, $antes);
            if (count($hechas) !== 1) {
                $problemas[] = "$operationId: se esperaba 1 peticion y hubo " . count($hechas);
                continue;
            }
            [$verbo, $plantilla, $op] = $operaciones[$operationId];
            $peticion = $hechas[0];
            $ruta = (string) parse_url($peticion['url'], PHP_URL_PATH);
            $patron = '#^' . preg_replace('#\\\\\{[^}]+\\\\\}#', '[^/]+', preg_quote($plantilla, '#')) . '$#';
            if ($peticion['method'] !== $verbo || preg_match($patron, $ruta) !== 1) {
                $problemas[] = "$operationId: el SDK llama {$peticion['method']} $ruta y el contrato dice $verbo $plantilla";
                continue;
            }
            // Parametros de consulta: el SDK debe poder enviar exactamente los del contrato.
            $esperados = [];
            foreach ($op['parameters'] ?? [] as $parametro) {
                if ($parametro['in'] === 'query') {
                    $esperados[] = $parametro['name'];
                }
            }
            parse_str((string) parse_url($peticion['url'], PHP_URL_QUERY), $enviados);
            $sobran = array_diff(array_keys($enviados), $esperados);
            $faltan = array_diff($esperados, array_keys($enviados));
            if ($sobran !== [] || $faltan !== []) {
                $problemas[] = "$operationId: query sobrante [" . implode(',', $sobran) . '] / faltante [' . implode(',', $faltan) . ']';
            }
            // Cabecera Idempotency-Key en las emisiones.
            $requiereClave = false;
            foreach ($op['parameters'] ?? [] as $parametro) {
                if ($parametro['in'] === 'header' && $parametro['name'] === 'Idempotency-Key') {
                    $requiereClave = true;
                }
            }
            $lleva = false;
            foreach ($peticion['headers'] as $linea) {
                if (stripos($linea, 'Idempotency-Key:') === 0) {
                    $lleva = true;
                }
            }
            if ($requiereClave !== $lleva) {
                $problemas[] = "$operationId: Idempotency-Key " . ($requiereClave ? 'falta' : 'sobra');
            }
        }
        $this->assertSame([], $problemas, "Metodos vs operaciones:\n - " . implode("\n - ", $problemas));
    }

    public function testCadaMetodoPublicoDeLosClientesEstaClasificado(): void
    {
        // Metodos que NO son una operacion del contrato (ayudas, accesores, alias).
        $ayudas = [
            'EcuafactClient' => [
                '__construct' => 1, 'options' => 1, '__debugInfo' => 1, 'para' => 1,
                'emitir' => 'despacha a las 6 operaciones emitir*', 'emitirEn' => 'idem, con RUC explicito',
                'validar' => 'despacha a las 6 operaciones validar*', 'validarEn' => 'idem, con RUC explicito',
                'getOperacion' => 'consultarOperacion', 'consultarOperacion' => 'consultarOperacion',
                'esperarResultado' => 'sondeo de consultarOperacion',
            ],
            'EcuafactContribuyente' => [
                '__construct' => 1, 'identificacion' => 1,
                'emitir' => 'despacha a las 6 operaciones emitir*', 'validar' => 'despacha a las 6 operaciones validar*',
                'esperarResultado' => 'sondeo de consultarOperacion',
            ],
        ];
        // Operaciones (metodos del cliente) conocidas; el resto debe estar en $ayudas.
        $operaciones = [
            'getContexto', 'consultarContribuyente', 'buscarContribuyentes', 'listarEstablecimientos',
            'validarIdentificacion', 'decodificarClaveAcceso', 'descargarRide', 'descargarXml', 'listarOperaciones',
            'getConsumo', 'getPerfil', 'actualizarPerfil', 'actualizarLogo', 'obtenerSalud',
            'obtenerEstadoServicio', 'listarCatalogos', 'obtenerCatalogo',
        ];
        $desconocidos = [];
        foreach (['EcuafactClient', 'EcuafactContribuyente'] as $corta) {
            $ref = new \ReflectionClass('Ecuafact\\Sdk\\' . $corta);
            foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
                if ($m->getDeclaringClass()->getName() !== $ref->getName()) {
                    continue;
                }
                $n = $m->getName();
                if (!isset($ayudas[$corta][$n]) && !in_array($n, $operaciones, true)) {
                    $desconocidos[] = "$corta::$n";
                }
            }
        }
        $this->assertSame([], $desconocidos, 'Metodos publicos que no apuntan a una operacion ni son ayudas conocidas.');
    }

    // ------------------------------------------------------------------ eventos webhook

    /**
     * El cuerpo del evento no esta en el OpenAPI (lo publica el servicio como webhook). Estos son sus campos, segun
     * la guia de webhooks y el emisor de eventos; WebhookEvent debe tener un campo tipado por cada uno.
     */
    public function testWebhookEventTipaTodosLosCamposDelCuerpo(): void
    {
        $campos = [
            'eventType' => 'eventType', 'resourceId' => 'resourceId', 'occurredAtUtc' => 'occurredAtUtc',
            'idOperacion' => 'idOperacion', 'estado' => 'estado', 'codEstado' => 'codEstado',
            'errores' => 'errores', 'tipoDocumento' => 'tipoDocumento', 'ruc' => 'ruc',
            'claveAcceso' => 'claveAcceso', 'msgError' => 'msgError', 'fhAutorizacion' => 'fhAutorizacion',
            'probe' => 'probe',
        ];
        $ref = new \ReflectionClass(\Ecuafact\Sdk\WebhookEvent::class);
        $propiedades = array_map(static fn (\ReflectionProperty $p): string => $p->getName(), $ref->getProperties());
        // Propiedades del SDK que no son campos del cuerpo: eventTypeRaw (texto original), cuerpo (completo).
        $propias = ['eventTypeRaw', 'cuerpo'];
        $this->assertSame([], array_values(array_diff(array_keys($campos), $propiedades)), 'Campos del evento sin propiedad.');
        $this->assertSame([], array_values(array_diff($propiedades, array_keys($campos), $propias)), 'Propiedades que el evento no trae.');

        $evento = \Ecuafact\Sdk\WebhookEvent::desdeJson(json_encode([
            'eventType' => 'document.authorized', 'resourceId' => 'r', 'occurredAtUtc' => '2026-11-12T10:00:00.1234567Z',
            'idOperacion' => 'i', 'estado' => 'autorizado', 'codEstado' => 2001, 'errores' => [],
            'tipoDocumento' => '01', 'ruc' => '0921357232001', 'claveAcceso' => 'c', 'fhAutorizacion' => '2026-11-12T10:00:00-05:00',
        ]));
        $this->assertSame('0921357232001', $evento->ruc);
    }

    // ------------------------------------------------------------------ helpers

    /** True para los modelos de solicitud de comprobantes (los que documentan `Obligatorio.`). */
    private static function esDeSolicitud(string $corta): bool
    {
        return in_array($corta, [
            'FacturaRequest', 'LiquidacionCompraRequest', 'NotaCreditoRequest', 'NotaDebitoRequest',
            'GuiaRemisionRequest', 'RetencionRequest', 'InfoTributaria', 'InfoFactura', 'InfoLiquidacionCompra',
            'InfoNotaCredito', 'InfoNotaDebito', 'InfoGuiaRemision', 'InfoComprobanteRetencion',
            'DetalleCompraVenta', 'DetalleNotaCredito', 'DetalleGuia', 'Motivo', 'Destinatario', 'DocsSustento',
            'ImpuestoDocSustento', 'RetencionLinea', 'Impuesto', 'Pago', 'CampoAdicional',
        ], true);
    }

    private function claseSdk(string $corta): string
    {
        $ns = self::NAMESPACE_DE[$corta] ?? 'Ecuafact\\Sdk\\Contracts\\';
        return $ns . $corta;
    }

    /**
     * @param string[] $esquemas
     * @return string[]
     */
    private function compararClase(string $corta, string $clase, array $esquemas): array
    {
        $diffs = [];
        $ref = new \ReflectionClass($clase);
        $sdk = [];
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $p) {
            if ($p->isStatic() || $p->getName() === 'extra') {
                continue;
            }
            $sdk[$p->getName()] = $p;
        }
        $union = [];
        foreach ($esquemas as $nombre) {
            $schema = self::$spec['components']['schemas'][$nombre] ?? null;
            if ($schema === null) {
                $diffs[] = "$corta: el esquema $nombre no existe en el contrato";
                continue;
            }
            foreach ($schema['properties'] ?? [] as $prop => $def) {
                $union[$prop][$nombre] = [
                    'def' => $this->describir($def),
                    'requerida' => in_array($prop, $schema['required'] ?? [], true),
                ];
            }
        }
        $excepciones = self::EXCEPCIONES[$corta] ?? [];
        $omisiones = self::OMISIONES[$corta] ?? [];
        if (self::esDeSolicitud($corta) && count($esquemas) === 1) {
            $requeridas = self::$spec['components']['schemas'][$esquemas[0]]['required'] ?? [];
            foreach ($sdk as $nombre => $propiedad) {
                $marcada = str_contains((string) $propiedad->getDocComment(), 'Obligatorio.');
                $exigida = in_array($nombre, $requeridas, true);
                if ($marcada !== $exigida) {
                    $diffs[] = "$corta::\$$nombre: obligatoriedad, el contrato dice " . ($exigida ? 'obligatoria' : 'opcional')
                        . ' y el docblock del SDK ' . ($marcada ? 'la marca obligatoria' : 'no la marca');
                }
            }
        }
        foreach (array_keys($sdk) as $nombre) {
            if (!isset($union[$nombre]) && !isset($excepciones[$nombre])) {
                $diffs[] = "$corta::\$$nombre existe en el SDK pero no en el contrato (" . implode('/', $esquemas) . ')';
            }
        }
        foreach ($union as $nombre => $porEsquema) {
            if (!isset($sdk[$nombre])) {
                if (!isset($omisiones[$nombre])) {
                    $diffs[] = "$corta: falta \$$nombre del contrato (" . implode('/', array_keys($porEsquema)) . ')';
                }
                continue;
            }
            foreach ($porEsquema as $esquema => $info) {
                foreach ($this->compararPropiedad($corta, $sdk[$nombre], $esquema, $info) as $d) {
                    $diffs[] = $d;
                }
            }
        }
        return $diffs;
    }

    /**
     * @param array{def: array<string, mixed>, requerida: bool} $info
     * @return string[]
     */
    private function compararPropiedad(string $corta, \ReflectionProperty $p, string $esquema, array $info): array
    {
        $diffs = [];
        $def = $info['def'];
        $donde = "$corta::\${$p->getName()} vs $esquema";
        $tipo = $p->getType();
        $nombres = [];
        $admiteNull = $tipo === null || $tipo->allowsNull();
        if ($tipo instanceof \ReflectionNamedType) {
            $nombres = [$tipo->getName()];
        } elseif ($tipo instanceof \ReflectionUnionType) {
            foreach ($tipo->getTypes() as $t) {
                $nombres[] = $t->getName();
            }
        }
        $nombres = array_values(array_diff($nombres, ['null']));

        if ($def['nullable'] && !$admiteNull && !isset(self::NULABILIDAD_ACEPTADA["$corta::{$p->getName()}"])) {
            $diffs[] = "$donde: nulabilidad, el contrato admite null y el SDK no";
        }
        if ($p->hasDefaultValue() && !$admiteNull && !$info['requerida']) {
            $diffs[] = "$donde: obligatoriedad, el SDK la trata como siempre presente y el contrato la deja opcional";
        }

        $esperado = $def['kind'];
        $ok = match ($esperado) {
            'number' => $this->mismoConjunto($nombres, ['float', 'string']) || $nombres === ['float'],
            'integer' => $nombres === ['int'],
            'string' => $nombres === ['string'],
            'boolean' => $nombres === ['bool'],
            'array' => $nombres === ['array'],
            'object' => $def['ref'] === null
                ? $nombres === ['array']
                : count($nombres) === 1 && !in_array($nombres[0], ['int', 'float', 'string', 'bool', 'array'], true),
            default => false,
        };
        if (!$ok) {
            $diffs[] = "$donde: tipo, el contrato dice $esperado y el SDK " . implode('|', $nombres);
            return $diffs;
        }
        if ($esperado === 'array') {
            $elemento = $this->elementoSdk($p);
            $esperadoEl = $def['items'];
            if ($esperadoEl !== null && $esperadoEl['kind'] === 'object') {
                $claseEsperada = $this->claseDeEsquema($esperadoEl['ref']);
                if ($elemento !== $claseEsperada) {
                    $diffs[] = "$donde: elemento de la lista, el contrato dice {$esperadoEl['ref']} y el SDK " . ($elemento ?? 'sin tipo');
                }
            } elseif ($esperadoEl !== null && $elemento !== null && $elemento !== $esperadoEl['kind']) {
                $diffs[] = "$donde: elemento de la lista, el contrato dice {$esperadoEl['kind']} y el SDK $elemento";
            }
        }
        if ($esperado === 'object' && $def['ref'] !== null) {
            $claseEsperada = $this->claseDeEsquema($def['ref']);
            if ($nombres[0] !== $claseEsperada) {
                $diffs[] = "$donde: objeto anidado, el contrato dice {$def['ref']} y el SDK {$nombres[0]}";
            }
        }
        return $diffs;
    }

    /** Clase del SDK que modela un esquema anidado (la primera que lo declara). */
    private function claseDeEsquema(string $esquema): ?string
    {
        foreach (self::ESQUEMAS as $corta => $lista) {
            if (in_array($esquema, $lista, true)) {
                return $this->claseSdk($corta);
            }
        }
        return null;
    }

    /** Nombre de clase completo del elemento `@var X[]`, o el tipo escalar (`string`, `int`...). */
    private function elementoSdk(\ReflectionProperty $p): ?string
    {
        $doc = $p->getDocComment();
        if ($doc === false && $p->isPromoted()) {
            $doc = $p->getDeclaringClass()->getConstructor()?->getDocComment();
            if ($doc !== false && preg_match('/@param\s+(\S+)\[\]\s+\$' . $p->getName() . '\b/', $doc, $m) === 1) {
                return $this->normalizarElemento($m[1]);
            }
            return null;
        }
        if ($doc !== false && preg_match('/@var\s+(\S+)\[\]/', $doc, $m) === 1) {
            return $this->normalizarElemento($m[1]);
        }
        return null;
    }

    private function normalizarElemento(string $nombre): string
    {
        return match ($nombre) {
            'string' => 'string',
            'int' => 'integer',
            'float' => 'number',
            'bool' => 'boolean',
            default => str_contains($nombre, '\\') ? ltrim($nombre, '\\') : 'Ecuafact\\Sdk\\Contracts\\' . $nombre,
        };
    }

    /**
     * Normaliza un esquema de propiedad del contrato.
     *
     * @param array<string, mixed> $def
     * @return array{kind: string, nullable: bool, ref: ?string, items: ?array<string, mixed>}
     */
    private function describir(array $def): array
    {
        $nullable = false;
        if (isset($def['oneOf'])) {
            $resto = [];
            foreach ($def['oneOf'] as $alt) {
                if (($alt['type'] ?? null) === 'null') {
                    $nullable = true;
                } else {
                    $resto[] = $alt;
                }
            }
            $def = array_merge($def, $resto[0] ?? []);
            unset($def['oneOf']);
        }
        if (isset($def['$ref'])) {
            $nombre = basename($def['$ref']);
            $schema = self::$spec['components']['schemas'][$nombre] ?? [];
            if (isset($schema['enum'])) {
                $primero = $schema['enum'][0] ?? '';
                return ['kind' => is_int($primero) ? 'integer' : 'string', 'nullable' => $nullable, 'ref' => null, 'items' => null];
            }
            return ['kind' => 'object', 'nullable' => $nullable, 'ref' => $nombre, 'items' => null];
        }
        $tipo = $def['type'] ?? 'string';
        if (is_array($tipo)) {
            if (in_array('null', $tipo, true)) {
                $nullable = true;
            }
            $tipo = array_values(array_diff($tipo, ['null']))[0] ?? 'string';
        }
        $items = null;
        if ($tipo === 'array' && isset($def['items'])) {
            $items = $this->describir($def['items']);
        }
        return ['kind' => $tipo, 'nullable' => $nullable, 'ref' => null, 'items' => $items];
    }

    /**
     * @param string[] $a
     * @param string[] $b
     */
    private function mismoConjunto(array $a, array $b): bool
    {
        sort($a);
        sort($b);
        return $a === $b;
    }

    /**
     * @param mixed $valor
     * @return array<string, true>
     */
    private function clavesDe(mixed $valor): array
    {
        $claves = [];
        if (is_array($valor)) {
            foreach ($valor as $k => $v) {
                if (is_string($k)) {
                    $claves[$k] = true;
                }
                $claves += $this->clavesDe($v);
            }
        }
        return $claves;
    }

    /**
     * Las seis solicitudes con TODAS las propiedades (recursivo) llenas, para ver lo que viaja.
     *
     * @return \Ecuafact\Sdk\Contracts\SolicitudComprobante[]
     */
    private function solicitudesLlenas(): array
    {
        return array_map(
            fn (string $clase): object => $this->llenar($clase),
            [FacturaRequest::class, LiquidacionCompraRequest::class, NotaCreditoRequest::class,
                NotaDebitoRequest::class, GuiaRemisionRequest::class, RetencionRequest::class]
        );
    }

    private function llenar(string $clase): object
    {
        $ref = new \ReflectionClass($clase);
        $obj = $ref->newInstance();
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $p) {
            if ($p->isStatic() || $p->getName() === 'extra') {
                continue;
            }
            $tipo = $p->getType();
            $nombres = [];
            if ($tipo instanceof \ReflectionNamedType) {
                $nombres = [$tipo->getName()];
            } elseif ($tipo instanceof \ReflectionUnionType) {
                foreach ($tipo->getTypes() as $t) {
                    $nombres[] = $t->getName();
                }
            }
            if (in_array('array', $nombres, true)) {
                $el = $this->elementoSdk($p);
                $p->setValue($obj, $el !== null && class_exists($el) ? [$this->llenar($el)] : ['x']);
            } elseif (in_array('float', $nombres, true)) {
                $p->setValue($obj, 1.5);
            } elseif (in_array('int', $nombres, true)) {
                $p->setValue($obj, 1);
            } elseif (in_array('bool', $nombres, true)) {
                $p->setValue($obj, true);
            } elseif (in_array('string', $nombres, true)) {
                $p->setValue($obj, 'x');
            } else {
                foreach ($nombres as $n) {
                    if (class_exists($n)) {
                        $p->setValue($obj, $this->llenar($n));
                    }
                }
            }
        }
        return $obj;
    }
}
