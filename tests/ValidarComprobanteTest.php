<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Tests;

use Ecuafact\Sdk\Contracts\CodEstado;
use Ecuafact\Sdk\Contracts\ComprobanteRequest;
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

    private function comprobante(): ComprobanteRequest
    {
        $comprobante = new ComprobanteRequest();
        $comprobante->infoTributaria = new InfoTributaria();
        $comprobante->infoTributaria->tipoDocumento = '01';
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
        self::assertSame('01', $comprobante->infoTributaria->tipoDocumento);
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
}
