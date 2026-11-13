<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Tests;

use Ecuafact\Sdk\Contracts\CodEstado;
use Ecuafact\Sdk\Contracts\FacturaRequest;
use Ecuafact\Sdk\Contracts\InfoTributaria;
use Ecuafact\Sdk\EcuafactAuthException;
use Ecuafact\Sdk\EcuafactClient;
use Ecuafact\Sdk\EcuafactClientOptions;
use Ecuafact\Sdk\EcuafactValidationException;
use Ecuafact\Sdk\RequestOptions;
use Ecuafact\Sdk\Tests\Support\FakeTransport;
use Ecuafact\Sdk\Transport\TransportInterface;
use Ecuafact\Sdk\Transport\TransportResponse;
use PHPUnit\Framework\TestCase;

final class ValidarComprobanteTest extends TestCase
{
    private const VALIDO = '{"valido":true}';
    private const INVALIDO = '{"estado":"rechazado","codEstado":3002,"msgError":"El comprobante no cumple las validaciones.","errores":["$.detalles[0].impuestos[0].valor: Valor de impuesto no coincide con base y tasa."]}';

    /** @param array<string, mixed> $extra */
    private function cliente(TransportInterface $transport, array $extra = []): EcuafactClient
    {
        return new EcuafactClient(new EcuafactClientOptions(...array_merge([
            'baseAddress' => 'https://api.example/',
            'apiKey' => 'clave-de-prueba',
            'identificacion' => '0123456789',
            'transport' => $transport,
            'retryBaseDelaySeconds' => 0.0,
            'retryJitterSeconds' => 0.0,
        ], $extra)));
    }

    private function comprobante(): FacturaRequest
    {
        $comprobante = new FacturaRequest();
        $comprobante->infoTributaria = new InfoTributaria();
        return $comprobante;
    }

    public function testComprobanteValidoDevuelveValidoTrue(): void
    {
        $transport = new FakeTransport(new TransportResponse(200, self::VALIDO, ['X-Correlation-Id' => 'corr-1']));
        $comprobante = $this->comprobante();
        $resultado = $this->cliente($transport)->validar($comprobante);

        self::assertTrue($resultado->valido);
        self::assertNull($resultado->codEstado);
        self::assertNull($resultado->msgError);
        self::assertSame([], $resultado->errores);
        self::assertSame('corr-1', $resultado->correlationId);
        self::assertSame('POST', $transport->peticiones[0]['method']);
        self::assertSame(
            'https://api.example/v1/contribuyentes/0123456789/facturas/validar',
            $transport->peticiones[0]['url']
        );
        $cuerpo = json_decode((string) $transport->peticiones[0]['body'], true);
        self::assertArrayNotHasKey('tipoDocumento', $cuerpo['infoTributaria']);
        self::assertStringNotContainsString('"codDoc"', (string) $transport->peticiones[0]['body']);
        self::assertStringStartsWith('application/json', (string) $transport->header(0, 'Content-Type'));
    }

    public function testComprobanteInvalido422DevuelveValidoFalseSinLanzar(): void
    {
        $transport = new FakeTransport(new TransportResponse(422, self::INVALIDO));
        $resultado = $this->cliente($transport)->validarEn('0999999999', $this->comprobante());

        self::assertFalse($resultado->valido);
        self::assertSame(CodEstado::COMPROBANTE_INVALIDO, $resultado->codEstado);
        self::assertSame('El comprobante no cumple las validaciones.', $resultado->msgError);
        self::assertSame(
            ['$.detalles[0].impuestos[0].valor: Valor de impuesto no coincide con base y tasa.'],
            $resultado->errores
        );
        self::assertSame(
            'https://api.example/v1/contribuyentes/0999999999/facturas/validar',
            $transport->peticiones[0]['url']
        );
        self::assertCount(1, $transport->peticiones);
    }

    public function testValido200ConCuerpoVacioOInesperado(): void
    {
        foreach (['', '{}', '{"otro":1}', '[]'] as $cuerpo) {
            $transport = new FakeTransport(new TransportResponse(200, $cuerpo));
            $resultado = $this->cliente($transport)->validar($this->comprobante());
            self::assertTrue($resultado->valido);
            self::assertNull($resultado->codEstado);
            self::assertSame([], $resultado->errores);
        }
    }

    public function testVistaDeContribuyenteValida(): void
    {
        $transport = new FakeTransport(new TransportResponse(200, self::VALIDO));
        $resultado = $this->cliente($transport, ['identificacion' => null])
            ->para('0888888888')
            ->validar($this->comprobante());

        self::assertTrue($resultado->valido);
        self::assertSame(
            'https://api.example/v1/contribuyentes/0888888888/facturas/validar',
            $transport->peticiones[0]['url']
        );
    }

    public function testOtro422LanzaValidacion(): void
    {
        $transport = new FakeTransport(new TransportResponse(422, '{"estado":"rechazado","codEstado":3003,"msgError":"Otro.","errores":[]}'));
        $this->expectException(EcuafactValidationException::class);
        $this->cliente($transport)->validar($this->comprobante());
    }

    public function testNoHabilitado403Lanza(): void
    {
        $transport = new FakeTransport(new TransportResponse(
            403,
            '{"estado":"rechazado","codEstado":3011,"msgError":"Contribuyente no habilitado.","errores":[]}'
        ));
        try {
            $this->cliente($transport)->validar($this->comprobante());
            self::fail('Se esperaba EcuafactAuthException.');
        } catch (EcuafactAuthException $exception) {
            self::assertSame(CodEstado::ACCESO_DENEGADO, $exception->codEstado);
            self::assertSame(403, $exception->estadoHttp);
            self::assertNull($exception->idempotencyKey);
        }
        self::assertCount(1, $transport->peticiones);
    }

    public function testNoEnviaIdempotencyKey(): void
    {
        $transport = new FakeTransport(new TransportResponse(200, self::VALIDO));
        $this->cliente($transport)->validar($this->comprobante(), new RequestOptions(correlationId: 'pedido-1'));

        self::assertNull($transport->header(0, 'Idempotency-Key'));
        self::assertSame('pedido-1', $transport->header(0, 'X-Correlation-Id'));
        self::assertSame('clave-de-prueba', $transport->header(0, 'X-Api-Key'));
    }

    public function testReintentaAnte503SinIdempotencyKey(): void
    {
        $transport = new FakeTransport(
            new TransportResponse(503, '{"estado":"error","codEstado":5002,"msgError":"No disponible.","errores":[]}'),
            new TransportResponse(200, self::VALIDO)
        );
        $resultado = $this->cliente($transport)->validar($this->comprobante());

        self::assertTrue($resultado->valido);
        self::assertCount(2, $transport->peticiones);
        self::assertNull($transport->header(0, 'Idempotency-Key'));
        self::assertNull($transport->header(1, 'Idempotency-Key'));
    }

    public function testSinReintentosPorLlamadaNoReintenta503(): void
    {
        $transport = new FakeTransport(
            new TransportResponse(503, '{"estado":"error","codEstado":5002,"msgError":"No disponible.","errores":[]}'),
            new TransportResponse(200, self::VALIDO)
        );
        try {
            $this->cliente($transport)->validar($this->comprobante(), new RequestOptions(reintentos: false));
            self::fail('Se esperaba una excepcion.');
        } catch (\Ecuafact\Sdk\EcuafactServerException) {
        }
        self::assertCount(1, $transport->peticiones);
    }

    public function testValidarDevuelveTalCualValidoErroresYAdvertencias(): void
    {
        $transport = new FakeTransport(new TransportResponse(
            200,
            '{"valido":false,"errores":["$.info.x: mal"],"advertencias":["La firma del XML se ignora."]}'
        ));
        $resultado = $this->cliente($transport)->validar($this->comprobante());

        self::assertFalse($resultado->valido);
        self::assertSame(['$.info.x: mal'], $resultado->errores);
        self::assertSame(['La firma del XML se ignora.'], $resultado->advertencias);
    }

    public function testValidarSiempreLlamaAlApiAunConUnCuerpoVacioODeFormaIncorrecta(): void
    {
        $tipos = [
            'facturas' => FacturaRequest::class,
            'liquidaciones-compra' => \Ecuafact\Sdk\Contracts\LiquidacionCompraRequest::class,
            'notas-credito' => \Ecuafact\Sdk\Contracts\NotaCreditoRequest::class,
            'notas-debito' => \Ecuafact\Sdk\Contracts\NotaDebitoRequest::class,
            'guias-remision' => \Ecuafact\Sdk\Contracts\GuiaRemisionRequest::class,
            'retenciones' => \Ecuafact\Sdk\Contracts\RetencionRequest::class,
        ];
        foreach ($tipos as $recurso => $clase) {
            $transport = new FakeTransport(new TransportResponse(422, self::INVALIDO));
            $comprobante = new $clase();
            $comprobante->infoTributaria = new InfoTributaria();
            $comprobante->infoTributaria->estab = '1';
            $comprobante->infoTributaria->secuencial = 'abc';

            $resultado = $this->cliente($transport)->validar($comprobante);

            self::assertFalse($resultado->valido);
            self::assertCount(1, $transport->peticiones);
            self::assertSame(
                'https://api.example/v1/contribuyentes/0123456789/' . $recurso . '/validar',
                $transport->peticiones[0]['url']
            );
            self::assertStringContainsString('"secuencial":"abc"', (string) $transport->peticiones[0]['body']);
        }
    }

    public function testEmitirNoPrevalidaYPropagaElValidationDelApi(): void
    {
        $transport = new FakeTransport(new TransportResponse(422, self::INVALIDO, ['X-Correlation-Id' => 'corr-9']));
        $comprobante = $this->comprobante();
        $comprobante->infoTributaria->estab = '1';
        $comprobante->infoTributaria->ptoEmi = 'xx';
        $comprobante->infoTributaria->secuencial = '12';

        try {
            $this->cliente($transport)->emitir($comprobante);
            self::fail('Se esperaba EcuafactValidationException.');
        } catch (EcuafactValidationException $exception) {
            self::assertSame(422, $exception->estadoHttp);
            self::assertSame(CodEstado::COMPROBANTE_INVALIDO, $exception->codEstado);
            self::assertSame(
                ['$.detalles[0].impuestos[0].valor: Valor de impuesto no coincide con base y tasa.'],
                $exception->errores
            );
        }
        self::assertCount(1, $transport->peticiones, 'El 422 no se reintenta ni se detecta localmente.');
        self::assertSame(
            'https://api.example/v1/contribuyentes/0123456789/facturas',
            $transport->peticiones[0]['url']
        );
        self::assertNotNull($transport->header(0, 'Idempotency-Key'));
    }

    private const ADMITIDO = '{"estado":"recibido","codEstado":1002,"idOperacion":"op-1"}';

    public function testPorDefectoEmitirEnviaUnaSolaPeticion(): void
    {
        $transport = new FakeTransport(new TransportResponse(202, self::ADMITIDO));
        $this->cliente($transport)->emitir($this->comprobante());

        self::assertCount(1, $transport->peticiones);
        self::assertStringEndsNotWith('/validar', $transport->peticiones[0]['url']);
    }

    public function testValidarAntesDeEmitirHace2PeticionesYExponeAdvertencias(): void
    {
        $transport = new FakeTransport(
            new TransportResponse(200, '{"valido":true,"advertencias":["Aviso 1"]}'),
            new TransportResponse(202, self::ADMITIDO)
        );
        $resultado = $this->cliente($transport, ['validarAntesDeEmitir' => true])->emitir($this->comprobante());

        self::assertCount(2, $transport->peticiones);
        self::assertStringEndsWith('/facturas/validar', $transport->peticiones[0]['url']);
        self::assertStringEndsWith('/facturas', $transport->peticiones[1]['url']);
        self::assertSame(['Aviso 1'], $resultado->advertencias);
    }

    public function testValidarAntesDeEmitirRechazadoNoEmite(): void
    {
        $transport = new FakeTransport(new TransportResponse(422, self::INVALIDO));
        try {
            $this->cliente($transport, ['validarAntesDeEmitir' => true])->emitir($this->comprobante());
            self::fail('Se esperaba EcuafactValidationException.');
        } catch (EcuafactValidationException $exception) {
            self::assertSame(422, $exception->estadoHttp);
            self::assertSame(CodEstado::COMPROBANTE_INVALIDO, $exception->codEstado);
            self::assertCount(1, $exception->errores);
        }
        self::assertCount(1, $transport->peticiones, 'No se envia la emision.');
    }

    public function testValidarAntesDeEmitirConValidoFalseEn200NoEmite(): void
    {
        $transport = new FakeTransport(new TransportResponse(200, '{"valido":false,"errores":["x"]}'));
        $this->expectException(EcuafactValidationException::class);
        try {
            $this->cliente($transport, ['validarAntesDeEmitir' => true])->emitir($this->comprobante());
        } finally {
            self::assertCount(1, $transport->peticiones);
        }
    }

    public function testOpcionPorLlamadaReemplazaAlCliente(): void
    {
        $transport = new FakeTransport(new TransportResponse(202, self::ADMITIDO));
        $this->cliente($transport, ['validarAntesDeEmitir' => true])
            ->emitir($this->comprobante(), null, new RequestOptions(validarAntes: false));
        self::assertCount(1, $transport->peticiones);

        $transport = new FakeTransport(new TransportResponse(200, self::VALIDO), new TransportResponse(202, self::ADMITIDO));
        $this->cliente($transport)->emitir($this->comprobante(), null, new RequestOptions(validarAntes: true));
        self::assertCount(2, $transport->peticiones);
    }
}
