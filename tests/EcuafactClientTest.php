<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Tests;

use Ecuafact\Sdk\Contracts\ComprobanteRequest;
use Ecuafact\Sdk\Contracts\InfoTributaria;
use Ecuafact\Sdk\EcuafactApiException;
use Ecuafact\Sdk\EcuafactClient;
use Ecuafact\Sdk\EcuafactClientOptions;
use Ecuafact\Sdk\EcuafactSdkException;
use Ecuafact\Sdk\Tests\Support\FakeTransport;
use Ecuafact\Sdk\Transport\TransportResponse;
use PHPUnit\Framework\TestCase;

final class EcuafactClientTest extends TestCase
{
    private function comprobante(): ComprobanteRequest
    {
        $comprobante = new ComprobanteRequest();
        $comprobante->origenReferencia = 'MiERP';
        $comprobante->referenciaExterna = 'F-1';
        $comprobante->infoTributaria = new InfoTributaria();
        $comprobante->infoTributaria->ruc = '0123456789001';
        $comprobante->infoTributaria->codDoc = '01';
        $comprobante->infoTributaria->estab = '002';
        $comprobante->infoTributaria->ptoEmi = '001';
        $comprobante->infoTributaria->secuencial = '000000123';
        return $comprobante;
    }

    private function cliente(FakeTransport $transport): EcuafactClient
    {
        return new EcuafactClient(new EcuafactClientOptions(
            baseAddress: 'https://api.example/',
            apiKey: 'clave.secreta',
            transport: $transport,
        ));
    }

    public function testExigeCredencial(): void
    {
        $this->expectException(EcuafactSdkException::class);
        new EcuafactClient(new EcuafactClientOptions(baseAddress: 'https://api.example/', apiKey: '  '));
    }

    public function testEmitePlanoYGeneraIdempotencyKey(): void
    {
        $transport = new FakeTransport(new TransportResponse(
            202,
            '{"codigo":"200","mensaje":"Operacion exitosa.","idOperacion":"op-1","urlEstado":"/x","uid":"u"}'
        ));
        $resultado = $this->cliente($transport)->emitirEn('0123456789', $this->comprobante());

        self::assertSame('op-1', $resultado->admission->idOperacion);
        self::assertSame(32, strlen($resultado->idempotencyKey));

        $peticion = $transport->peticiones[0];
        self::assertSame('POST', $peticion['method']);
        self::assertStringEndsWith('/v1/contribuyentes/0123456789/comprobantes', $peticion['url']);
        self::assertSame('clave.secreta', $transport->header(0, 'X-Api-Key'));
        self::assertSame($resultado->idempotencyKey, $transport->header(0, 'Idempotency-Key'));
        $cuerpo = json_decode((string) $peticion['body'], true);
        self::assertSame('01', $cuerpo['infoTributaria']['codDoc']);
        self::assertArrayNotHasKey('info_tributaria', $cuerpo);
    }

    public function testUsaLaKeyProvista(): void
    {
        $transport = new FakeTransport(new TransportResponse(202, '{"idOperacion":"x"}'));
        $resultado = $this->cliente($transport)->emitirEn('0123456789', $this->comprobante(), 'FACTURA-2026-0001');
        self::assertSame('FACTURA-2026-0001', $resultado->idempotencyKey);
        self::assertSame('FACTURA-2026-0001', $transport->header(0, 'Idempotency-Key'));
    }

    public function testRechazaKeyInvalida(): void
    {
        $client = $this->cliente(new FakeTransport(new TransportResponse(202, '{"idOperacion":"x"}')));
        $this->expectException(EcuafactSdkException::class);
        $client->emitirEn('0123456789', $this->comprobante(), str_repeat('a', 129));
    }

    public function testRechazaKeyNoAscii(): void
    {
        $client = $this->cliente(new FakeTransport(new TransportResponse(202, '{"idOperacion":"x"}')));
        $this->expectException(EcuafactSdkException::class);
        $client->emitirEn('0123456789', $this->comprobante(), 'acentuada-ñ');
    }

    public function testDesenvuelveDatosEnContexto(): void
    {
        $transport = new FakeTransport(new TransportResponse(200, '{"datos":{"idCliente":"abc","nombre":"Mi empresa"}}'));
        self::assertSame('abc', $this->cliente($transport)->getContexto()->idCliente);
    }

    public function testLeeConsumoPlanoYQuery(): void
    {
        $transport = new FakeTransport(new TransportResponse(
            200,
            '{"limiteDocumentos":20,"reservados":0,"consumidos":0,"disponibles":20,"vigenteHasta":"2026-12-31",'
            . '"limiteExtra":0,"reservadosExtra":0,"consumidosExtra":0,"disponiblesExtra":0}'
        ));
        $cupo = $this->cliente($transport)->getConsumo();
        self::assertSame(20, $cupo->limiteDocumentos);
        self::assertStringEndsWith('/v1/consumo', $transport->peticiones[0]['url']);
    }

    public function testPropagaErrorConCodigoYCorrelacion(): void
    {
        $transport = new FakeTransport(new TransportResponse(
            409,
            '{"codigo":"401","mensaje":"Comprobante duplicado.","errores":["Ya registrado."]}',
            ['X-Correlation-Id' => 'corr-9']
        ));
        try {
            $this->cliente($transport)->emitirEn('0123456789', $this->comprobante());
            self::fail('Se esperaba EcuafactApiException.');
        } catch (EcuafactApiException $error) {
            self::assertSame('401', $error->codigo);
            self::assertSame(409, $error->estadoHttp);
            self::assertSame('corr-9', $error->idSeguimiento);
            self::assertSame(['Ya registrado.'], $error->errores);
        }
    }

    public function testReintentaTransitoriosConLaMismaKey(): void
    {
        $transport = new FakeTransport(
            new TransportResponse(503, '{"codigo":"002","mensaje":"no disponible"}'),
            new TransportResponse(202, '{"codigo":"200","idOperacion":"ok"}')
        );
        $resultado = $this->cliente($transport)->emitirEn('0123456789', $this->comprobante(), 'K-1');
        self::assertSame('ok', $resultado->admission->idOperacion);
        self::assertCount(2, $transport->peticiones);
        self::assertSame('K-1', $transport->header(0, 'Idempotency-Key'));
        self::assertSame('K-1', $transport->header(1, 'Idempotency-Key'));
    }

    public function testNoReintenta409(): void
    {
        $transport = new FakeTransport(new TransportResponse(409, '{"codigo":"401","mensaje":"duplicado"}'));
        try {
            $this->cliente($transport)->emitirEn('0123456789', $this->comprobante(), 'K-2');
            self::fail('Se esperaba EcuafactApiException.');
        } catch (EcuafactApiException) {
            self::assertCount(1, $transport->peticiones);
        }
    }

    public function testVistaMultiRucFijaRuc(): void
    {
        $transport = new FakeTransport(new TransportResponse(
            200,
            '{"datos":{"comprobantes":[],"pagina":1,"tamanoPagina":20,"hayMas":false}}'
        ));
        $this->cliente($transport)->para('1790099987001')->listarEmitidos();
        self::assertStringEndsWith(
            '/v1/contribuyentes/1790099987001/comprobantes/emitidos',
            $transport->peticiones[0]['url']
        );
    }

    public function testConsultaContribuyentePlanoYQuery(): void
    {
        $transport = new FakeTransport(new TransportResponse(
            200,
            '{"identificacion":"1760013210001","tipoIdentificacion":"RucSociedadPublica"}'
        ));
        $contribuyente = $this->cliente($transport)->consultarContribuyente('1760013210001', true, 'Todas');
        self::assertSame('RucSociedadPublica', $contribuyente->tipoIdentificacion);
        self::assertStringEndsWith(
            '/v1/consultas/contribuyentes/1760013210001?fusionar=true&fuente=Todas',
            $transport->peticiones[0]['url']
        );
    }

    public function testBuscaContribuyentesDevuelveLista(): void
    {
        $transport = new FakeTransport(new TransportResponse(
            200,
            '[{"identificacion":"0912345678","nombreCompleto":"PEREZ JUAN"}]'
        ));
        $encontrados = $this->cliente($transport)->buscarContribuyentes('PEREZ LOPEZ', 'JUAN', 'PersonaNatural', 10);
        self::assertCount(1, $encontrados);
        self::assertSame('0912345678', $encontrados[0]->identificacion);
        $url = $transport->peticiones[0]['url'];
        self::assertStringContainsString('apellidos=PEREZ%20LOPEZ', $url);
        self::assertStringContainsString('nombres=JUAN', $url);
        self::assertStringContainsString('clase=PersonaNatural', $url);
        self::assertStringContainsString('max=10', $url);
    }

    public function testListaEstablecimientosDeUnRuc(): void
    {
        $transport = new FakeTransport(new TransportResponse(
            200,
            '{"ruc":"1760013210001","filtro":"SoloActivos","cantidad":72}'
        ));
        $establecimientos = $this->cliente($transport)->listarEstablecimientos('1760013210001', 'SoloActivos');
        self::assertSame(72, $establecimientos->cantidad);
        self::assertStringEndsWith(
            '/v1/consultas/establecimientos/1760013210001?filtro=SoloActivos',
            $transport->peticiones[0]['url']
        );
    }

    public function testValidaIdentificacion(): void
    {
        $transport = new FakeTransport(new TransportResponse(
            200,
            '{"valido":true,"tipo":"RucSociedadPublica","longitud":13}'
        ));
        $resultado = $this->cliente($transport)->validarIdentificacion('1760013210001');
        self::assertTrue($resultado->valido);
        self::assertStringEndsWith(
            '/v1/consultas/identificacion/validar/1760013210001',
            $transport->peticiones[0]['url']
        );
    }

    public function testDecodificaClaveDeAcceso(): void
    {
        $transport = new FakeTransport(new TransportResponse(
            200,
            '{"valido":true,"tipo":"Factura","digitoVerificador":"9"}'
        ));
        $clave = $this->cliente($transport)->decodificarClaveAcceso('3009202601176001321000110010010000000010000000119');
        self::assertSame('Factura', $clave->tipo);
        self::assertSame('9', $clave->digitoVerificador);
        self::assertStringContainsString(
            '/v1/consultas/comprobantes/decodificar/3009202601',
            $transport->peticiones[0]['url']
        );
    }

    public function testConsultarOperacionUsaGet(): void
    {
        $transport = new FakeTransport(new TransportResponse(
            200,
            '{"datos":{"idOperacion":"op-1","estado":"authorized"}}'
        ));
        $operacion = $this->cliente($transport)->consultarOperacion('op-1');
        self::assertSame('authorized', $operacion->estado);
        self::assertSame('GET', $transport->peticiones[0]['method']);
        self::assertStringEndsWith('/v1/operaciones/op-1', $transport->peticiones[0]['url']);
        self::assertStringNotContainsString('/consultar', $transport->peticiones[0]['url']);
    }

    public function testEnviarCorreo(): void
    {
        $transport = new FakeTransport(new TransportResponse(
            200,
            '{"datos":{"claveAcceso":"CLAVE","destinatario":"avera@ecuanexus.com"}}'
        ));
        $enviado = $this->cliente($transport)->enviarCorreo('0921357232', 'CLAVE', 'avera@ecuanexus.com');
        self::assertSame('avera@ecuanexus.com', $enviado->destinatario);
        self::assertSame('POST', $transport->peticiones[0]['method']);
        self::assertStringEndsWith('/correo', $transport->peticiones[0]['url']);
        self::assertStringContainsString('avera@ecuanexus.com', (string) $transport->peticiones[0]['body']);
    }

    public function testListarCatalogo(): void
    {
        $transport = new FakeTransport(new TransportResponse(
            200,
            '{"datos":[{"codigo":"4","nombre":"IVA 15%","tarifa":15}]}'
        ));
        $filas = $this->cliente($transport)->listarCatalogo('tarifas-iva');
        self::assertSame('4', $filas[0]->codigo);
        self::assertSame('GET', $transport->peticiones[0]['method']);
        self::assertStringEndsWith('/v1/catalogos/tarifas-iva', $transport->peticiones[0]['url']);
    }

    public function testValidaArgumentosDeConsultasSri(): void
    {
        $client = $this->cliente(new FakeTransport(new TransportResponse(200, '{}')));
        $casos = [
            [static fn () => $client->consultarContribuyente(' ')],
            [static fn () => $client->buscarContribuyentes('')],
            [static fn () => $client->listarEstablecimientos('')],
            [static fn () => $client->validarIdentificacion('')],
            [static fn () => $client->decodificarClaveAcceso('')],
        ];
        foreach ($casos as [$caso]) {
            try {
                $caso();
                self::fail('Se esperaba EcuafactSdkException.');
            } catch (EcuafactSdkException) {
                self::assertTrue(true);
            }
        }
    }

    public function testReintenta429YLeeRetryAfter(): void
    {
        $transport = new FakeTransport(
            new TransportResponse(429, '{"codigo":"rate_limited","mensaje":"lento"}', ['Retry-After' => '0']),
            new TransportResponse(202, '{"codigo":"200","idOperacion":"ok-429"}')
        );
        $resultado = $this->cliente($transport)->emitirEn('0123456789', $this->comprobante(), 'K-429');
        self::assertSame('ok-429', $resultado->admission->idOperacion);
        self::assertCount(2, $transport->peticiones);
    }

    public function testExponeCorrelacionEnLaEmision(): void
    {
        $transport = new FakeTransport(new TransportResponse(
            202,
            '{"codigo":"200","idOperacion":"op-corr"}',
            ['X-Correlation-Id' => 'corr-1']
        ));
        $resultado = $this->cliente($transport)->emitirEn('0123456789', $this->comprobante(), 'K-CORR');
        self::assertSame('corr-1', $resultado->correlationId);
    }

    public function testNoReintentaElCorreoSinKey(): void
    {
        $transport = new FakeTransport(new TransportResponse(503, '{"codigo":"002","mensaje":"caido"}'));
        try {
            $this->cliente($transport)->enviarCorreo('0921357232', 'CLAVE', 'a@b.com');
            self::fail('Se esperaba EcuafactApiException.');
        } catch (EcuafactApiException) {
            self::assertCount(1, $transport->peticiones);
        }
    }

    public function testReintentaLaDescargaTransitoria(): void
    {
        $transport = new FakeTransport(
            new TransportResponse(503, '{"codigo":"002","mensaje":"caido"}'),
            new TransportResponse(200, 'contenido-xml', ['Content-Type' => 'application/xml; charset=utf-8'])
        );
        $archivo = $this->cliente($transport)->descargarXml('0123456789', 'CLAVE');
        self::assertSame('contenido-xml', $archivo->contenido);
        self::assertSame('application/xml', $archivo->contentType);
        self::assertCount(2, $transport->peticiones);
    }
}
