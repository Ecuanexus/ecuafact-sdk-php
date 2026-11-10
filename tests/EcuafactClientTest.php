<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Tests;

use Ecuafact\Sdk\Contracts\Admission;
use Ecuafact\Sdk\Contracts\CodEstado;
use Ecuafact\Sdk\Contracts\ComprobanteRequest;
use Ecuafact\Sdk\Contracts\EstadoOperacion;
use Ecuafact\Sdk\Contracts\InfoTributaria;
use Ecuafact\Sdk\Contracts\Operation;
use Ecuafact\Sdk\Contracts\PerfilEmisor;
use Ecuafact\Sdk\Contracts\TipoComprobante;
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
        $comprobante->infoTributaria->tipoDocumento = '01';
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
        $creado = new EcuafactClient(new EcuafactClientOptions(baseAddress: 'https://api.example/', apiKey: '  '));
        self::fail('Se esperaba EcuafactSdkException.' . ' Se creo ' . $creado::class . '.');
    }

    public function testEmitePlanoYGeneraIdempotencyKey(): void
    {
        $transport = new FakeTransport(new TransportResponse(
            202,
            '{"estado":"recibido","codEstado":1002,"errores":[],"idOperacion":"op-1","urlEstado":"https://api.example/v1/operaciones/op-1","ambiente":1,"tipoDocumento":"01","numeroDocumento":"001-001-000000123","referenciaExterna":"FAC-ERP-4581","claveAcceso":"0911202601179001691900110010010000001231234567811","fhRecibido":"2026-11-09T10:15:00-05:00","fhAutorizacion":null}'
        ));
        $comprobante = $this->comprobante();
        $resultado = $this->cliente($transport)->emitirEn('0123456789', $comprobante);

        self::assertSame('recibido', $resultado->admission->estado);
        self::assertSame(EstadoOperacion::Recibido, $resultado->admission->estadoOperacion());
        self::assertSame('op-1', $resultado->admission->idOperacion);
        self::assertSame('https://api.example/v1/operaciones/op-1', $resultado->admission->urlEstado);
        self::assertSame(CodEstado::COMPROBANTE_ENVIADO, $resultado->admission->codEstado);
        self::assertNull($resultado->admission->msgError);
        self::assertSame([], $resultado->admission->errores);
        self::assertSame(1, $resultado->admission->ambiente);
        self::assertSame(TipoComprobante::Factura, $resultado->admission->tipoComprobante());
        self::assertSame('001-001-000000123', $resultado->admission->numeroDocumento);
        self::assertSame('FAC-ERP-4581', $resultado->admission->referenciaExterna);
        self::assertSame(49, strlen((string) $resultado->admission->claveAcceso));
        self::assertSame('2026-11-09T10:15:00-05:00', $resultado->admission->fhRecibido);
        self::assertNull($resultado->admission->fhAutorizacion);
        self::assertFalse($resultado->admission->esFinal());
        self::assertSame([], $resultado->admission->extra);
        self::assertFalse(property_exists($resultado->admission, 'uid'));
        self::assertFalse(property_exists($resultado->admission, 'codigo'));
        self::assertSame(32, strlen($resultado->idempotencyKey));

        $peticion = $transport->peticiones[0];
        self::assertSame('POST', $peticion['method']);
        self::assertStringEndsWith('/v1/contribuyentes/0123456789/facturas', $peticion['url']);
        self::assertSame('clave.secreta', $transport->header(0, 'X-Api-Key'));
        self::assertSame($resultado->idempotencyKey, $transport->header(0, 'Idempotency-Key'));
        $cuerpo = json_decode((string) $peticion['body'], true);
        self::assertArrayNotHasKey('tipoDocumento', $cuerpo['infoTributaria']);
        self::assertArrayNotHasKey('codDoc', $cuerpo['infoTributaria']);
        self::assertArrayNotHasKey('info_tributaria', $cuerpo);
        self::assertSame('01', $comprobante->infoTributaria->tipoDocumento);
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
        $transport = new FakeTransport(new TransportResponse(200, '{"idCliente":"abc","nombre":"Mi empresa"}'));
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
            '{"estado":"rechazado","codEstado":3004,"msgError":"Comprobante duplicado.","errores":["Ya registrado."],'
            . '"idOperacion":null,"urlEstado":null,"ambiente":1,"tipoDocumento":"01","numeroDocumento":null,'
            . '"referenciaExterna":"FAC-1","claveAcceso":null,"fhRecibido":null,"fhAutorizacion":null}',
            ['X-Correlation-Id' => 'corr-9']
        ));
        try {
            $this->cliente($transport)->emitirEn('0123456789', $this->comprobante());
            self::fail('Se esperaba EcuafactApiException.');
        } catch (EcuafactApiException $error) {
            self::assertSame(CodEstado::COMPROBANTE_DUPLICADO, $error->codEstado);
            self::assertSame('rechazado', $error->estado);
            self::assertSame(409, $error->status);
            self::assertSame('Comprobante duplicado.', $error->msgError);
            self::assertSame('Comprobante duplicado.', $error->getMessage());
            self::assertSame(409, $error->estadoHttp);
            self::assertSame('corr-9', $error->correlationId);
            self::assertSame(['Ya registrado.'], $error->errores);
        }
    }

    public function testReintentaTransitoriosConLaMismaKey(): void
    {
        $transport = new FakeTransport(
            new TransportResponse(503, '{"estado":"error","codEstado":5002,"msgError":"no disponible"}'),
            new TransportResponse(202, '{"estado":"recibido","idOperacion":"ok"}')
        );
        $resultado = $this->cliente($transport)->emitirEn('0123456789', $this->comprobante(), 'K-1');
        self::assertSame('ok', $resultado->admission->idOperacion);
        self::assertCount(2, $transport->peticiones);
        self::assertSame('K-1', $transport->header(0, 'Idempotency-Key'));
        self::assertSame('K-1', $transport->header(1, 'Idempotency-Key'));
    }

    public function testNoReintenta409(): void
    {
        $transport = new FakeTransport(new TransportResponse(409, '{"estado":"rechazado","codEstado":3004,"msgError":"duplicado"}'));
        try {
            $this->cliente($transport)->emitirEn('0123456789', $this->comprobante(), 'K-2');
            self::fail('Se esperaba EcuafactApiException.');
        } catch (EcuafactApiException) {
            self::assertCount(1, $transport->peticiones);
        }
    }

    public function testVistaMultiRucFijaRuc(): void
    {
        $transport = new FakeTransport(new TransportResponse(200, '{"identificacion":"1790099987001"}'));
        $perfil = $this->cliente($transport)->para('1790099987001')->getPerfil();
        self::assertSame('1790099987001', $perfil->identificacion);
        self::assertStringEndsWith(
            '/v1/contribuyentes/1790099987001/perfil',
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
            '{"idOperacion":"3f2b8c1e-7a4d-4e2b-9c11-5d6f7a8b9c0d","estado":"autorizado"}'
        ));
        $operacion = $this->cliente($transport)->consultarOperacion('3f2b8c1e-7a4d-4e2b-9c11-5d6f7a8b9c0d');
        self::assertSame('autorizado', $operacion->estado);
        self::assertSame('GET', $transport->peticiones[0]['method']);
        self::assertStringEndsWith('/v1/operaciones/3f2b8c1e-7a4d-4e2b-9c11-5d6f7a8b9c0d', $transport->peticiones[0]['url']);
        self::assertStringNotContainsString('/consultar', $transport->peticiones[0]['url']);
    }

    public function testLeeOperacionCompletaYToleraCamposNuevos(): void
    {
        $transport = new FakeTransport(new TransportResponse(
            200,
            '{"estado":"rechazado","codEstado":3001,"msgError":"El SRI rechazo el comprobante.",'
            . '"errores":["CLAVE ACCESO REGISTRADA"],"idOperacion":"op-1",'
            . '"urlEstado":"https://api.example/v1/operaciones/op-1","ambiente":1,"tipoDocumento":"01",'
            . '"numeroDocumento":"001-001-000000123","referenciaExterna":null,"claveAcceso":"0101",'
            . '"fhRecibido":"2026-11-09T10:15:00-05:00","fhAutorizacion":null,"campoNuevo":{"x":1}}'
        ));
        $operacion = $this->cliente($transport)->getOperacion('3f2b8c1e-7a4d-4e2b-9c11-5d6f7a8b9c0d');
        self::assertInstanceOf(Operation::class, $operacion);
        self::assertSame('op-1', $operacion->idOperacion);
        self::assertSame(1, $operacion->ambiente);
        self::assertSame('01', $operacion->tipoDocumento);
        self::assertSame(TipoComprobante::Factura, $operacion->tipoComprobante());
        self::assertSame('0101', $operacion->claveAcceso);
        self::assertSame(EstadoOperacion::Rechazado, $operacion->estadoOperacion());
        self::assertSame(CodEstado::RECHAZO_SRI, $operacion->codEstado);
        self::assertSame(EstadoOperacion::Rechazado, CodEstado::estado($operacion->codEstado));
        self::assertSame('El SRI rechazo el comprobante.', $operacion->msgError);
        self::assertSame(['CLAVE ACCESO REGISTRADA'], $operacion->errores);
        self::assertSame('https://api.example/v1/operaciones/op-1', $operacion->urlEstado);
        self::assertSame('001-001-000000123', $operacion->numeroDocumento);
        self::assertNull($operacion->referenciaExterna);
        self::assertSame('2026-11-09T10:15:00-05:00', $operacion->fhRecibido);
        self::assertNull($operacion->fhAutorizacion);
        self::assertFalse(property_exists($operacion, 'fechaCreacion'));
        self::assertFalse(property_exists($operacion, 'codError'));
        self::assertTrue($operacion->esFinal());
        self::assertFalse($operacion->esAutorizado());
        self::assertSame(['campoNuevo' => ['x' => 1]], $operacion->extra);
    }

    public function testAdmisionYOperacionTienenLosMismosCampos(): void
    {
        $campos = static function (string $clase): array {
            $nombres = array_map(
                static fn (\ReflectionProperty $p): string => $p->getName(),
                (new \ReflectionClass($clase))->getProperties(\ReflectionProperty::IS_PUBLIC)
            );
            sort($nombres);
            return $nombres;
        };
        self::assertSame($campos(Admission::class), $campos(Operation::class));
        self::assertSame(
            ['ambiente', 'claveAcceso', 'codEstado', 'errores', 'estado', 'extra', 'fhAutorizacion', 'fhRecibido',
                'idOperacion', 'msgError', 'numeroDocumento', 'referenciaExterna', 'tipoDocumento', 'urlEstado'],
            $campos(Admission::class)
        );
    }

    public function testLeePerfilCompletoYToleraCamposNuevos(): void
    {
        $transport = new FakeTransport(new TransportResponse(
            200,
            '{"identificacion":"0123456789001","razonSocial":"Empresa","nombreComercial":null,'
            . '"direccionMatriz":"Matriz","direccionEstablecimiento":"Local","codigoEstablecimiento":"001",'
            . '"codigoPuntoEmision":"002","moneda":"DOLAR","obligadoContabilidad":true,"correo":null,'
            . '"telefono":null,"ciudad":"Quito","provincia":"Pichincha","pais":"Ecuador","logo":null,'
            . '"campoNuevo":"x"}'
        ));
        $perfil = $this->cliente($transport)->getPerfil('0123456789001');
        self::assertInstanceOf(PerfilEmisor::class, $perfil);
        self::assertSame('Local', $perfil->direccionEstablecimiento);
        self::assertSame('001', $perfil->codigoEstablecimiento);
        self::assertSame('002', $perfil->codigoPuntoEmision);
        self::assertSame('DOLAR', $perfil->moneda);
        self::assertTrue($perfil->obligadoContabilidad);
        self::assertSame('Ecuador', $perfil->pais);
        self::assertStringEndsWith('/v1/contribuyentes/0123456789001/perfil', $transport->peticiones[0]['url']);
    }

    public function testListadoDeRecibidosRetirado(): void
    {
        self::assertFalse(method_exists(EcuafactClient::class, 'listarRecibidos'));
        self::assertFalse(method_exists(EcuafactClient::class, 'listarRecibidosEn'));
        self::assertFalse(method_exists(\Ecuafact\Sdk\EcuafactContribuyente::class, 'listarRecibidos'));
    }

    public function testListadoDeEmitidosRetirado(): void
    {
        self::assertFalse(method_exists(EcuafactClient::class, 'listarEmitidos'));
        self::assertFalse(method_exists(EcuafactClient::class, 'listarEmitidosEn'));
        self::assertFalse(method_exists(EcuafactClient::class, 'iterarEmitidos'));
        self::assertFalse(method_exists(EcuafactClient::class, 'iterarEmitidosEn'));
        self::assertFalse(method_exists(\Ecuafact\Sdk\EcuafactContribuyente::class, 'listarEmitidos'));
        self::assertFalse(method_exists(\Ecuafact\Sdk\EcuafactContribuyente::class, 'iterarEmitidos'));
        self::assertFalse(class_exists(\Ecuafact\Sdk\Contracts\PaginaComprobantes::class));
        self::assertFalse(class_exists(\Ecuafact\Sdk\Contracts\Comprobante::class));
        self::assertFalse(class_exists(\Ecuafact\Sdk\ListadoRequest::class));
    }

    public function testCatalogoYReenvioDeCorreoRetirados(): void
    {
        self::assertFalse(method_exists(EcuafactClient::class, 'enviarCorreo'));
        self::assertFalse(method_exists(EcuafactClient::class, 'listarCatalogo'));
        self::assertFalse(method_exists(\Ecuafact\Sdk\EcuafactContribuyente::class, 'enviarCorreo'));
        self::assertFalse(class_exists(\Ecuafact\Sdk\Contracts\CatalogoItem::class));
        self::assertFalse(class_exists(\Ecuafact\Sdk\Contracts\CorreoEnviado::class));
        self::assertFalse(class_exists(\Ecuafact\Sdk\Contracts\CorreoSolicitud::class));
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
            new TransportResponse(429, '{"estado":"error","codEstado":5005,"msgError":"lento"}', ['Retry-After' => '0']),
            new TransportResponse(202, '{"estado":"recibido","idOperacion":"ok-429"}')
        );
        // El 429 con codEstado 5005 (`limite_solicitudes`) se reintenta por defecto.
        $client = new EcuafactClient(new EcuafactClientOptions(
            baseAddress: 'https://api.example/',
            apiKey: 'clave.secreta',
            transport: $transport,
        ));
        $resultado = $client->emitirEn('0123456789', $this->comprobante(), 'K-429');
        self::assertSame('ok-429', $resultado->admission->idOperacion);
        self::assertCount(2, $transport->peticiones);
    }

    public function testExponeCorrelacionEnLaEmision(): void
    {
        $transport = new FakeTransport(new TransportResponse(
            202,
            '{"estado":"recibido","idOperacion":"op-corr"}',
            ['X-Correlation-Id' => 'corr-1']
        ));
        $resultado = $this->cliente($transport)->emitirEn('0123456789', $this->comprobante(), 'K-CORR');
        self::assertSame('corr-1', $resultado->correlationId);
    }

    public function testReintentaLaDescargaTransitoria(): void
    {
        $transport = new FakeTransport(
            new TransportResponse(503, '{"estado":"error","codEstado":5002,"msgError":"caido"}'),
            new TransportResponse(200, 'contenido-xml', ['Content-Type' => 'application/xml; charset=utf-8'])
        );
        $archivo = $this->cliente($transport)->descargarXml('0123456789', 'CLAVE');
        self::assertSame('contenido-xml', $archivo->contenido);
        self::assertSame('application/xml', $archivo->contentType);
        self::assertCount(2, $transport->peticiones);
    }
}
