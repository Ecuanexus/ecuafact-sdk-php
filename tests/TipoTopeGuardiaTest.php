<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Tests;

use Ecuafact\Sdk\Contracts\FacturaRequest;
use Ecuafact\Sdk\Contracts\InfoTributaria;
use Ecuafact\Sdk\EcuafactClient;
use Ecuafact\Sdk\EcuafactClientOptions;
use Ecuafact\Sdk\EcuafactConfigurationException;
use Ecuafact\Sdk\EcuafactResponseTooLargeException;
use Ecuafact\Sdk\EcuafactSdkException;
use Ecuafact\Sdk\Tests\Support\FakeTransport;
use Ecuafact\Sdk\Transport\CurlTransport;
use Ecuafact\Sdk\Transport\TransportResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Guardia de rutas y tope de respuesta. */
final class TipoTopeGuardiaTest extends TestCase
{
    private const ADMISION = '{"estado":"recibido","codEstado":1002,"idOperacion":"op-1"}';

    private function cliente(FakeTransport $transport, ?int $maxResponseBytes = EcuafactClientOptions::POR_DEFECTO): EcuafactClient
    {
        return new EcuafactClient(new EcuafactClientOptions(
            baseAddress: 'https://api.example/',
            apiKey: 'clave-de-prueba',
            transport: $transport,
            retryBaseDelaySeconds: 0.0,
            retryJitterSeconds: 0.0,
            maxResponseBytes: $maxResponseBytes,
        ));
    }

    private function vacio(): FacturaRequest
    {
        $c = new FacturaRequest();
        $c->infoTributaria = new InfoTributaria();
        $c->infoTributaria->ruc = '0123456789001';
        return $c;
    }

    /** @return iterable<string, array{string}> */
    public static function identificaciones(): iterable
    {
        foreach (['..', '.', '', ' ', 'a/b', '../x', '123', '12345678901234', '12345abcde'] as $valor) {
            yield 'ident ' . json_encode($valor) => [$valor];
        }
    }

    #[DataProvider('identificaciones')]
    public function testIdentificacionInvalidaNoLlegaAlCable(string $ident): void
    {
        $transport = new FakeTransport(new TransportResponse(202, self::ADMISION));
        $client = $this->cliente($transport);
        $llamadas = [
            fn () => $client->getPerfil($ident),
            fn () => $client->emitirEn($ident, $this->vacio(), 'K-1'),
            fn () => $client->validarEn($ident, $this->vacio()),
            fn () => $client->descargarRide($ident, '1'),
        ];
        foreach ($llamadas as $llamada) {
            try {
                $llamada();
                self::fail('Se esperaba EcuafactSdkException.');
            } catch (EcuafactSdkException) {
                self::assertTrue(true);
            }
        }
        self::assertSame([], $transport->peticiones);
    }

    /** @return iterable<string, array{string}> */
    public static function segmentos(): iterable
    {
        yield 'dos puntos' => [''.'..'];
        yield 'un punto' => ['.'];
        yield 'punto con espacios' => [' . '];
    }

    #[DataProvider('segmentos')]
    public function testSegmentosPuntoSeRechazanEnConsultas(string $valor): void
    {
        $transport = new FakeTransport(new TransportResponse(202, self::ADMISION));
        $client = $this->cliente($transport);
        $llamadas = [
            fn () => $client->consultarContribuyente($valor),
            fn () => $client->listarEstablecimientos($valor),
            fn () => $client->validarIdentificacion($valor),
            fn () => $client->decodificarClaveAcceso($valor),
            fn () => $client->descargarXml('0123456789', $valor),
        ];
        foreach ($llamadas as $llamada) {
            try {
                $llamada();
                self::fail('Se esperaba EcuafactSdkException.');
            } catch (EcuafactSdkException) {
                self::assertTrue(true);
            }
        }
        self::assertSame([], $transport->peticiones);
    }

    // -- tope de respuesta ----------------------------------------------------

    public function testRespuestaMayorQueElTopeLanzaConLimiteYBytes(): void
    {
        $transport = new FakeTransport(new TransportResponse(200, str_repeat('a', 5000)));
        try {
            $this->cliente($transport, 1000)->getContexto();
            self::fail('Se esperaba EcuafactResponseTooLargeException.');
        } catch (EcuafactResponseTooLargeException $error) {
            self::assertSame(1000, $error->limite);
            self::assertSame(5000, $error->bytesLeidos);
            self::assertStringContainsString('1000', $error->getMessage());
        }
        self::assertCount(1, $transport->peticiones);
    }

    public function testRespuestaMenorQueElTopePasa(): void
    {
        $transport = new FakeTransport(new TransportResponse(202, self::ADMISION));
        $resultado = $this->cliente($transport, 10000)->emitirEn('0123456789', $this->vacio(), 'K-1');
        self::assertSame('op-1', $resultado->admission->idOperacion);
    }

    public function testTopeDesactivadoConNullOCero(): void
    {
        foreach ([null, 0] as $valor) {
            $transport = new FakeTransport(new TransportResponse(200, str_repeat('a', 200000)));
            $archivo = $this->cliente($transport, $valor)->descargarRide('0123456789', '1');
            self::assertSame(200000, strlen($archivo->contenido));
        }
    }

    public function testTopesPorDefectoYDescargaConTopeExplicito(): void
    {
        $opciones = new EcuafactClientOptions(baseAddress: 'https://api.example/', apiKey: 'k');
        self::assertSame(32 * 1024 * 1024, $opciones->maxResponseBytes);
        self::assertSame(100 * 1024 * 1024, $opciones->maxDownloadBytes);

        $transport = new FakeTransport(new TransportResponse(200, str_repeat('a', 5000)));
        $this->expectException(EcuafactResponseTooLargeException::class);
        $this->cliente($transport, 1000)->descargarXml('0123456789', '1');
    }

    public function testTopeInvalidoEsErrorDeConfiguracion(): void
    {
        $this->expectException(EcuafactConfigurationException::class);
        new EcuafactClientOptions(baseAddress: 'https://api.example/', apiKey: 'k', maxResponseBytes: -5);
    }

    public function testCurlTransportCortaLaLecturaAlSuperarElTope(): void
    {
        if (!function_exists('curl_init')) {
            self::markTestSkipped('ext-curl no disponible.');
        }
        $archivo = tempnam(sys_get_temp_dir(), 'ecuafact');
        file_put_contents($archivo, str_repeat('a', 5000));
        try {
            $transport = new CurlTransport(5.0);
            $transport->limitarRespuesta(1000);
            try {
                $transport->request('GET', 'file://' . str_replace('\\', '/', $archivo), [], null);
                self::fail('Se esperaba EcuafactResponseTooLargeException.');
            } catch (EcuafactResponseTooLargeException $error) {
                self::assertSame(1000, $error->limite);
                self::assertGreaterThan(1000, $error->bytesLeidos);
            }
            $transport->limitarRespuesta(null);
            $respuesta = $transport->request('GET', 'file://' . str_replace('\\', '/', $archivo), [], null);
            self::assertSame(5000, strlen($respuesta->body));
        } finally {
            unlink($archivo);
        }
    }
}
