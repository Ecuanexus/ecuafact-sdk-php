<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Tests;

use Ecuafact\Sdk\Contracts\ComprobanteRequest;
use Ecuafact\Sdk\Contracts\InfoTributaria;
use Ecuafact\Sdk\Contracts\Operation;
use Ecuafact\Sdk\EcuafactClient;
use Ecuafact\Sdk\EcuafactClientOptions;
use Ecuafact\Sdk\EcuafactConfigurationException;
use Ecuafact\Sdk\EcuafactConnectionException;
use Ecuafact\Sdk\EcuafactTimeoutException;
use Ecuafact\Sdk\ListadoRequest;
use Ecuafact\Sdk\RequestOptions;
use Ecuafact\Sdk\Support\Hydrator;
use Ecuafact\Sdk\Support\Serialization;
use Ecuafact\Sdk\Tests\Support\FakeTransport;
use Ecuafact\Sdk\Transport\Psr18Transport;
use Ecuafact\Sdk\Transport\TransportInterface;
use Ecuafact\Sdk\Transport\TransportResponse;
use PHPUnit\Framework\TestCase;

/** Timeout por defecto, cabecera X-Additional-Info, deteccion de timeout PSR-18 y campos `extra`. */
final class CabeceraTimeoutYModelosTest extends TestCase
{
    private const UUID = '3f2b8c1e-7a4d-4e2b-9c11-5d6f7a8b9c0d';

    /** @param array<string, mixed> $extra */
    private function opciones(array $extra = []): EcuafactClientOptions
    {
        return new EcuafactClientOptions(...array_merge([
            'baseAddress' => 'https://api.example/',
            'apiKey' => 'clave-de-prueba',
            'identificacion' => '0123456789',
            'retryBaseDelaySeconds' => 0.0,
            'retryJitterSeconds' => 0.0,
        ], $extra));
    }

    /** @param array<string, mixed> $extra */
    private function cliente(TransportInterface $transport, array $extra = []): EcuafactClient
    {
        return new EcuafactClient($this->opciones(['transport' => $transport] + $extra));
    }

    // ---------------------------------------------------------------- timeout por defecto

    public function testTimeoutPorDefectoEs30Segundos(): void
    {
        self::assertSame(30.0, $this->opciones()->timeout);
        self::assertSame(12.5, $this->opciones(['timeout' => 12.5])->timeout);
        self::assertSame(5.0, (new RequestOptions(timeout: 5.0))->timeout);
    }

    // ---------------------------------------------------------------- X-Additional-Info

    public function testSinAdditionalInfoNoSeEnviaLaCabecera(): void
    {
        $transport = new FakeTransport(new TransportResponse(200, '{"datos":{"idCliente":"c"}}'));
        $this->cliente($transport)->getContexto();
        self::assertNull($transport->header(0, 'X-Additional-Info'));

        $transport = new FakeTransport(new TransportResponse(200, '{"datos":{"idCliente":"c"}}'));
        $this->cliente($transport, ['additionalInfo' => ''])->getContexto(new RequestOptions(additionalInfo: ''));
        self::assertNull($transport->header(0, 'X-Additional-Info'));
    }

    public function testAdditionalInfoDelClienteSeEnviaEnTodasLasSolicitudes(): void
    {
        $operacion = '{"datos":{"idOperacion":"' . self::UUID . '","estado":"enviado","estadoAutorizacion":"autorizado"}}';
        $transport = new FakeTransport(
            new TransportResponse(202, '{"idOperacion":"' . self::UUID . '"}'),
            new TransportResponse(200, $operacion),
            new TransportResponse(200, '{"datos":{"comprobantes":[],"pagina":1,"tamanoPagina":50,"hayMas":false}}'),
            new TransportResponse(200, '%PDF', ['Content-Type' => 'application/pdf']),
        );
        $client = $this->cliente($transport, ['additionalInfo' => 'erp=SAP; sucursal=Quito']);

        $comprobante = new ComprobanteRequest();
        $comprobante->infoTributaria = new InfoTributaria();
        $comprobante->infoTributaria->codDoc = '01';
        $client->para('0123456789')->emitir($comprobante, 'K-1');
        $client->esperarResultado(self::UUID, 0.0, 1.0);
        iterator_to_array($client->iterarEmitidos(new ListadoRequest()));
        $client->descargarRide('0123456789', str_repeat('1', 49));

        self::assertCount(4, $transport->peticiones);
        foreach (array_keys($transport->peticiones) as $indice) {
            self::assertSame('erp=SAP; sucursal=Quito', $transport->header($indice, 'X-Additional-Info'));
        }
    }

    public function testAdditionalInfoPorLlamadaReemplazaAlDelCliente(): void
    {
        $transport = new FakeTransport(new TransportResponse(200, '{"datos":{"idCliente":"c"}}'));
        $client = $this->cliente($transport, ['additionalInfo' => 'cliente']);
        $client->getContexto(new RequestOptions(additionalInfo: 'llamada-1'));
        $client->getContexto();
        self::assertSame('llamada-1', $transport->header(0, 'X-Additional-Info'));
        self::assertSame('cliente', $transport->header(1, 'X-Additional-Info'));
    }

    public function testAdditionalInfoVacioEnLlamadaNoEnviaYNullUsaElDelCliente(): void
    {
        $transport = new FakeTransport(new TransportResponse(200, '{"datos":{"idCliente":"c"}}'));
        $client = $this->cliente($transport, ['additionalInfo' => 'cliente']);
        $client->getContexto(new RequestOptions(additionalInfo: ''));
        $client->getContexto(new RequestOptions(additionalInfo: null));
        self::assertNull($transport->header(0, 'X-Additional-Info'));
        self::assertSame('cliente', $transport->header(1, 'X-Additional-Info'));
    }

    public function testAdditionalInfoInvalidoLanzaConfiguracion(): void
    {
        $invalidos = [' inicio', 'fin ', "salto\nlinea", 'tilde-cami' . "\u{00F3}" . 'n', str_repeat('a', 257), "tab\tx"];
        foreach ($invalidos as $valor) {
            try {
                $this->opciones(['additionalInfo' => $valor]);
                self::fail('Se esperaba EcuafactConfigurationException para el cliente: ' . json_encode($valor));
            } catch (EcuafactConfigurationException $error) {
                self::assertStringContainsString('X-Additional-Info', $error->getMessage());
            }
            try {
                new RequestOptions(additionalInfo: $valor);
                self::fail('Se esperaba EcuafactConfigurationException por llamada: ' . json_encode($valor));
            } catch (EcuafactConfigurationException $error) {
                self::assertStringContainsString('X-Additional-Info', $error->getMessage());
            }
        }
        self::assertSame(str_repeat('a', 256), $this->opciones(['additionalInfo' => str_repeat('a', 256)])->additionalInfo);
        self::assertSame('a b ~!', (new RequestOptions(additionalInfo: 'a b ~!'))->additionalInfo);
    }

    public function testAdditionalInfoNoApareceEnVarDump(): void
    {
        $opciones = $this->opciones(['additionalInfo' => 'dato-del-integrador']);
        self::assertStringNotContainsString('dato-del-integrador', print_r($opciones, true));
        self::assertStringNotContainsString('dato-del-integrador', print_r(new RequestOptions(additionalInfo: 'dato-del-integrador'), true));
    }

    // ---------------------------------------------------------------- Psr18Transport

    private function cargarStubs(): void
    {
        if (interface_exists(\Psr\Http\Message\RequestInterface::class)) {
            self::markTestSkipped('Hay paquetes PSR reales instalados; esta prueba usa interfaces minimas.');
        }
        require_once __DIR__ . '/Support/PsrStubs.php';
    }

    private function psr18(\Throwable $falla): Psr18Transport
    {
        $this->cargarStubs();
        $factory = new class () implements \Psr\Http\Message\RequestFactoryInterface, \Psr\Http\Message\StreamFactoryInterface {
            public function createRequest(string $method, $uri)
            {
                return new class () {
                    public function withHeader(string $name, string $value): static
                    {
                        return $this;
                    }

                    public function withBody(mixed $body): static
                    {
                        return $this;
                    }
                };
            }

            public function createStream(string $content = '')
            {
                return $content;
            }
        };
        $client = new class ($falla) implements \Psr\Http\Client\ClientInterface {
            public function __construct(private readonly \Throwable $falla)
            {
            }

            public function sendRequest($request)
            {
                throw $this->falla;
            }
        };
        return new Psr18Transport($client, $factory, $factory);
    }

    /** @param class-string<\Throwable> $esperada */
    private function assertFallaPsr(\Throwable $falla, string $esperada): void
    {
        try {
            $this->psr18($falla)->request('GET', 'https://api.example/v1/contexto', [], null);
            self::fail('Se esperaba ' . $esperada);
        } catch (EcuafactTimeoutException | EcuafactConnectionException $error) {
            self::assertInstanceOf($esperada, $error);
            self::assertSame($falla, $error->getPrevious());
        }
    }

    public function testPsr18GuzzleErrno28EsTimeout(): void
    {
        $this->cargarStubs();
        $falla = new class ('cURL error 28: Operation timed out') extends \RuntimeException implements \Psr\Http\Client\NetworkExceptionInterface {
            /** @return array<string, mixed> */
            public function getHandlerContext(): array
            {
                return ['errno' => 28, 'error' => 'Operation timed out'];
            }
        };
        $this->assertFallaPsr($falla, EcuafactTimeoutException::class);
    }

    public function testPsr18ErrnoDistintoDe28EsConexionAunqueElTextoDigaTimeout(): void
    {
        $this->cargarStubs();
        $falla = new class ('cURL error 7: Failed to connect (timeout de conexion del balanceador)') extends \RuntimeException implements \Psr\Http\Client\NetworkExceptionInterface {
            /** @return array<string, mixed> */
            public function getHandlerContext(): array
            {
                return ['errno' => 7];
            }
        };
        $this->assertFallaPsr($falla, EcuafactConnectionException::class);
    }

    public function testPsr18SymfonyTimeoutEnLaCadenaEsTimeout(): void
    {
        $this->cargarStubs();
        $causa = new class ('Idle timeout reached') extends \RuntimeException implements \Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface {
        };
        $falla = new class ('Fallo de red', 0, $causa) extends \RuntimeException implements \Psr\Http\Client\NetworkExceptionInterface {
        };
        $this->assertFallaPsr($falla, EcuafactTimeoutException::class);
    }

    public function testPsr18RequestExceptionNoEsTimeoutPorTexto(): void
    {
        $this->cargarStubs();
        $falla = new class ('Cabecera timeout invalida') extends \RuntimeException implements \Psr\Http\Client\RequestExceptionInterface {
        };
        $this->assertFallaPsr($falla, EcuafactConnectionException::class);
    }

    public function testPsr18SinTipoUsaElTextoComoRespaldo(): void
    {
        $this->assertFallaPsr(new \RuntimeException('Operation timed out after 30001 ms'), EcuafactTimeoutException::class);
        $falla = new class ('Could not resolve host') extends \RuntimeException implements \Psr\Http\Client\NetworkExceptionInterface {
        };
        $this->assertFallaPsr($falla, EcuafactConnectionException::class);
    }

    // ---------------------------------------------------------------- Hydrator extra

    public function testHydratorConservaCamposDesconocidosEnExtra(): void
    {
        $json = json_decode('{"idOperacion":"' . self::UUID . '","estado":"enviado","campoNuevo":"x",'
            . '"detalleNuevo":{"a":1,"b":[{"c":true}]},"extra":"del-api"}');
        /** @var Operation $operacion */
        $operacion = Hydrator::hydrate(Operation::class, $json);
        self::assertSame('enviado', $operacion->estado);
        self::assertSame([
            'campoNuevo' => 'x',
            'detalleNuevo' => ['a' => 1, 'b' => [['c' => true]]],
            'extra' => 'del-api',
        ], $operacion->extra);
    }

    public function testExtraEnModelosAnidadosYNoSeSerializa(): void
    {
        $transport = new FakeTransport(new TransportResponse(
            200,
            '{"datos":{"idCliente":"c","nombre":"N","contribuyentes":[{"identificacion":"0123456789","nuevo":1}],"plan":"pro"}}'
        ));
        $contexto = $this->cliente($transport)->getContexto();
        self::assertSame(['plan' => 'pro'], $contexto->extra);
        self::assertSame(['nuevo' => 1], $contexto->contribuyentes[0]->extra);

        $operacion = new Operation();
        $operacion->estado = 'enviado';
        $operacion->extra = ['oculto' => 1];
        self::assertSame('{"estado":"enviado"}', Serialization::encode($operacion));
    }
}
