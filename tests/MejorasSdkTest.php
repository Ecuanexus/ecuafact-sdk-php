<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Tests;

use Ecuafact\Sdk\Contracts\ComprobanteRequest;
use Ecuafact\Sdk\Contracts\EstadoAutorizacion;
use Ecuafact\Sdk\Contracts\EstadoOperacion;
use Ecuafact\Sdk\Contracts\InfoDocumento;
use Ecuafact\Sdk\Contracts\InfoTributaria;
use Ecuafact\Sdk\Contracts\Operation;
use Ecuafact\Sdk\Contracts\Pago;
use Ecuafact\Sdk\Contracts\PerfilEmisor;
use Ecuafact\Sdk\Contracts\PerfilEmisorUpdate;
use Ecuafact\Sdk\Contracts\TipoComprobante;
use Ecuafact\Sdk\EcuafactApiException;
use Ecuafact\Sdk\EcuafactAuthException;
use Ecuafact\Sdk\EcuafactClient;
use Ecuafact\Sdk\EcuafactClientOptions;
use Ecuafact\Sdk\EcuafactConfigurationException;
use Ecuafact\Sdk\EcuafactConflictException;
use Ecuafact\Sdk\EcuafactConnectionException;
use Ecuafact\Sdk\EcuafactNotFoundException;
use Ecuafact\Sdk\EcuafactQuotaException;
use Ecuafact\Sdk\EcuafactRateLimitException;
use Ecuafact\Sdk\EcuafactSdkException;
use Ecuafact\Sdk\EcuafactServerException;
use Ecuafact\Sdk\EcuafactTimeoutException;
use Ecuafact\Sdk\EcuafactValidationException;
use Ecuafact\Sdk\ListadoRequest;
use Ecuafact\Sdk\RetryBackoff;
use Ecuafact\Sdk\Support\Hydrator;
use Ecuafact\Sdk\Support\Serialization;
use Ecuafact\Sdk\Tests\Support\FakeTransport;
use Ecuafact\Sdk\Transport\CurlTransport;
use Ecuafact\Sdk\Transport\Psr18Transport;
use Ecuafact\Sdk\Transport\TransportInterface;
use Ecuafact\Sdk\Transport\TransportResponse;
use PHPUnit\Framework\TestCase;

final class MejorasSdkTest extends TestCase
{
    private const UUID = '3f2b8c1e-7a4d-4e2b-9c11-5d6f7a8b9c0d';
    private const API_KEY = 'clave.secreta-NO-MOSTRAR';

    /** @var array<string, string|false> */
    private array $envOriginal = [];

    protected function setUp(): void
    {
        foreach (['ECUAFACT_API_KEY', 'ECUAFACT_BASE_URL'] as $name) {
            $this->envOriginal[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envOriginal as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    /** @param array<string, mixed> $extra */
    private function cliente(TransportInterface $transport, array $extra = []): EcuafactClient
    {
        return new EcuafactClient(new EcuafactClientOptions(...array_merge([
            'baseAddress' => 'https://api.example/',
            'apiKey' => self::API_KEY,
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

    private static function operacionJson(string $estado, string $autorizacion): string
    {
        return '{"datos":{"idOperacion":"' . self::UUID . '","estado":"' . $estado . '","estadoAutorizacion":"' . $autorizacion . '"}}';
    }

    // ---------------------------------------------------------------- 1.1 429

    public function test429Con501NuncaSeReintentaNiConRetryRateLimited(): void
    {
        $transport = new FakeTransport(
            new TransportResponse(429, '{"codigo":"501","mensaje":"Cupo agotado."}', ['Retry-After' => '0']),
            new TransportResponse(202, '{"idOperacion":"ok"}')
        );
        try {
            $this->cliente($transport, ['retryRateLimited' => true])->emitirEn('0123456789', $this->comprobante(), 'K-501');
            self::fail('Se esperaba EcuafactQuotaException.');
        } catch (EcuafactQuotaException $error) {
            self::assertCount(1, $transport->peticiones);
            self::assertSame('501', $error->codigo);
            self::assertFalse($error->esReintentable);
            self::assertSame('K-501', $error->idempotencyKey);
        }
    }

    public function test429Con104NoSeReintentaPorDefecto(): void
    {
        $transport = new FakeTransport(
            new TransportResponse(429, '{"codigo":"104","mensaje":"Limite."}', ['Retry-After' => '7']),
            new TransportResponse(202, '{"idOperacion":"ok"}')
        );
        try {
            $this->cliente($transport)->emitirEn('0123456789', $this->comprobante(), 'K-104');
            self::fail('Se esperaba EcuafactRateLimitException.');
        } catch (EcuafactRateLimitException $error) {
            self::assertCount(1, $transport->peticiones);
            self::assertTrue($error->esReintentable);
            self::assertSame(7, $error->retryAfter);
        }
    }

    public function test429Con104SeReintentaConRetryRateLimited(): void
    {
        $transport = new FakeTransport(
            new TransportResponse(429, '{"codigo":104,"mensaje":"Limite."}', ['Retry-After' => '0']),
            new TransportResponse(202, '{"idOperacion":"ok"}')
        );
        $resultado = $this->cliente($transport, ['retryRateLimited' => true])
            ->emitirEn('0123456789', $this->comprobante(), 'K-104');
        self::assertSame('ok', $resultado->admission->idOperacion);
        self::assertCount(2, $transport->peticiones);
    }

    // ---------------------------------------------------------------- 2.1 politica

    public function testCodigosReintentablesConfigurables(): void
    {
        $transport = new FakeTransport(new TransportResponse(503, '{"codigo":"002"}'));
        try {
            $this->cliente($transport, ['retryableStatusCodes' => [500]])->getContexto();
            self::fail('Se esperaba EcuafactServerException.');
        } catch (EcuafactServerException) {
            self::assertCount(1, $transport->peticiones);
        }

        $transport = new FakeTransport(
            new TransportResponse(500, '{"codigo":"002"}'),
            new TransportResponse(200, '{"datos":{"idCliente":"c"}}')
        );
        self::assertSame('c', $this->cliente($transport, ['retryableStatusCodes' => [500]])->getContexto()->idCliente);
        self::assertCount(2, $transport->peticiones);
    }

    public function testBackoffExponencialYLineal(): void
    {
        $delays = function (RetryBackoff $backoff): array {
            $client = $this->cliente(new FakeTransport(new TransportResponse(200, '{}')), [
                'retryBaseDelaySeconds' => 0.1,
                'retryJitterSeconds' => 0.0,
                'retryBackoff' => $backoff,
            ]);
            $method = new \ReflectionMethod($client, 'computeDelay');
            return [$method->invoke($client, 1, null), $method->invoke($client, 2, null), $method->invoke($client, 3, null)];
        };
        self::assertSame([100, 200, 400], $delays(RetryBackoff::Exponential));
        self::assertSame([100, 200, 300], $delays(RetryBackoff::Linear));

        $options = new EcuafactClientOptions('https://api.example/', 'k', retryBackoff: 'exponential');
        self::assertSame(RetryBackoff::Exponential, $options->retryBackoff);
    }

    public function testTotalTimeoutCortaLosReintentos(): void
    {
        $transport = new FakeTransport(new TransportResponse(503, '{"codigo":"002"}'));
        $inicio = microtime(true);
        try {
            $this->cliente($transport, [
                'maxAttempts' => 50,
                'retryBaseDelaySeconds' => 0.1,
                'retryBackoff' => RetryBackoff::Linear,
                'totalTimeoutSeconds' => 0.35,
            ])->getContexto();
            self::fail('Se esperaba EcuafactServerException.');
        } catch (EcuafactServerException) {
            self::assertLessThan(1.0, microtime(true) - $inicio);
            self::assertLessThan(50, count($transport->peticiones));
            self::assertGreaterThanOrEqual(2, count($transport->peticiones));
        }
    }

    public function testValidaOpcionesDeReintentoYTiempos(): void
    {
        $casos = [
            ['maxAttempts' => 0],
            ['timeout' => -1.0],
            ['timeout' => 0.0],
            ['maxRetryDelaySeconds' => -1.0],
            ['retryBaseDelaySeconds' => -0.1],
            ['retryJitterSeconds' => -0.1],
            ['totalTimeoutSeconds' => 0.0],
            ['connectTimeout' => -2.0],
            ['retryableStatusCodes' => [99]],
            ['retryBackoff' => 'cuadratico'],
        ];
        foreach ($casos as $caso) {
            try {
                new EcuafactClientOptions(...array_merge(['baseAddress' => 'https://api.example/', 'apiKey' => 'k'], $caso));
                self::fail('Se esperaba EcuafactConfigurationException para ' . json_encode($caso));
            } catch (EcuafactConfigurationException $error) {
                self::assertInstanceOf(EcuafactSdkException::class, $error);
            }
        }
    }

    // ---------------------------------------------------------------- 1.2 importes

    public function testImportesExactosEnElJsonEnviado(): void
    {
        $pago = new Pago();
        $pago->formaPago = '01';
        $pago->total = 0.1 + 0.2;
        self::assertSame('{"formaPago":"01","total":0.3}', Serialization::encode($pago));

        $pago->total = '11.50';
        self::assertSame('{"formaPago":"01","total":11.50}', Serialization::encode($pago));

        $pago->total = '-0.000001';
        self::assertSame('{"formaPago":"01","total":-0.000001}', Serialization::encode($pago));

        $info = new InfoDocumento();
        $info->importeTotal = '1234567890.123456';
        $info->pagos = [$pago];
        $json = Serialization::encode($info);
        self::assertStringContainsString('"importeTotal":1234567890.123456', $json);
        self::assertNotNull(json_decode($json));
    }

    public function testImporteNoDecimalSeRechazaLocalmente(): void
    {
        $pago = new Pago();
        $pago->total = '1e3';
        $this->expectException(EcuafactSdkException::class);
        Serialization::encode($pago);
    }

    public function testEmisionEnviaImportesComoNumeroLiteral(): void
    {
        $transport = new FakeTransport(new TransportResponse(202, '{"idOperacion":"x"}'));
        $comprobante = $this->comprobante();
        $comprobante->info = new InfoDocumento();
        $comprobante->info->importeTotal = '11.50';
        $comprobante->info->totalSinImpuestos = 0.1 + 0.2;
        $this->cliente($transport)->emitirEn('0123456789', $comprobante, 'K-IMP');
        $cuerpo = (string) $transport->peticiones[0]['body'];
        self::assertStringContainsString('"importeTotal":11.50', $cuerpo);
        self::assertStringContainsString('"totalSinImpuestos":0.3', $cuerpo);
    }

    // ---------------------------------------------------------------- 1.5 hydrator

    public function testHydratorCoercionaPorTipo(): void
    {
        $request = Hydrator::hydrate(ComprobanteRequest::class, json_decode(
            '{"info":{"importeTotal":"11.50","totalSinImpuestos":10,"pagos":[{"total":"11.50","plazo":30}]}}'
        ));
        self::assertSame('11.50', $request->info->importeTotal);
        self::assertSame(10.0, $request->info->totalSinImpuestos);
        self::assertSame('11.50', $request->info->pagos[0]->total);
        self::assertStringContainsString('"importeTotal":11.50', Serialization::encode($request));

        // Arreglo asociativo tambien se acepta.
        $assoc = Hydrator::hydrate(ComprobanteRequest::class, ['info' => ['importeTotal' => '1.00']]);
        self::assertSame('1.00', $assoc->info->importeTotal);

        $perfil = Hydrator::hydrate(PerfilEmisor::class, json_decode('{"obligadoContabilidad":null,"razonSocial":123}'));
        self::assertFalse($perfil->obligadoContabilidad);
        self::assertSame('123', $perfil->razonSocial);

        $operacion = Hydrator::hydrate(Operation::class, json_decode('{"ambiente":"1","estado":"en_cola"}'));
        self::assertSame(1, $operacion->ambiente);

        $perfil = Hydrator::hydrate(PerfilEmisor::class, json_decode('{"obligadoContabilidad":"true"}'));
        self::assertTrue($perfil->obligadoContabilidad);
    }

    public function testRespuestaConTiposInesperadosDaExcepcionDelSdk(): void
    {
        $transport = new FakeTransport(new TransportResponse(200, '{"datos":{"ambiente":{"x":1}}}'));
        try {
            $this->cliente($transport)->getOperacion(self::UUID);
            self::fail('Se esperaba EcuafactSdkException.');
        } catch (EcuafactSdkException $error) {
            self::assertNotInstanceOf(EcuafactApiException::class, $error);
            self::assertStringContainsString('Operation.ambiente', $error->getMessage());
        }

        $transport = new FakeTransport(new TransportResponse(200, '{"datos":"texto"}'));
        $this->expectException(EcuafactSdkException::class);
        $this->cliente($transport)->getContexto();
    }

    public function testLeeTotalStringDelListadoComoFloat(): void
    {
        $transport = new FakeTransport(new TransportResponse(
            200,
            '{"datos":{"comprobantes":[{"claveAcceso":"C","total":"11.50","codDoc":"04","estadoAutorizacion":"autorizado"}],"pagina":1,"hayMas":false}}'
        ));
        $pagina = $this->cliente($transport)->listarEmitidosEn('0123456789');
        self::assertSame(11.5, $pagina->comprobantes[0]->total);
        self::assertSame(TipoComprobante::NotaCredito, $pagina->comprobantes[0]->tipoComprobante());
        self::assertSame(EstadoAutorizacion::Autorizado, $pagina->comprobantes[0]->estadoAutorizacionTipado());
    }

    public function testErrorConCodigoNumerico(): void
    {
        $transport = new FakeTransport(new TransportResponse(422, '{"codigo":302,"mensaje":"Invalido.","errores":["a",5,{"x":1}]}'));
        try {
            $this->cliente($transport)->getContexto();
            self::fail('Se esperaba EcuafactValidationException.');
        } catch (EcuafactValidationException $error) {
            self::assertSame('302', $error->codigo);
            self::assertSame(['a', '5', '{"x":1}'], $error->errores);
        }
    }

    // ---------------------------------------------------------------- 1.6 secretos

    public function testApiKeyOcultaAlImprimir(): void
    {
        $options = new EcuafactClientOptions('https://api.example/', self::API_KEY, proxy: 'http://u:clave-proxy@proxy:8080');
        $client = new EcuafactClient($options);
        self::assertSame(self::API_KEY, $options->apiKey);
        self::assertTrue(isset($options->apiKey));

        ob_start();
        var_dump($options);
        var_dump($client);
        $volcado = (string) ob_get_clean();
        $volcado .= print_r($options, true) . print_r($client, true) . (string) json_encode($options);
        $volcado .= var_export((array) $options, true);
        self::assertStringNotContainsString(self::API_KEY, $volcado);
        self::assertStringNotContainsString('clave-proxy', print_r($options, true));
        self::assertStringContainsString('***', print_r($options, true));
    }

    public function testApiKeyNuncaAparecaEnExcepcionesNiTrazas(): void
    {
        foreach ([' ' . self::API_KEY, self::API_KEY . "\n", "clave\x01rara"] as $clave) {
            try {
                new EcuafactClientOptions('https://api.example/', $clave);
                self::fail('Se esperaba EcuafactConfigurationException.');
            } catch (EcuafactConfigurationException $error) {
                self::assertStringNotContainsString(trim($clave), $error->getMessage());
                self::assertStringNotContainsString(trim($clave), $error->getTraceAsString());
                self::assertStringNotContainsString(trim($clave), print_r($error->getTrace(), true));
            }
        }
    }

    public function testNoSePuedeModificarLaApiKey(): void
    {
        $options = new EcuafactClientOptions('https://api.example/', 'k');
        $this->expectException(\Error::class);
        $options->apiKey = 'otra';
    }

    // ---------------------------------------------------------------- 1.10

    public function testIdempotencyKeyInvalida(): void
    {
        $client = $this->cliente(new FakeTransport(new TransportResponse(202, '{"idOperacion":"x"}')));
        foreach (['con espacio', 'tilde-ñ', 'a;b', str_repeat('a', 129), "salto\n"] as $key) {
            try {
                $client->emitirEn('0123456789', $this->comprobante(), $key);
                self::fail('Se esperaba EcuafactSdkException.');
            } catch (EcuafactSdkException $error) {
                self::assertSame(
                    'Idempotency-Key: solo letras, digitos y - _ . : /, maximo 128 caracteres.',
                    $error->getMessage()
                );
            }
        }
        $transport = new FakeTransport(new TransportResponse(202, '{"idOperacion":"x"}'));
        $this->cliente($transport)->emitirEn('0123456789', $this->comprobante(), 'ERP:2026/F-001_a.b');
        self::assertSame('ERP:2026/F-001_a.b', $transport->header(0, 'Idempotency-Key'));
    }

    public function testIdOperacionDebeSerUuid(): void
    {
        $transport = new FakeTransport(new TransportResponse(200, self::operacionJson('en_cola', 'pendiente')));
        $client = $this->cliente($transport);
        foreach (['../consumo?x=', 'op-1', '', self::UUID . '/x'] as $id) {
            try {
                $client->getOperacion($id);
                self::fail('Se esperaba EcuafactSdkException.');
            } catch (EcuafactSdkException) {
                self::assertCount(0, $transport->peticiones);
            }
        }
    }

    // ---------------------------------------------------------------- 2.6 entorno y URL

    public function testLeeVariablesDeEntorno(): void
    {
        putenv('ECUAFACT_API_KEY=clave-desde-entorno');
        putenv('ECUAFACT_BASE_URL=https://staging-api.mynexusapi.com/');
        $options = new EcuafactClientOptions();
        self::assertSame('clave-desde-entorno', $options->apiKey);
        self::assertSame('https://staging-api.mynexusapi.com/', $options->baseAddress);

        // getenv() devuelve false si la variable no existe: se usa el entorno.
        $options = new EcuafactClientOptions(baseAddress: getenv('NO_EXISTE_ECUAFACT'), apiKey: getenv('NO_EXISTE_ECUAFACT'));
        self::assertSame('clave-desde-entorno', $options->apiKey);
    }

    public function testSinApiKeyNiEntornoDaErrorClaro(): void
    {
        try {
            new EcuafactClientOptions(baseAddress: 'https://api.example/');
            self::fail('Se esperaba EcuafactConfigurationException.');
        } catch (EcuafactConfigurationException $error) {
            self::assertStringContainsString('ECUAFACT_API_KEY', $error->getMessage());
        }
        try {
            new EcuafactClientOptions(apiKey: 'k');
            self::fail('Se esperaba EcuafactConfigurationException.');
        } catch (EcuafactConfigurationException $error) {
            self::assertStringContainsString('ECUAFACT_BASE_URL', $error->getMessage());
        }
    }

    public function testRechazaUrlConV1OInvalida(): void
    {
        foreach (['https://api.example/v1', 'https://api.example/v1/', 'https://api.example/V1', 'ftp://api.example/', 'api.example', '/relativa', 'https://api.example/?a=1'] as $url) {
            try {
                new EcuafactClientOptions($url, 'k');
                self::fail('Se esperaba EcuafactConfigurationException para ' . $url);
            } catch (EcuafactConfigurationException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
        putenv('ECUAFACT_BASE_URL=https://api.example/v1/');
        try {
            new EcuafactClientOptions(apiKey: 'k');
            self::fail('Se esperaba EcuafactConfigurationException.');
        } catch (EcuafactConfigurationException $error) {
            self::assertStringContainsString('/v1', $error->getMessage());
        }
        self::assertSame('http://localhost:5000/base', (new EcuafactClientOptions('http://localhost:5000/base', 'k'))->baseAddress);
    }

    // ---------------------------------------------------------------- 2.4 conexion

    public function testOpcionesDeConexionSeAplicanAlTransporteCurl(): void
    {
        $client = new EcuafactClient(new EcuafactClientOptions(
            baseAddress: 'https://api.example/',
            apiKey: 'k',
            timeout: 2.5,
            connectTimeout: 0.75,
            proxy: 'http://proxy.local:8080',
            caInfo: '/ruta/ca.pem',
            verifyPeer: false,
            curlOptions: [CURLOPT_TCP_KEEPALIVE => 1],
        ));
        $transport = (new \ReflectionProperty($client, 'transport'))->getValue($client);
        self::assertInstanceOf(CurlTransport::class, $transport);
        $curl = $transport->curlOptions();
        self::assertSame(2500, $curl[CURLOPT_TIMEOUT_MS]);
        self::assertSame(750, $curl[CURLOPT_CONNECTTIMEOUT_MS]);
        self::assertSame('http://proxy.local:8080', $curl[CURLOPT_PROXY]);
        self::assertSame('/ruta/ca.pem', $curl[CURLOPT_CAINFO]);
        self::assertFalse($curl[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(0, $curl[CURLOPT_SSL_VERIFYHOST]);
        self::assertSame(1200, $transport->curlOptions(1.2)[CURLOPT_TIMEOUT_MS]);
    }

    public function testCurlSinServidorDaExcepcionDeConexion(): void
    {
        $client = new EcuafactClient(new EcuafactClientOptions(
            baseAddress: 'http://127.0.0.1:9/',
            apiKey: 'clave-red-secreta',
            connectTimeout: 1.0,
            timeout: 2.0,
            retryTransientFailures: false,
        ));
        try {
            $client->emitirEn('0123456789', $this->comprobante(), 'K-RED');
            self::fail('Se esperaba una excepcion de conexion.');
        } catch (EcuafactConnectionException | EcuafactTimeoutException $error) {
            self::assertSame('K-RED', $error->idempotencyKey);
            self::assertNotNull($error->curlErrno);
            // La credencial no aparece en las trazas (cabeceras marcadas como SensitiveParameter).
            for ($actual = $error; $actual !== null; $actual = $actual->getPrevious()) {
                self::assertStringNotContainsString('clave-red-secreta', print_r($actual->getTrace(), true));
                self::assertStringNotContainsString('clave-red-secreta', $actual->getTraceAsString());
            }
        }
    }

    public function testErrorDeTransportePropioSeEnvuelveConLaKey(): void
    {
        $transport = new class () implements TransportInterface {
            public int $llamadas = 0;

            public function request(string $method, string $url, array $headers, ?string $body): TransportResponse
            {
                $this->llamadas++;
                throw new \RuntimeException('socket cerrado');
            }
        };
        try {
            $this->cliente($transport)->emitirEn('0123456789', $this->comprobante(), 'K-T');
            self::fail('Se esperaba EcuafactConnectionException.');
        } catch (EcuafactConnectionException $error) {
            self::assertSame('K-T', $error->idempotencyKey);
            self::assertSame(3, $transport->llamadas);
        }
    }

    public function testAdaptadorPsr18(): void
    {
        if (interface_exists(\Psr\Http\Message\RequestInterface::class)) {
            self::markTestSkipped('Hay paquetes PSR reales instalados; esta prueba usa interfaces minimas.');
        }
        require_once __DIR__ . '/Support/PsrStubs.php';

        $request = new class () {
            /** @var array<string, string> */
            public array $headers = [];
            public string $body = '';
            public string $method = '';
            public string $uri = '';

            public function withHeader(string $name, string $value): static
            {
                $copy = clone $this;
                $copy->headers[$name] = $value;
                return $copy;
            }

            public function withBody(string $body): static
            {
                $copy = clone $this;
                $copy->body = $body;
                return $copy;
            }
        };
        $factory = new class ($request) implements \Psr\Http\Message\RequestFactoryInterface, \Psr\Http\Message\StreamFactoryInterface {
            public function __construct(private readonly object $prototype)
            {
            }

            public function createRequest(string $method, $uri)
            {
                $copy = clone $this->prototype;
                $copy->method = $method;
                $copy->uri = (string) $uri;
                return $copy;
            }

            public function createStream(string $content = '')
            {
                return $content;
            }
        };
        $psr = new class () implements \Psr\Http\Client\ClientInterface {
            public ?object $recibido = null;

            public function sendRequest($request)
            {
                $this->recibido = $request;
                return new class () {
                    public function getStatusCode(): int
                    {
                        return 200;
                    }

                    /** @return array<string, string[]> */
                    public function getHeaders(): array
                    {
                        return ['X-Correlation-Id' => ['corr-psr']];
                    }

                    public function getBody(): string
                    {
                        return '{"datos":{"idCliente":"psr"}}';
                    }
                };
            }
        };
        $client = $this->cliente(new Psr18Transport($psr, $factory, $factory));
        self::assertSame('psr', $client->getContexto()->idCliente);
        self::assertSame('GET', $psr->recibido->method);
        self::assertSame('https://api.example/v1/contexto', $psr->recibido->uri);
        self::assertSame(self::API_KEY, $psr->recibido->headers['X-Api-Key']);
    }

    // ---------------------------------------------------------------- 3.1 esperarResultado

    public function testEsperarResultadoTerminaEnEstadoFinal(): void
    {
        $transport = new FakeTransport(
            new TransportResponse(200, self::operacionJson('en_cola', 'no_disponible')),
            new TransportResponse(200, self::operacionJson('enviado', 'pendiente')),
            new TransportResponse(200, self::operacionJson('enviado', 'autorizado')),
            new TransportResponse(200, self::operacionJson('enviado', 'pendiente'))
        );
        $operacion = $this->cliente($transport)->esperarResultado(self::UUID, 0.01, 5.0);
        self::assertTrue($operacion->esFinal());
        self::assertTrue($operacion->esAutorizado());
        self::assertCount(3, $transport->peticiones);
    }

    public function testEsperarResultadoRespetaElMaximo(): void
    {
        $transport = new FakeTransport(new TransportResponse(200, self::operacionJson('enviado', 'pendiente')));
        $inicio = microtime(true);
        $operacion = $this->cliente($transport)->esperarResultado(self::UUID, 0.05, 0.2);
        $transcurrido = microtime(true) - $inicio;
        self::assertFalse($operacion->esFinal());
        self::assertGreaterThanOrEqual(0.19, $transcurrido);
        self::assertLessThan(1.5, $transcurrido);
        self::assertGreaterThanOrEqual(3, count($transport->peticiones));
        self::assertLessThanOrEqual(8, count($transport->peticiones));
    }

    public function testEsperarResultadoTerminaConRechazoOCancelacion(): void
    {
        foreach (['rechazado', 'cancelado'] as $estado) {
            $transport = new FakeTransport(new TransportResponse(200, self::operacionJson($estado, 'no_disponible')));
            $operacion = $this->cliente($transport)->esperarResultado(self::UUID, 10.0, 60.0);
            self::assertTrue($operacion->esFinal());
            self::assertFalse($operacion->esAutorizado());
            self::assertCount(1, $transport->peticiones);
        }
    }

    // ---------------------------------------------------------------- 3.2 iterarEmitidos

    public function testIterarEmitidosRecorrePaginasDeFormaPerezosa(): void
    {
        $transport = new FakeTransport(
            new TransportResponse(200, '{"datos":{"comprobantes":[{"claveAcceso":"A"},{"claveAcceso":"B"}],"pagina":1,"hayMas":true}}'),
            new TransportResponse(200, '{"datos":{"comprobantes":[{"claveAcceso":"C"}],"pagina":2,"hayMas":true}}'),
            new TransportResponse(200, '{"datos":{"comprobantes":[{"claveAcceso":"D"}],"pagina":3,"hayMas":false}}')
        );
        $filtros = (new ListadoRequest())->codDoc('01')->tamanoPagina(2);
        $iterador = $this->cliente($transport)->iterarEmitidosEn('0123456789', $filtros);
        self::assertCount(0, $transport->peticiones);
        self::assertSame('A', $iterador->current()->claveAcceso);
        self::assertCount(1, $transport->peticiones);

        $claves = [];
        foreach ($iterador as $comprobante) {
            $claves[] = $comprobante->claveAcceso;
        }
        self::assertSame(['A', 'B', 'C', 'D'], $claves);
        self::assertCount(3, $transport->peticiones);
        self::assertStringContainsString('pagina=1', $transport->peticiones[0]['url']);
        self::assertStringContainsString('pagina=3', $transport->peticiones[2]['url']);
        self::assertStringContainsString('codDoc=01', $transport->peticiones[2]['url']);
        self::assertNull($filtros->pagina);
    }

    public function testIterarEmitidosDesdeLaVistaYConRucPorDefecto(): void
    {
        $transport = new FakeTransport(new TransportResponse(200, '{"datos":{"comprobantes":[{"claveAcceso":"A"}],"hayMas":false}}'));
        $claves = iterator_to_array($this->cliente($transport)->para('0123456789')->iterarEmitidos(), false);
        self::assertCount(1, $claves);
        self::assertStringContainsString('/v1/contribuyentes/0123456789/comprobantes/emitidos?pagina=1', $transport->peticiones[0]['url']);

        $this->expectException(EcuafactSdkException::class);
        $this->cliente($transport)->iterarEmitidos();
    }

    public function testFechasDeFiltroComoDateTime(): void
    {
        $transport = new FakeTransport(new TransportResponse(200, '{"datos":{"comprobantes":[],"hayMas":false}}'));
        $filtros = (new ListadoRequest())
            ->desde(new \DateTimeImmutable('2026-10-01 23:30:00', new \DateTimeZone('America/Guayaquil')))
            ->hasta('2026-10-07');
        $this->cliente($transport)->listarEmitidosEn('0123456789', $filtros);
        self::assertStringContainsString('desde=2026-10-01&hasta=2026-10-07', $transport->peticiones[0]['url']);
    }

    // ---------------------------------------------------------------- 3.4 logo

    public function testActualizarLogoEnviaMultipart(): void
    {
        $transport = new FakeTransport(new TransportResponse(
            200,
            '{"datos":{"identificacion":"0123456789001","logo":"0123456789001_Logo.png","obligadoContabilidad":false}}'
        ));
        $bytes = "\x89PNG\r\n\x1a\n\x00\x01binario";
        $perfil = $this->cliente($transport)->actualizarLogo('0123456789', $bytes, 'image/png', 'logo.png');
        self::assertSame('0123456789001_Logo.png', $perfil->logo);

        $peticion = $transport->peticiones[0];
        self::assertSame('POST', $peticion['method']);
        self::assertStringEndsWith('/v1/contribuyentes/0123456789/perfil/logo', $peticion['url']);
        $tipo = (string) $transport->header(0, 'Content-Type');
        self::assertMatchesRegularExpression('/^multipart\/form-data; boundary=(.+)$/', $tipo);
        $boundary = substr($tipo, strlen('multipart/form-data; boundary='));
        $cuerpo = (string) $peticion['body'];
        self::assertStringStartsWith('--' . $boundary . "\r\n", $cuerpo);
        self::assertStringContainsString('Content-Disposition: form-data; name="logo"; filename="logo.png"', $cuerpo);
        self::assertStringContainsString("Content-Type: image/png\r\n\r\n" . $bytes . "\r\n--" . $boundary . "--\r\n", $cuerpo);
        self::assertNull($transport->header(0, 'Idempotency-Key'));
    }

    public function testActualizarLogoNoSeReintentaYValidaArgumentos(): void
    {
        $transport = new FakeTransport(new TransportResponse(503, '{"codigo":"002"}'));
        try {
            $this->cliente($transport)->actualizarLogo('0123456789', 'x', 'image/png', 'logo.png');
            self::fail('Se esperaba EcuafactServerException.');
        } catch (EcuafactServerException) {
            self::assertCount(1, $transport->peticiones);
        }
        $this->expectException(EcuafactSdkException::class);
        $this->cliente($transport)->actualizarLogo('0123456789', '', 'image/png', 'logo.png');
    }

    // ---------------------------------------------------------------- 3.6 enums

    public function testEnumsConValorDesconocido(): void
    {
        $operacion = Hydrator::hydrate(Operation::class, json_decode(
            '{"estado":"estado_nuevo","estadoAutorizacion":"otro","codDoc":"99"}'
        ));
        self::assertSame(EstadoOperacion::Desconocido, $operacion->estadoOperacion());
        self::assertSame(EstadoAutorizacion::Desconocido, $operacion->estadoAutorizacionTipado());
        self::assertNull($operacion->tipoComprobante());
        self::assertFalse($operacion->esFinal());
        self::assertSame('estado_nuevo', $operacion->estado);

        $operacion->estado = 'resultado_desconocido';
        $operacion->estadoAutorizacion = 'error';
        $operacion->codDoc = '07';
        self::assertSame(EstadoOperacion::ResultadoDesconocido, $operacion->estadoOperacion());
        self::assertSame(EstadoAutorizacion::Error, $operacion->estadoAutorizacionTipado());
        self::assertSame(TipoComprobante::Retencion, $operacion->tipoComprobante());
        self::assertTrue($operacion->esFinal());
        self::assertFalse($operacion->esAutorizado());

        $vacia = new Operation();
        self::assertSame(EstadoOperacion::Desconocido, $vacia->estadoOperacion());
        self::assertFalse($vacia->esFinal());
    }

    // ---------------------------------------------------------------- 3.7 excepciones

    public function testTipoDeExcepcionPorHttpYCodigo(): void
    {
        $casos = [
            [401, '100', EcuafactAuthException::class, false],
            [403, '103', EcuafactAuthException::class, false],
            [400, '301', EcuafactValidationException::class, false],
            [422, '302', EcuafactValidationException::class, false],
            [404, '202', EcuafactNotFoundException::class, false],
            [409, '401', EcuafactConflictException::class, false],
            [429, '104', EcuafactRateLimitException::class, true],
            [429, '501', EcuafactQuotaException::class, false],
            [500, '002', EcuafactServerException::class, true],
            [502, '002', EcuafactServerException::class, true],
            [501, '005', EcuafactServerException::class, false],
            [418, 'x', EcuafactApiException::class, false],
        ];
        foreach ($casos as [$status, $codigo, $clase, $reintentable]) {
            $transport = new FakeTransport(new TransportResponse(
                $status,
                '{"codigo":"' . $codigo . '","mensaje":"m"}',
                ['X-Correlation-Id' => 'corr', 'Retry-After' => '3']
            ));
            try {
                $this->cliente($transport, ['retryTransientFailures' => false])->getContexto();
                self::fail('Se esperaba ' . $clase);
            } catch (EcuafactApiException $error) {
                self::assertSame($clase, $error::class, 'HTTP ' . $status);
                self::assertSame($codigo, $error->codigo);
                self::assertSame('m', $error->mensaje);
                self::assertSame($status, $error->estadoHttp);
                self::assertSame('corr', $error->idSeguimiento);
                self::assertSame(3, $error->retryAfter);
                self::assertSame($reintentable, $error->esReintentable, 'HTTP ' . $status);
            }
        }
    }

    // ---------------------------------------------------------------- 3.8 vista

    public function testVistaPorContribuyente(): void
    {
        $transport = new FakeTransport(
            new TransportResponse(200, 'pdf', ['Content-Type' => 'application/pdf']),
            new TransportResponse(200, '<xml/>', ['Content-Type' => 'application/xml', 'X-Contenido' => 'provisional']),
            new TransportResponse(200, '{"datos":{"claveAcceso":"CLAVE","destinatario":"a@b.com"}}'),
            new TransportResponse(200, '{"datos":{"identificacion":"0123456789001","obligadoContabilidad":true}}'),
            new TransportResponse(200, '{"datos":{"identificacion":"0123456789001","ciudad":"Quito"}}'),
            new TransportResponse(200, '{"datos":{"identificacion":"0123456789001","logo":"l.png"}}')
        );
        $vista = $this->cliente($transport)->para('0123456789');
        self::assertSame('application/pdf', $vista->descargarRide('CLAVE')->contentType);
        self::assertTrue($vista->descargarXml('CLAVE')->provisional);
        self::assertSame('a@b.com', $vista->enviarCorreo('CLAVE', 'a@b.com')->destinatario);
        self::assertTrue($vista->getPerfil()->obligadoContabilidad);
        $cambios = new PerfilEmisorUpdate();
        $cambios->ciudad = 'Quito';
        self::assertSame('Quito', $vista->actualizarPerfil($cambios)->ciudad);
        self::assertSame('l.png', $vista->actualizarLogo('bytes', 'image/jpeg', 'logo.jpg')->logo);

        $base = 'https://api.example/v1/contribuyentes/0123456789';
        self::assertSame($base . '/comprobantes/CLAVE/ride', $transport->peticiones[0]['url']);
        self::assertSame($base . '/comprobantes/CLAVE/xml', $transport->peticiones[1]['url']);
        self::assertSame($base . '/comprobantes/CLAVE/correo', $transport->peticiones[2]['url']);
        self::assertSame($base . '/perfil', $transport->peticiones[3]['url']);
        self::assertSame('PUT', $transport->peticiones[4]['method']);
        self::assertSame('{"ciudad":"Quito"}', $transport->peticiones[4]['body']);
        self::assertSame($base . '/perfil/logo', $transport->peticiones[5]['url']);
    }
}
