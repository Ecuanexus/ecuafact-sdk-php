<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Tests;

use Ecuafact\Sdk\Contracts\FacturaRequest;
use Ecuafact\Sdk\Contracts\InfoTributaria;
use Ecuafact\Sdk\EcuafactClient;
use Ecuafact\Sdk\EcuafactClientOptions;
use Ecuafact\Sdk\EcuafactInvalidResponseException;
use Ecuafact\Sdk\EcuafactSdkException;
use Ecuafact\Sdk\RequestOptions;
use Ecuafact\Sdk\Tests\Support\FakeTransport;
use Ecuafact\Sdk\Transport\TransportResponse;
use PHPUnit\Framework\TestCase;

/**
 * Hallazgo de la simulacion (escenario `jsoninvalido`): una emision aceptada con cuerpo ilegible lanzaba una
 * excepcion generica sin la Idempotency-Key, y el integrador no podia repetir la llamada sin duplicar.
 */
final class RespuestaInvalidaTest extends TestCase
{
    private function cliente(FakeTransport $transport): EcuafactClient
    {
        return new EcuafactClient(new EcuafactClientOptions(
            baseAddress: 'https://api.example/',
            apiKey: 'clave.secreta',
            identificacion: '0992156406001',
            transport: $transport,
            retryBaseDelaySeconds: 0.0,
            retryJitterSeconds: 0.0,
        ));
    }

    public function testEmisionConCuerpoIlegibleTraeLaClaveDeIdempotencia(): void
    {
        $transport = new FakeTransport(new TransportResponse(202, '{"idOperacion":', ['X-Correlation-Id' => 'corr-1']));
        $factura = new FacturaRequest();
        $factura->infoTributaria = new InfoTributaria();
        try {
            $this->cliente($transport)->emitir($factura, 'mi-clave-1');
            self::fail('Se esperaba EcuafactInvalidResponseException.');
        } catch (EcuafactInvalidResponseException $error) {
            self::assertInstanceOf(EcuafactSdkException::class, $error);
            self::assertSame(202, $error->status);
            self::assertSame('mi-clave-1', $error->idempotencyKey);
            self::assertSame('corr-1', $error->correlationId);
            self::assertStringContainsString('mi-clave-1', $error->getMessage());
            self::assertStringContainsString('corr-1', $error->getMessage());
            self::assertNotNull($error->getPrevious());
        }
        self::assertCount(1, $transport->peticiones, 'No debe reintentar una respuesta 2xx.');
    }

    public function testConsultaConCuerpoVacioNoMencionaLaClave(): void
    {
        $transport = new FakeTransport(new TransportResponse(200, ''));
        try {
            $this->cliente($transport)->getContexto(new RequestOptions(correlationId: 'c-9'));
            self::fail('Se esperaba EcuafactInvalidResponseException.');
        } catch (EcuafactInvalidResponseException $error) {
            self::assertNull($error->idempotencyKey);
            self::assertSame('c-9', $error->correlationId);
            self::assertStringNotContainsString('Idempotency-Key', $error->getMessage());
            self::assertStringContainsString('HTTP 200', $error->getMessage());
        }
    }
}
