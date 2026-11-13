<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Tests;

use Ecuafact\Sdk\Contracts\FacturaRequest;
use Ecuafact\Sdk\Contracts\EstadoOperacion;
use Ecuafact\Sdk\Contracts\InfoTributaria;
use Ecuafact\Sdk\Contracts\TipoComprobante;
use Ecuafact\Sdk\Contracts\WebhookDestinoCrearRequest;
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
    private const ENTREGA = '0a1b2c3d-1111-4222-8333-444455556666';

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

    public function testListarYObtenerDestinos(): void
    {
        $destino = [
            'publicId' => self::ID,
            'url' => 'https://miempresa.com/webhooks',
            'state' => 'activo',
            'fallosConsecutivos' => 0,
            'ultimoFallo' => null,
            'creado' => '2026-11-12T10:00:00Z',
            'eventos' => ['document.authorized'],
            'ruc' => null,
        ];
        $transport = new FakeTransport(
            new TransportResponse(200, json_encode([$destino], JSON_THROW_ON_ERROR)),
            new TransportResponse(200, json_encode($destino, JSON_THROW_ON_ERROR)),
        );
        $cliente = $this->cliente($transport);
        $lista = $cliente->listarWebhookDestinos();
        self::assertSame('https://api.example/v1/webhooks/destinos', $transport->peticiones[0]['url']);
        self::assertCount(1, $lista);
        self::assertSame(self::ID, $lista[0]->publicId);
        self::assertSame(['document.authorized'], $lista[0]->eventos);

        $uno = $cliente->getWebhookDestino(self::ID);
        self::assertSame('https://api.example/v1/webhooks/destinos/' . self::ID, $transport->peticiones[1]['url']);
        self::assertSame('activo', $uno->state);
    }

    public function testCrearDestinoEnviaCuerpoYLeeElSecreto(): void
    {
        $transport = new FakeTransport(new TransportResponse(
            201,
            '{"publicId":"' . self::ID . '","url":"https://miempresa.com/webhooks","secretoUnaVez":"whsec_abc","ruc":"0123456789001"}'
        ));
        $solicitud = new WebhookDestinoCrearRequest();
        $solicitud->url = 'https://miempresa.com/webhooks';
        $solicitud->eventos = ['document.authorized', 'document.rejected'];
        $solicitud->ruc = '0123456789001';
        $creado = $this->cliente($transport)->crearWebhookDestino($solicitud);

        self::assertSame('POST', $transport->peticiones[0]['method']);
        self::assertSame(
            '{"url":"https://miempresa.com/webhooks","eventos":["document.authorized","document.rejected"],"ruc":"0123456789001"}',
            $transport->peticiones[0]['body']
        );
        self::assertNull($transport->header(0, 'Idempotency-Key'));
        self::assertSame('whsec_abc', $creado->secretoUnaVez);
        self::assertStringNotContainsString('whsec_abc', print_r($creado, true));
    }

    public function testCrearDestinoExigeUrlYEventos(): void
    {
        $transport = new FakeTransport(new TransportResponse(201, '{}'));
        $cliente = $this->cliente($transport);
        $sinUrl = new WebhookDestinoCrearRequest();
        $sinUrl->eventos = ['document.authorized'];
        $sinEventos = new WebhookDestinoCrearRequest();
        $sinEventos->url = 'https://miempresa.com/webhooks';
        foreach ([$sinUrl, $sinEventos] as $solicitud) {
            try {
                $cliente->crearWebhookDestino($solicitud);
                self::fail('Se esperaba EcuafactSdkException.');
            } catch (EcuafactSdkException) {
                self::assertSame([], $transport->peticiones);
            }
        }
    }

    public function testAccionesSobreUnDestinoUsanVerboYRuta(): void
    {
        $transport = new FakeTransport(
            new TransportResponse(204, ''),
            new TransportResponse(200, '{"publicId":"' . self::ID . '","url":"https://x.test","secretoUnaVez":"whsec_nuevo"}'),
            new TransportResponse(202, ''),
            new TransportResponse(204, ''),
            new TransportResponse(204, ''),
            new TransportResponse(204, ''),
            new TransportResponse(202, ''),
        );
        $cliente = $this->cliente($transport);
        $cliente->actualizarWebhookEventos(self::ID, ['document.authorized', 'document.rejected']);
        $rotado = $cliente->rotarWebhookSecreto(self::ID);
        $cliente->probarWebhookDestino(self::ID);
        $cliente->pausarWebhookDestino(self::ID);
        $cliente->reanudarWebhookDestino(self::ID);
        $cliente->deshabilitarWebhookDestino(self::ID);
        $cliente->reintentarWebhookEntrega(self::ID, self::ENTREGA);

        $base = 'https://api.example/v1/webhooks/destinos/' . self::ID;
        $esperado = [
            ['PUT', $base . '/eventos'],
            ['POST', $base . '/rotar-secreto'],
            ['POST', $base . '/probar'],
            ['POST', $base . '/pausar'],
            ['POST', $base . '/reanudar'],
            ['POST', $base . '/deshabilitar'],
            ['POST', $base . '/entregas/' . self::ENTREGA . '/reintentar'],
        ];
        foreach ($esperado as $i => [$metodo, $url]) {
            self::assertSame($metodo, $transport->peticiones[$i]['method']);
            self::assertSame($url, $transport->peticiones[$i]['url']);
        }
        self::assertSame('{"eventos":["document.authorized","document.rejected"]}', $transport->peticiones[0]['body']);
        self::assertSame('whsec_nuevo', $rotado->secretoUnaVez);
    }

    public function testEntregasEIntentos(): void
    {
        $transport = new FakeTransport(
            new TransportResponse(200, json_encode([[
                'publicId' => self::ENTREGA,
                'eventId' => '9f9f9f9f-1111-4222-8333-444455556666',
                'intento' => 2,
                'estado' => 'fallida',
                'programado' => '2026-11-12T10:00:00Z',
                'enviado' => null,
                'httpStatus' => 500,
                'codigoError' => null,
                'tipoEvento' => 'document.authorized',
            ]], JSON_THROW_ON_ERROR)),
            new TransportResponse(200, json_encode([[
                'intento' => 1,
                'fecha' => '2026-11-12T10:00:00Z',
                'httpStatus' => 500,
                'codigoError' => null,
                'duracionMs' => 120,
                'resultado' => 'fallido',
            ]], JSON_THROW_ON_ERROR)),
        );
        $cliente = $this->cliente($transport);
        $entregas = $cliente->listarWebhookEntregas(self::ID);
        self::assertSame(self::ENTREGA, $entregas[0]->publicId);
        self::assertSame(500, $entregas[0]->httpStatus);
        self::assertSame('document.authorized', $entregas[0]->tipoEvento);
        $intentos = $cliente->listarWebhookIntentos(self::ID, self::ENTREGA);
        self::assertSame(
            'https://api.example/v1/webhooks/destinos/' . self::ID . '/entregas/' . self::ENTREGA . '/intentos',
            $transport->peticiones[1]['url']
        );
        self::assertSame(120, $intentos[0]->duracionMs);
    }

    public function testIdsDeWebhookDebenSerUuid(): void
    {
        $transport = new FakeTransport(new TransportResponse(204, ''));
        $cliente = $this->cliente($transport);
        foreach ([
            fn () => $cliente->getWebhookDestino('no-es-uuid'),
            fn () => $cliente->listarWebhookIntentos(self::ID, '../x'),
            fn () => $cliente->reintentarWebhookEntrega(self::ID, 'x'),
            fn () => $cliente->actualizarWebhookEventos(self::ID, []),
        ] as $llamada) {
            try {
                $llamada();
                self::fail('Se esperaba EcuafactSdkException.');
            } catch (EcuafactSdkException) {
                self::assertSame([], $transport->peticiones);
            }
        }
    }

    public function testErroresDeWebhookUsanLasExcepcionesDelContrato(): void
    {
        $casos = [
            [404, 3013, EcuafactNotFoundException::class],
            [422, 3002, EcuafactValidationException::class],
            [415, 3003, EcuafactValidationException::class],
            [413, 3006, EcuafactValidationException::class],
        ];
        foreach ($casos as [$status, $cod, $clase]) {
            $transport = new FakeTransport(new TransportResponse(
                $status,
                '{"estado":"rechazado","codEstado":' . $cod . ',"msgError":"m","errores":[]}'
            ));
            try {
                $this->cliente($transport)->pausarWebhookDestino(self::ID);
                self::fail('Se esperaba ' . $clase);
            } catch (\Ecuafact\Sdk\EcuafactApiException $error) {
                self::assertSame($clase, $error::class, 'HTTP ' . $status);
            }
        }
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
