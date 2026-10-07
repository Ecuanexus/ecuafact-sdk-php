<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Tests;

use Ecuafact\Sdk\Contracts\ComprobanteRequest;
use Ecuafact\Sdk\Contracts\InfoTributaria;
use Ecuafact\Sdk\EcuafactClient;
use Ecuafact\Sdk\EcuafactClientOptions;
use Ecuafact\Sdk\EcuafactConfigurationException;
use Ecuafact\Sdk\EcuafactConnectionException;
use Ecuafact\Sdk\EcuafactNotFoundException;
use Ecuafact\Sdk\EcuafactServerException;
use Ecuafact\Sdk\EcuafactTimeoutException;
use Ecuafact\Sdk\RequestOptions;
use Ecuafact\Sdk\Tests\Support\FakeTransport;
use Ecuafact\Sdk\Transport\CurlTransport;
use Ecuafact\Sdk\Transport\TimeoutAwareTransportInterface;
use Ecuafact\Sdk\Transport\TimeoutOverrideTransportInterface;
use Ecuafact\Sdk\Transport\TransportInterface;
use Ecuafact\Sdk\Transport\TransportResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OpcionesPorLlamadaTest extends TestCase
{
    private const UUID = '3f2b8c1e-7a4d-4e2b-9c11-5d6f7a8b9c0d';

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
        $comprobante->infoTributaria->codDoc = '01';
        return $comprobante;
    }

    public function testCorrelationIdSeEnviaYSeDevuelveEnLaEmision(): void
    {
        $transport = new FakeTransport(new TransportResponse(202, '{"idOperacion":"op"}', ['X-Correlation-Id' => 'erp-123']));
        $resultado = $this->cliente($transport)->emitir(
            $this->comprobante(),
            'K-1',
            new RequestOptions(correlationId: 'erp-123')
        );
        self::assertSame('erp-123', $transport->header(0, 'X-Correlation-Id'));
        self::assertSame('erp-123', $resultado->correlationId);
    }

    public function testCorrelationIdEnviadoSeUsaSiLaRespuestaNoLaTrae(): void
    {
        $transport = new FakeTransport(new TransportResponse(202, '{"idOperacion":"op"}'));
        $resultado = $this->cliente($transport)->emitirEn(
            '0123456789',
            $this->comprobante(),
            null,
            new RequestOptions(correlationId: 'pedido.42_a-b')
        );
        self::assertSame('pedido.42_a-b', $resultado->correlationId);
    }

    public function testSinOpcionesNoSeEnviaCorrelationId(): void
    {
        $transport = new FakeTransport(new TransportResponse(200, '{"datos":{"idCliente":"c"}}'));
        $this->cliente($transport)->getContexto();
        self::assertNull($transport->header(0, 'X-Correlation-Id'));
    }

    public function testCorrelationIdEnLaExcepcionDelApi(): void
    {
        $transport = new FakeTransport(new TransportResponse(404, '{"codigo":"404","mensaje":"No existe."}'));
        try {
            $this->cliente($transport)->getOperacion(self::UUID, new RequestOptions(correlationId: 'corr-404'));
            self::fail('Se esperaba EcuafactNotFoundException.');
        } catch (EcuafactNotFoundException $error) {
            self::assertSame('corr-404', $error->idSeguimiento);
            self::assertSame('corr-404', $transport->header(0, 'X-Correlation-Id'));
        }
    }

    public function testCorrelationIdEnLasExcepcionesDeConexionYTimeout(): void
    {
        $transport = new class implements TransportInterface {
            public function request(string $method, string $url, array $headers, ?string $body): TransportResponse
            {
                throw new EcuafactTimeoutException('Se agoto el tiempo.', 28);
            }
        };
        try {
            $this->cliente($transport, ['maxAttempts' => 1])->getContexto(new RequestOptions(correlationId: 'corr-t'));
            self::fail('Se esperaba EcuafactTimeoutException.');
        } catch (EcuafactTimeoutException $error) {
            self::assertSame('corr-t', $error->correlationId);
        }

        $caido = new class implements TransportInterface {
            public function request(string $method, string $url, array $headers, ?string $body): TransportResponse
            {
                throw new \RuntimeException('sin red');
            }
        };
        try {
            $this->cliente($caido, ['maxAttempts' => 1])->getConsumo(new RequestOptions(correlationId: 'corr-c'));
            self::fail('Se esperaba EcuafactConnectionException.');
        } catch (EcuafactConnectionException $error) {
            self::assertSame('corr-c', $error->correlationId);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function correlacionesInvalidas(): iterable
    {
        yield 'vacia' => [''];
        yield 'espacio' => ['con espacio'];
        yield 'barra' => ['a/b'];
        yield 'salto' => ["a\nb"];
        yield '65 caracteres' => [str_repeat('a', 65)];
    }

    #[DataProvider('correlacionesInvalidas')]
    public function testCorrelationIdInvalidoLanzaConfiguracion(string $valor): void
    {
        $this->expectException(EcuafactConfigurationException::class);
        new RequestOptions(correlationId: $valor);
    }

    public function testCorrelationIdDe64CaracteresEsValido(): void
    {
        self::assertSame(64, strlen((string) (new RequestOptions(correlationId: str_repeat('A', 64)))->correlationId));
    }

    public function testTimeoutsInvalidosLanzanConfiguracion(): void
    {
        foreach ([[0.0, null], [-1.0, null], [INF, null], [null, 0.0], [null, NAN]] as [$timeout, $total]) {
            try {
                new RequestOptions(timeout: $timeout, totalTimeout: $total);
                self::fail('Se esperaba EcuafactConfigurationException.');
            } catch (EcuafactConfigurationException) {
                self::assertTrue(true);
            }
        }
    }

    public function testReintentosFalseDesactivaLosReintentosSoloEnEsaLlamada(): void
    {
        $transport = new FakeTransport(
            new TransportResponse(503, '{"codigo":"002","mensaje":"No disponible."}'),
            new TransportResponse(503, '{"codigo":"002","mensaje":"No disponible."}'),
            new TransportResponse(200, '{"datos":{"idCliente":"c"}}')
        );
        $cliente = $this->cliente($transport);
        try {
            $cliente->getContexto(new RequestOptions(reintentos: false));
            self::fail('Se esperaba EcuafactServerException.');
        } catch (EcuafactServerException) {
            self::assertCount(1, $transport->peticiones);
        }
        // La siguiente llamada sin opciones conserva la politica del cliente.
        $cliente->getContexto();
        self::assertCount(3, $transport->peticiones);
    }

    public function testTimeoutPorLlamadaReemplazaAlDelCliente(): void
    {
        $transport = new RegistroTimeoutTransport();
        $cliente = $this->cliente($transport, ['timeout' => 5.0]);
        $cliente->getContexto(new RequestOptions(timeout: 42.5));
        self::assertSame([['override', 42.5]], $transport->llamadas);

        $cliente->getContexto();
        self::assertSame(['request', null], $transport->llamadas[1]);
    }

    public function testTotalTimeoutPorLlamadaAcotaElIntento(): void
    {
        $transport = new RegistroTimeoutTransport();
        $cliente = $this->cliente($transport);
        $cliente->getContexto(new RequestOptions(totalTimeout: 0.5));
        self::assertSame('aware', $transport->llamadas[0][0]);
        self::assertLessThanOrEqual(0.5, $transport->llamadas[0][1]);
        self::assertGreaterThan(0.0, $transport->llamadas[0][1]);

        // Con timeout y totalTimeout gana el menor.
        $cliente->getContexto(new RequestOptions(timeout: 30.0, totalTimeout: 0.5));
        self::assertSame('override', $transport->llamadas[1][0]);
        self::assertLessThanOrEqual(0.5, $transport->llamadas[1][1]);
    }

    public function testTotalTimeoutPorLlamadaCortaLosReintentos(): void
    {
        $transport = new FakeTransport(
            new TransportResponse(503, '{"codigo":"002","mensaje":"No disponible."}'),
            new TransportResponse(200, '{"datos":{"idCliente":"c"}}')
        );
        $cliente = $this->cliente($transport, ['retryBaseDelaySeconds' => 1.0, 'maxAttempts' => 3]);
        try {
            $cliente->getContexto(new RequestOptions(totalTimeout: 0.2));
            self::fail('Se esperaba EcuafactServerException.');
        } catch (EcuafactServerException) {
            // El reintento (1 s) no cabe en el tope de 0.2 s: se devuelve el 503.
            self::assertCount(1, $transport->peticiones);
        }
    }

    public function testVistaPorContribuyenteYHelpersAceptanOpciones(): void
    {
        $operacion = '{"datos":{"idOperacion":"' . self::UUID . '","estado":"enviado","estadoAutorizacion":"autorizado"}}';
        $transport = new FakeTransport(
            new TransportResponse(200, '{"datos":{"comprobantes":[{"claveAcceso":"1"}],"hayMas":false}}'),
            new TransportResponse(200, $operacion),
            new TransportResponse(200, 'PDF', ['Content-Type' => 'application/pdf']),
            new TransportResponse(200, '{"datos":{"identificacion":"0123456789"}}')
        );
        $vista = $this->cliente($transport)->para('0123456789');
        $opciones = new RequestOptions(correlationId: 'vista-1');
        iterator_to_array($vista->iterarEmitidos(null, $opciones));
        $vista->esperarResultado(self::UUID, 0.0, 1.0, $opciones);
        $vista->descargarRide('clave', $opciones);
        $vista->getPerfil($opciones);
        self::assertCount(4, $transport->peticiones);
        for ($i = 0; $i < 4; $i++) {
            self::assertSame('vista-1', $transport->header($i, 'X-Correlation-Id'));
        }
    }

    public function testCurlTransportAplicaElTimeoutDeLaLlamada(): void
    {
        $transport = new CurlTransport(timeout: 5.0);
        self::assertInstanceOf(TimeoutOverrideTransportInterface::class, $transport);
        self::assertSame(42500, $transport->curlOptions(42.5)[CURLOPT_TIMEOUT_MS]);
        $this->expectException(EcuafactConfigurationException::class);
        $transport->requestWithAttemptTimeout('GET', 'http://127.0.0.1:1/', [], null, 0.0);
    }
}

/** Transporte que registra que metodo y que timeout uso el cliente. */
final class RegistroTimeoutTransport implements TimeoutOverrideTransportInterface
{
    /** @var array<int, array{0: string, 1: ?float}> */
    public array $llamadas = [];

    public function request(string $method, string $url, array $headers, ?string $body): TransportResponse
    {
        $this->llamadas[] = ['request', null];
        return $this->ok();
    }

    public function requestWithTimeout(string $method, string $url, array $headers, ?string $body, float $timeoutSeconds): TransportResponse
    {
        $this->llamadas[] = ['aware', $timeoutSeconds];
        return $this->ok();
    }

    public function requestWithAttemptTimeout(string $method, string $url, array $headers, ?string $body, float $timeoutSeconds): TransportResponse
    {
        $this->llamadas[] = ['override', $timeoutSeconds];
        return $this->ok();
    }

    private function ok(): TransportResponse
    {
        return new TransportResponse(200, '{"datos":{"idCliente":"c"}}');
    }
}
