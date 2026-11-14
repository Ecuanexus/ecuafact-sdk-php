<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Tests;

use Ecuafact\Sdk\Contracts\FacturaRequest;
use Ecuafact\Sdk\Contracts\EstadoOperacion;
use Ecuafact\Sdk\Contracts\InfoTributaria;
use Ecuafact\Sdk\Contracts\TipoComprobante;
use Ecuafact\Sdk\EcuafactAuthException;
use Ecuafact\Sdk\EcuafactClient;
use Ecuafact\Sdk\EcuafactClientOptions;
use Ecuafact\Sdk\EcuafactNotFoundException;
use Ecuafact\Sdk\EcuafactSdkException;
use Ecuafact\Sdk\EcuafactValidationException;
use Ecuafact\Sdk\Tests\Support\FakeTransport;
use Ecuafact\Sdk\Transport\TransportResponse;
use PHPUnit\Framework\TestCase;

final class OperacionesYWebhooksTest extends TestCase
{
    private const ID = '3f2b8c1e-7a4d-4e2b-9c11-5d6f7a8b9c0d';

    private function cliente(FakeTransport $transport): EcuafactClient
    {
        return new EcuafactClient(new EcuafactClientOptions(
            baseAddress: 'https://api.example/',
            apiKey: 'clave.secreta',
            transport: $transport,
            retryBaseDelaySeconds: 0.0,
            retryJitterSeconds: 0.0,
        ));
    }

    public function testListarOperacionesArmaLaConsultaYLeeLaPagina(): void
    {
        $transport = new FakeTransport(new TransportResponse(200, json_encode([
            'comprobantes' => [[
                'claveAcceso' => '0911202601179001691900110010010000001231234567811',
                'numeroDocumento' => '001-001-000000123',
                'tipoDocumento' => '01',
                'fechaEmision' => '2026-11-09',
                'identificacionContraparte' => '0999999999',
                'razonSocialContraparte' => 'Cliente SA',
                'total' => 11.5,
                'estado' => 'autorizado',
                'codEstado' => 2001,
                'errores' => [],
                'fhAutorizacion' => '2026-11-09T10:15:00-05:00',
            ]],
            'pagina' => 2,
            'tamanoPagina' => 50,
            'hayMas' => true,
        ], JSON_THROW_ON_ERROR)));
        $pagina = $this->cliente($transport)->listarOperaciones(
            '0123456789001',
            '2026-11-01',
            '2026-11-12',
            'factura',
            'FAC 2026',
            2,
            50
        );

        self::assertSame('GET', $transport->peticiones[0]['method']);
        self::assertSame(
            'https://api.example/v1/operaciones?identificacion=0123456789001&desde=2026-11-01&hasta=2026-11-12'
            . '&tipoDocumento=01&buscar=FAC%202026&pagina=2&tamanoPagina=50',
            $transport->peticiones[0]['url']
        );
        self::assertSame(2, $pagina->pagina);
        self::assertTrue($pagina->hayMas);
        self::assertCount(1, $pagina->comprobantes);
        $fila = $pagina->comprobantes[0];
        self::assertSame('001-001-000000123', $fila->numeroDocumento);
        self::assertSame(11.5, $fila->total);
        self::assertSame(2001, $fila->codEstado);
        self::assertSame(EstadoOperacion::Autorizado, $fila->estadoOperacion());
        self::assertSame(TipoComprobante::Factura, $fila->tipoComprobante());
    }

    public function testListarOperacionesValidaArgumentosSinLlamar(): void
    {
        $transport = new FakeTransport(new TransportResponse(200, '{}'));
        $cliente = $this->cliente($transport);
        foreach ([
            fn () => $cliente->listarOperaciones('abc'),
            fn () => $cliente->listarOperaciones('0123456789', null, null, null, null, 0),
            fn () => $cliente->listarOperaciones('0123456789', null, null, null, null, 1, 101),
            fn () => $cliente->listarOperaciones('0123456789', null, null, 'xx'),
        ] as $llamada) {
            try {
                $llamada();
                self::fail('Se esperaba EcuafactSdkException.');
            } catch (EcuafactSdkException) {
                self::assertSame([], $transport->peticiones);
            }
        }
    }

    public function testListarOperacionesDesdeLaVistaDeContribuyente(): void
    {
        $transport = new FakeTransport(new TransportResponse(
            200,
            '{"comprobantes":[],"pagina":1,"tamanoPagina":20,"hayMas":false}'
        ));
        $pagina = $this->cliente($transport)->para('0123456789')->listarOperaciones();
        self::assertSame(
            'https://api.example/v1/operaciones?identificacion=0123456789&pagina=1&tamanoPagina=20',
            $transport->peticiones[0]['url']
        );
        self::assertFalse($pagina->hayMas);
        self::assertSame([], $pagina->comprobantes);
    }

    public function testListarOperacionesPropagaErrores(): void
    {
        $transport = new FakeTransport(new TransportResponse(
            403,
            '{"estado":"rechazado","codEstado":3011,"msgError":"Acceso denegado.","errores":[]}'
        ));
        $this->expectException(EcuafactAuthException::class);
        $this->cliente($transport)->listarOperaciones('0123456789');
    }

    public function testObtenerSaludNoEnviaApiKey(): void
    {
        $transport = new FakeTransport(new TransportResponse(200, 'Healthy'));
        self::assertTrue($this->cliente($transport)->obtenerSalud());
        self::assertSame('https://api.example/salud', $transport->peticiones[0]['url']);
        self::assertNull($transport->header(0, 'X-Api-Key'));
    }

    public function testObtenerSaludLanzaConError(): void
    {
        $transport = new FakeTransport(new TransportResponse(503, '{"estado":"error","codEstado":5003,"errores":[]}'));
        $this->expectException(\Ecuafact\Sdk\EcuafactServerException::class);
        $this->cliente($transport)->obtenerSalud(new \Ecuafact\Sdk\RequestOptions(reintentos: false));
    }

    public function testObtenerEstadoServicioNoEnviaApiKeyYLeeTodosLosCampos(): void
    {
        $transport = new FakeTransport(new TransportResponse(200, json_encode([
            'estado' => 'degradado',
            'medidoEn' => '2026-11-13T10:00:00Z',
            'componentes' => [['nombre' => 'API', 'estado' => 'operativo'], ['nombre' => 'SRI', 'estado' => 'degradado']],
            'disponibilidad90Dias' => 99.95,
            'incidentes' => [[
                'inicio' => '2026-11-12T08:00:00Z', 'fin' => null, 'impacto' => 'degradado',
                'titulo' => 'Demora del SRI', 'detalle' => 'Las autorizaciones tardan mas.',
            ]],
        ])));
        $estado = $this->cliente($transport)->obtenerEstadoServicio();
        self::assertSame('https://api.example/estado', $transport->peticiones[0]['url']);
        self::assertNull($transport->header(0, 'X-Api-Key'));
        self::assertSame('degradado', $estado->estado);
        self::assertSame('2026-11-13T10:00:00Z', $estado->medidoEn);
        self::assertSame(99.95, $estado->disponibilidad90Dias);
        self::assertCount(2, $estado->componentes);
        self::assertSame('SRI', $estado->componentes[1]->nombre);
        self::assertSame('degradado', $estado->componentes[1]->estado);
        self::assertSame('Demora del SRI', $estado->incidentes[0]->titulo);
        self::assertSame('degradado', $estado->incidentes[0]->impacto);
        self::assertSame('2026-11-12T08:00:00Z', $estado->incidentes[0]->inicio);
        self::assertNull($estado->incidentes[0]->fin);
        self::assertSame('Las autorizaciones tardan mas.', $estado->incidentes[0]->detalle);
    }

    public function testObtenerEstadoServicioAdmiteNulos(): void
    {
        $transport = new FakeTransport(new TransportResponse(200, '{"estado":"sin_datos","medidoEn":null,"componentes":[],"disponibilidad90Dias":null,"incidentes":[]}'));
        $estado = $this->cliente($transport)->obtenerEstadoServicio();
        self::assertSame('sin_datos', $estado->estado);
        self::assertNull($estado->medidoEn);
        self::assertNull($estado->disponibilidad90Dias);
        self::assertSame([], $estado->componentes);
    }

    public function testObtenerEstadoServicioLanzaConError(): void
    {
        $transport = new FakeTransport(new TransportResponse(503, '{"estado":"error","codEstado":5003,"errores":[]}'));
        $this->expectException(\Ecuafact\Sdk\EcuafactServerException::class);
        $this->cliente($transport)->obtenerEstadoServicio(new \Ecuafact\Sdk\RequestOptions(reintentos: false));
    }

    public function testListarCatalogosLeeElIndice(): void
    {
        $transport = new FakeTransport(new TransportResponse(200, '{"catalogos":[{"nombre":"tarifas-iva","descripcion":"Tarifas de IVA"},{"nombre":"formas-pago","descripcion":"Formas de pago"}]}'));
        $indice = $this->cliente($transport)->listarCatalogos();
        self::assertSame('https://api.example/v1/catalogos', $transport->peticiones[0]['url']);
        self::assertSame('clave.secreta', $transport->header(0, 'X-Api-Key'));
        self::assertCount(2, $indice->catalogos);
        self::assertSame('tarifas-iva', $indice->catalogos[0]->nombre);
        self::assertSame('Formas de pago', $indice->catalogos[1]->descripcion);
    }

    public function testObtenerCatalogoLeeTodosLosCampos(): void
    {
        $transport = new FakeTransport(new TransportResponse(200, json_encode([
            'nombre' => 'tarifas-iva', 'descripcion' => 'Tarifas de IVA', 'fuente' => 'Tabla 17',
            'items' => [
                ['codigo' => '4', 'descripcion' => 'IVA 15%', 'valor' => 15.0, 'vigenteDesde' => '2024-04-01', 'vigenteHasta' => null, 'atributos' => ['grupo' => 'general']],
                ['codigo' => '0', 'descripcion' => 'IVA 0%', 'valor' => null, 'vigenteDesde' => null, 'vigenteHasta' => '2026-12-31', 'atributos' => null],
            ],
        ])));
        $detalle = $this->cliente($transport)->obtenerCatalogo('tarifas-iva');
        self::assertSame('https://api.example/v1/catalogos/tarifas-iva', $transport->peticiones[0]['url']);
        self::assertSame('tarifas-iva', $detalle->nombre);
        self::assertSame('Tarifas de IVA', $detalle->descripcion);
        self::assertSame('Tabla 17', $detalle->fuente);
        self::assertCount(2, $detalle->items);
        $a = $detalle->items[0];
        self::assertSame('4', $a->codigo);
        self::assertSame('IVA 15%', $a->descripcion);
        self::assertSame(15.0, $a->valor);
        self::assertSame('2024-04-01', $a->vigenteDesde);
        self::assertNull($a->vigenteHasta);
        self::assertSame(['grupo' => 'general'], $a->atributos);
        $b = $detalle->items[1];
        self::assertNull($b->valor);
        self::assertNull($b->vigenteDesde);
        self::assertSame('2026-12-31', $b->vigenteHasta);
        self::assertNull($b->atributos);
    }

    public function testObtenerCatalogoInexistenteLanzaNoEncontrado(): void
    {
        $transport = new FakeTransport(new TransportResponse(404, '{"estado":"error","codEstado":4004,"msgError":"Catalogo no encontrado","errores":[]}'));
        $this->expectException(EcuafactNotFoundException::class);
        $this->cliente($transport)->obtenerCatalogo('no-existe', new \Ecuafact\Sdk\RequestOptions(reintentos: false));
    }

    public function testObtenerCatalogoValidaYCodificaElNombre(): void
    {
        $transport = new FakeTransport(new TransportResponse(200, '{"nombre":"a b","items":[]}'));
        $cliente = $this->cliente($transport);
        $cliente->obtenerCatalogo('a b');
        self::assertSame('https://api.example/v1/catalogos/a%20b', $transport->peticiones[0]['url']);
        $this->expectException(EcuafactSdkException::class);
        $cliente->obtenerCatalogo(' ');
    }

    public function testValidarExponeAdvertencias(): void
    {
        $transport = new FakeTransport(new TransportResponse(
            200,
            '{"valido":true,"errores":[],"advertencias":["La firma del XML se ignora."]}'
        ));
        $comprobante = new FacturaRequest();
        $comprobante->infoTributaria = new InfoTributaria();
        $resultado = $this->cliente($transport)->validarEn('0123456789', $comprobante);
        self::assertTrue($resultado->valido);
        self::assertSame(['La firma del XML se ignora.'], $resultado->advertencias);
        self::assertSame([], $resultado->errores);
    }

    public function testValidarSinAdvertenciasDevuelveListaVacia(): void
    {
        $transport = new FakeTransport(new TransportResponse(200, '{"valido":true,"errores":[]}'));
        $comprobante = new FacturaRequest();
        $comprobante->infoTributaria = new InfoTributaria();
        $resultado = $this->cliente($transport)->validarEn('0123456789', $comprobante);
        self::assertSame([], $resultado->advertencias);
    }

    public function testLasSolicitudesNoTienenCamposQueElApiYaNoProcesa(): void
    {
        $fuera = [
            'unidadMedida', 'precioSinSubsidio', 'descuentoAdicional', 'valorDevolucionIva', 'tipoRegi', 'paisEfecPago',
            'aplicConvDobTrib', 'pagExtSujRetNorLeg', 'pagoRegFis', 'totalComprobantesReembolso',
            'totalBaseImponibleReembolso', 'totalImpuestoReembolso', 'reembolsos', 'dividendos', 'compraCajBanano',
            'tipoDocumento', 'reembolsos', 'compensaciones', 'maquinaFiscal', 'tipoNegociable', 'valorRetIva',
        ];
        $clases = [
            \Ecuafact\Sdk\Contracts\FacturaRequest::class, \Ecuafact\Sdk\Contracts\InfoFactura::class,
            \Ecuafact\Sdk\Contracts\InfoTributaria::class, \Ecuafact\Sdk\Contracts\Impuesto::class,
            \Ecuafact\Sdk\Contracts\DetalleCompraVenta::class, \Ecuafact\Sdk\Contracts\DetalleNotaCredito::class,
            \Ecuafact\Sdk\Contracts\DocsSustento::class, \Ecuafact\Sdk\Contracts\RetencionLinea::class,
        ];
        foreach ($clases as $clase) {
            $propiedades = array_map(
                static fn (\ReflectionProperty $p): string => $p->getName(),
                (new \ReflectionClass($clase))->getProperties()
            );
            foreach ($fuera as $campo) {
                self::assertNotContains($campo, $propiedades, $clase . '::' . $campo);
            }
        }
    }
}
