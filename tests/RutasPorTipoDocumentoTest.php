<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Tests;

use Ecuafact\Sdk\Contracts\FacturaRequest;
use Ecuafact\Sdk\Contracts\GuiaRemisionRequest;
use Ecuafact\Sdk\Contracts\InfoTributaria;
use Ecuafact\Sdk\Contracts\LiquidacionCompraRequest;
use Ecuafact\Sdk\Contracts\NotaCreditoRequest;
use Ecuafact\Sdk\Contracts\NotaDebitoRequest;
use Ecuafact\Sdk\Contracts\RetencionRequest;
use Ecuafact\Sdk\Contracts\SolicitudComprobante;
use Ecuafact\Sdk\Contracts\TipoComprobante;
use Ecuafact\Sdk\EcuafactClient;
use Ecuafact\Sdk\EcuafactClientOptions;
use Ecuafact\Sdk\EcuafactValidationException;
use Ecuafact\Sdk\RequestOptions;
use Ecuafact\Sdk\Tests\Support\FakeTransport;
use Ecuafact\Sdk\Transport\TransportResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cada tipo de comprobante tiene su clase de solicitud y esa clase fija la ruta: no hay tipo en el cuerpo ni en las
 * opciones de la llamada.
 */
final class RutasPorTipoDocumentoTest extends TestCase
{
    private const ADMISION = '{"estado":"recibido","codEstado":1002,"idOperacion":"op-1"}';
    private const VALIDO = '{"valido":true}';
    private const INVALIDO = '{"estado":"rechazado","codEstado":3002,"msgError":"El comprobante no es valido.","errores":["$.info: mal"]}';

    private function cliente(FakeTransport $transport, bool $validarAntes = false): EcuafactClient
    {
        return new EcuafactClient(new EcuafactClientOptions(
            baseAddress: 'https://api.example/',
            apiKey: 'clave-de-prueba',
            identificacion: '0123456789',
            transport: $transport,
            retryBaseDelaySeconds: 0.0,
            retryJitterSeconds: 0.0,
            validarAntesDeEmitir: $validarAntes,
        ));
    }

    private function solicitud(string $clase): SolicitudComprobante
    {
        $solicitud = new $clase();
        $solicitud->origenReferencia = 'MiERP';
        $solicitud->referenciaExterna = 'REF-1';
        $solicitud->infoTributaria = new InfoTributaria();
        $solicitud->infoTributaria->ruc = '0123456789001';
        $solicitud->infoTributaria->estab = '001';
        $solicitud->infoTributaria->ptoEmi = '001';
        return $solicitud;
    }

    /** @return iterable<string, array{class-string<SolicitudComprobante>, TipoComprobante, string}> */
    public static function tipos(): iterable
    {
        yield 'factura' => [FacturaRequest::class, TipoComprobante::Factura, 'facturas'];
        yield 'liquidacion de compra' => [LiquidacionCompraRequest::class, TipoComprobante::LiquidacionCompra, 'liquidaciones-compra'];
        yield 'nota de credito' => [NotaCreditoRequest::class, TipoComprobante::NotaCredito, 'notas-credito'];
        yield 'nota de debito' => [NotaDebitoRequest::class, TipoComprobante::NotaDebito, 'notas-debito'];
        yield 'guia de remision' => [GuiaRemisionRequest::class, TipoComprobante::GuiaRemision, 'guias-remision'];
        yield 'retencion' => [RetencionRequest::class, TipoComprobante::Retencion, 'retenciones'];
    }

    /**
     * @param class-string<SolicitudComprobante> $clase
     */
    #[DataProvider('tipos')]
    public function testEmisionUsaLaRutaDeLaClaseYEnviaElCuerpoIntegro(string $clase, TipoComprobante $tipo, string $segmento): void
    {
        $transport = new FakeTransport(new TransportResponse(202, self::ADMISION));
        $solicitud = $this->solicitud($clase);

        $this->cliente($transport)->emitirEn('0123456789', $solicitud, 'K-1');

        self::assertSame($tipo, $solicitud->tipoComprobante());
        self::assertCount(1, $transport->peticiones);
        self::assertSame(
            'https://api.example/v1/contribuyentes/0123456789/' . $segmento,
            $transport->peticiones[0]['url']
        );
        self::assertSame(
            '{"origenReferencia":"MiERP","referenciaExterna":"REF-1","infoTributaria":'
            . '{"ruc":"0123456789001","estab":"001","ptoEmi":"001"}}',
            $transport->peticiones[0]['body']
        );
        self::assertStringNotContainsString('tipoDocumento', (string) $transport->peticiones[0]['body']);
    }

    /**
     * @param class-string<SolicitudComprobante> $clase
     */
    #[DataProvider('tipos')]
    public function testValidacionUsaLaRutaDeLaClaseMasValidarYEnviaElCuerpoIntegro(string $clase, TipoComprobante $tipo, string $segmento): void
    {
        $transport = new FakeTransport(new TransportResponse(200, self::VALIDO));

        $resultado = $this->cliente($transport)->validarEn('0123456789', $this->solicitud($clase));

        self::assertTrue($resultado->valido);
        self::assertCount(1, $transport->peticiones);
        self::assertSame(
            'https://api.example/v1/contribuyentes/0123456789/' . $segmento . '/validar',
            $transport->peticiones[0]['url']
        );
        self::assertSame(
            '{"origenReferencia":"MiERP","referenciaExterna":"REF-1","infoTributaria":'
            . '{"ruc":"0123456789001","estab":"001","ptoEmi":"001"}}',
            $transport->peticiones[0]['body']
        );
    }

    public function testEmitirYValidarConIdentificacionPorDefectoUsanLaRutaDelTipo(): void
    {
        $transport = new FakeTransport(
            new TransportResponse(202, self::ADMISION),
            new TransportResponse(200, self::VALIDO)
        );
        $client = $this->cliente($transport);

        $client->emitir($this->solicitud(NotaCreditoRequest::class), 'K-1');
        $client->validar($this->solicitud(NotaCreditoRequest::class));
        $client->para('0123456789')->emitir($this->solicitud(GuiaRemisionRequest::class), 'K-2');

        self::assertSame('https://api.example/v1/contribuyentes/0123456789/notas-credito', $transport->peticiones[0]['url']);
        self::assertSame('https://api.example/v1/contribuyentes/0123456789/notas-credito/validar', $transport->peticiones[1]['url']);
        self::assertSame('https://api.example/v1/contribuyentes/0123456789/guias-remision', $transport->peticiones[2]['url']);
    }

    /**
     * @param class-string<SolicitudComprobante> $clase
     */
    #[DataProvider('tipos')]
    public function testSinValidarAntesEmitirHaceUnaSolaPeticion(string $clase, TipoComprobante $tipo, string $segmento): void
    {
        $transport = new FakeTransport(new TransportResponse(202, self::ADMISION));

        $this->cliente($transport)->emitirEn('0123456789', $this->solicitud($clase), 'K-1');

        self::assertCount(1, $transport->peticiones);
    }

    /**
     * @param class-string<SolicitudComprobante> $clase
     */
    #[DataProvider('tipos')]
    public function testValidarAntesDeEmitirDelClienteHaceDosPeticionesEnLaRutaDelTipo(string $clase, TipoComprobante $tipo, string $segmento): void
    {
        $transport = new FakeTransport(
            new TransportResponse(200, '{"valido":true,"advertencias":["aviso"]}'),
            new TransportResponse(202, self::ADMISION)
        );

        $resultado = $this->cliente($transport, true)->emitirEn('0123456789', $this->solicitud($clase), 'K-1');

        self::assertCount(2, $transport->peticiones);
        self::assertSame('https://api.example/v1/contribuyentes/0123456789/' . $segmento . '/validar', $transport->peticiones[0]['url']);
        self::assertSame('https://api.example/v1/contribuyentes/0123456789/' . $segmento, $transport->peticiones[1]['url']);
        self::assertSame($transport->peticiones[0]['body'], $transport->peticiones[1]['body']);
        self::assertSame(['aviso'], $resultado->advertencias);
    }

    public function testValidarAntesPorLlamadaGanaSobreElCliente(): void
    {
        $transport = new FakeTransport(new TransportResponse(202, self::ADMISION));
        $this->cliente($transport, true)->emitirEn(
            '0123456789',
            $this->solicitud(FacturaRequest::class),
            'K-1',
            new RequestOptions(validarAntes: false)
        );
        self::assertCount(1, $transport->peticiones);

        $transport = new FakeTransport(new TransportResponse(200, self::VALIDO), new TransportResponse(202, self::ADMISION));
        $this->cliente($transport, false)->emitirEn(
            '0123456789',
            $this->solicitud(FacturaRequest::class),
            'K-2',
            new RequestOptions(validarAntes: true)
        );
        self::assertCount(2, $transport->peticiones);
    }

    public function testValidarAntesInvalidoNoEmite(): void
    {
        $transport = new FakeTransport(new TransportResponse(422, self::INVALIDO));
        try {
            $this->cliente($transport, true)->emitirEn('0123456789', $this->solicitud(RetencionRequest::class), 'K-1');
            self::fail('Se esperaba EcuafactValidationException.');
        } catch (EcuafactValidationException $error) {
            self::assertSame(['$.info: mal'], $error->errores);
        }
        self::assertCount(1, $transport->peticiones);
        self::assertStringEndsWith('/retenciones/validar', $transport->peticiones[0]['url']);
    }

    public function testElTipoSalePorLaClaseYNoPorLasOpcionesDeLaLlamada(): void
    {
        self::assertFalse(property_exists(RequestOptions::class, 'tipo'));
        self::assertFalse(property_exists(InfoTributaria::class, 'tipoDocumento'));
    }
}
