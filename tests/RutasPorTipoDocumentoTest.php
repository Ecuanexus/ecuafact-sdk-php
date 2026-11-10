<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Tests;

use Ecuafact\Sdk\Contracts\ComprobanteRequest;
use Ecuafact\Sdk\Contracts\InfoTributaria;
use Ecuafact\Sdk\EcuafactClient;
use Ecuafact\Sdk\EcuafactClientOptions;
use Ecuafact\Sdk\EcuafactSdkException;
use Ecuafact\Sdk\Tests\Support\FakeTransport;
use Ecuafact\Sdk\Transport\TransportResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RutasPorTipoDocumentoTest extends TestCase
{
    private const ADMISION = '{"estado":"recibido","codEstado":1002,"idOperacion":"op-1"}';
    private const VALIDO = '{"valido":true}';

    private function cliente(FakeTransport $transport): EcuafactClient
    {
        return new EcuafactClient(new EcuafactClientOptions(
            baseAddress: 'https://api.example/',
            apiKey: 'clave-de-prueba',
            identificacion: '0123456789',
            transport: $transport,
            retryBaseDelaySeconds: 0.0,
            retryJitterSeconds: 0.0,
        ));
    }

    private function comprobante(?string $tipo): ComprobanteRequest
    {
        $comprobante = new ComprobanteRequest();
        $comprobante->infoTributaria = new InfoTributaria();
        $comprobante->infoTributaria->ruc = '0123456789001';
        $comprobante->infoTributaria->tipoDocumento = $tipo;
        $comprobante->infoTributaria->estab = '001';
        $comprobante->infoTributaria->ptoEmi = '001';
        return $comprobante;
    }

    /** @return iterable<string, array{string, string}> */
    public static function tipos(): iterable
    {
        yield 'factura' => ['01', 'facturas'];
        yield 'liquidacion de compra' => ['03', 'liquidaciones-compra'];
        yield 'nota de credito' => ['04', 'notas-credito'];
        yield 'nota de debito' => ['05', 'notas-debito'];
        yield 'guia de remision' => ['06', 'guias-remision'];
        yield 'retencion' => ['07', 'retenciones'];
    }

    /** @return iterable<string, array{string}> */
    public static function metodos(): iterable
    {
        yield 'emision' => ['emision'];
        yield 'validacion' => ['validacion'];
    }

    #[DataProvider('tipos')]
    public function testEmisionUsaLaRutaDelTipo(string $tipo, string $segmento): void
    {
        $transport = new FakeTransport(new TransportResponse(202, self::ADMISION));
        $comprobante = $this->comprobante($tipo);

        $this->cliente($transport)->emitirEn('0123456789', $comprobante, 'K-1');

        self::assertCount(1, $transport->peticiones);
        self::assertSame(
            'https://api.example/v1/contribuyentes/0123456789/' . $segmento,
            $transport->peticiones[0]['url']
        );
        $cuerpo = json_decode((string) $transport->peticiones[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('"tipoDocumento"', (string) $transport->peticiones[0]['body']);
        self::assertArrayNotHasKey('tipoDocumento', $cuerpo['infoTributaria']);
        self::assertSame('0123456789001', $cuerpo['infoTributaria']['ruc']);
        self::assertSame($tipo, $comprobante->infoTributaria->tipoDocumento);
    }

    #[DataProvider('tipos')]
    public function testValidacionUsaLaRutaDelTipoMasValidar(string $tipo, string $segmento): void
    {
        $transport = new FakeTransport(new TransportResponse(200, self::VALIDO));
        $comprobante = $this->comprobante($tipo);

        $resultado = $this->cliente($transport)->validarEn('0123456789', $comprobante);

        self::assertTrue($resultado->valido);
        self::assertCount(1, $transport->peticiones);
        self::assertSame(
            'https://api.example/v1/contribuyentes/0123456789/' . $segmento . '/validar',
            $transport->peticiones[0]['url']
        );
        $cuerpo = json_decode((string) $transport->peticiones[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('"tipoDocumento"', (string) $transport->peticiones[0]['body']);
        self::assertArrayNotHasKey('tipoDocumento', $cuerpo['infoTributaria']);
        self::assertSame($tipo, $comprobante->infoTributaria->tipoDocumento);
    }

    public function testEmitirYValidarConIdentificacionPorDefectoUsanLaRutaDelTipo(): void
    {
        $transport = new FakeTransport(
            new TransportResponse(202, self::ADMISION),
            new TransportResponse(200, self::VALIDO)
        );
        $client = $this->cliente($transport);

        $client->emitir($this->comprobante('04'), 'K-1');
        $client->validar($this->comprobante('04'));

        self::assertSame(
            'https://api.example/v1/contribuyentes/0123456789/notas-credito',
            $transport->peticiones[0]['url']
        );
        self::assertSame(
            'https://api.example/v1/contribuyentes/0123456789/notas-credito/validar',
            $transport->peticiones[1]['url']
        );
    }

    #[DataProvider('metodos')]
    public function testSinTipoDocumentoFallaAntesDeEnviar(string $metodo): void
    {
        $this->assertFallaSinLlamar(
            $metodo,
            $this->comprobante(null),
            'infoTributaria.tipoDocumento es obligatorio.'
        );
    }

    #[DataProvider('metodos')]
    public function testSinInfoTributariaFallaAntesDeEnviar(string $metodo): void
    {
        $this->assertFallaSinLlamar(
            $metodo,
            new ComprobanteRequest(),
            'infoTributaria.tipoDocumento es obligatorio.'
        );
    }

    #[DataProvider('metodos')]
    public function testTipoNoSoportadoFallaAntesDeEnviar(string $metodo): void
    {
        $this->assertFallaSinLlamar(
            $metodo,
            $this->comprobante('02'),
            'infoTributaria.tipoDocumento debe ser 01, 03, 04, 05, 06 o 07.'
        );
    }

    private function assertFallaSinLlamar(string $metodo, ComprobanteRequest $comprobante, string $mensaje): void
    {
        $transport = new FakeTransport(
            new TransportResponse(202, self::ADMISION),
            new TransportResponse(200, self::VALIDO)
        );
        $client = $this->cliente($transport);

        try {
            if ($metodo === 'emision') {
                $client->emitirEn('0123456789', $comprobante, 'K-1');
            } else {
                $client->validarEn('0123456789', $comprobante);
            }
            self::fail('Se esperaba EcuafactSdkException.');
        } catch (EcuafactSdkException $error) {
            self::assertSame($mensaje, $error->getMessage());
        }
        self::assertSame([], $transport->peticiones);
    }
}
