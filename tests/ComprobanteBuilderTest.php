<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Tests;

use Ecuafact\Sdk\Builders\ComprobanteBuilder;
use Ecuafact\Sdk\Builders\FacturaBuilder;
use Ecuafact\Sdk\Builders\Tarifa;
use Ecuafact\Sdk\Contracts\DetalleGuia;
use Ecuafact\Sdk\Contracts\GuiaRemisionRequest;
use Ecuafact\Sdk\Contracts\InfoGuiaRemision;
use Ecuafact\Sdk\Contracts\SolicitudComprobante;
use Ecuafact\Sdk\EcuafactSdkException;
use Ecuafact\Sdk\Support\DecimalMath;
use Ecuafact\Sdk\Support\Serialization;
use PHPUnit\Framework\TestCase;

/**
 * Los constructores solo arman el cuerpo con los valores del usuario: no calculan totales, impuestos ni
 * redondeos, y no completan valores por defecto. El calculo y la validacion viven en el API.
 */
final class ComprobanteBuilderTest extends TestCase
{
    private const RUC = '0123456789001';

    public function testFacturaArmaElCuerpoConLosValoresDados(): void
    {
        $factura = ComprobanteBuilder::factura(self::RUC, '002', '001')
            ->referencias('MiERP', 'FACTURA-2026-0001')
            ->fechaEmision('07/10/2026')
            ->comprador('05', '0123456789', 'CONTRIBUYENTE DE PRUEBAS', 'DIRECCION DE PRUEBAS')
            ->moneda('DOLAR')
            ->linea('SERV-001', 'CONSULTORIA', 3, '12.50', '2.50', Tarifa::iva('4', 15))
            ->totalSinImpuestos('35.00')
            ->totalDescuento('2.50')
            ->importeTotal('40.25')
            ->pago('01', '40.25')
            ->infoAdicional('Pedido', 'PED-2026-0457')
            ->construir();

        self::assertJsonIgual(<<<'JSON'
{
  "origenReferencia": "MiERP",
  "referenciaExterna": "FACTURA-2026-0001",
  "infoTributaria": { "ruc": "0123456789001", "estab": "002", "ptoEmi": "001" },
  "info": {
    "fechaEmision": "07/10/2026",
    "tipoIdentificacionComprador": "05",
    "identificacionComprador": "0123456789",
    "razonSocialComprador": "CONTRIBUYENTE DE PRUEBAS",
    "direccionComprador": "DIRECCION DE PRUEBAS",
    "moneda": "DOLAR",
    "totalSinImpuestos": 35.00,
    "totalDescuento": 2.50,
    "importeTotal": 40.25,
    "pagos": [ { "formaPago": "01", "total": 40.25 } ]
  },
  "detalles": [
    { "codigoPrincipal": "SERV-001", "descripcion": "CONSULTORIA", "cantidad": 3, "precioUnitario": 12.50,
      "descuento": 2.50,
      "impuestos": [ { "codigo": "2", "codigoPorcentaje": "4", "tarifa": 15 } ] }
  ],
  "infoAdicional": [ { "nombre": "Pedido", "valor": "PED-2026-0457" } ]
}
JSON, $factura);
    }

    public function testNoCalculaNiCompletaNada(): void
    {
        $json = json_decode(Serialization::encode(
            $this->base('F-1')->linea('A', 'B', 1, '10.00', 0, Tarifa::iva('4', 15))->pago('01')->construir()
        ), true);
        self::assertArrayNotHasKey('precioTotalSinImpuesto', $json['detalles'][0]);
        self::assertArrayNotHasKey('baseImponible', $json['detalles'][0]['impuestos'][0]);
        self::assertArrayNotHasKey('valor', $json['detalles'][0]['impuestos'][0]);
        foreach (['importeTotal', 'totalSinImpuestos', 'totalDescuento', 'totalConImpuestos', 'moneda', 'propina'] as $campo) {
            self::assertArrayNotHasKey($campo, $json['info'], $campo);
        }
        self::assertArrayNotHasKey('total', $json['info']['pagos'][0]);
    }

    public function testNoExisteElCalculoLocal(): void
    {
        self::assertFalse(method_exists(FacturaBuilder::class, 'calcularTotales'));
        self::assertFalse(method_exists(ComprobanteBuilder::class, 'calcularTotales'));
        self::assertFalse(method_exists(ComprobanteBuilder::class, 'desde'));
        self::assertFalse(class_exists('Ecuafact\\Sdk\\Builders\\Precision'));
        self::assertFalse(class_exists('Ecuafact\\Sdk\\Builders\\CalculadoraComprobante'));
        self::assertFalse(enum_exists('Ecuafact\\Sdk\\Builders\\Iva'));
        self::assertFalse(enum_exists('Ecuafact\\Sdk\\Builders\\ModoRedondeo'));
    }

    public function testImportesConservanPrecisionSinPerdida(): void
    {
        $r = $this->base('F-2')
            ->linea('A', 'B', '3', '1.0112', '0.000001', Tarifa::iva('4', '15.00'))
            ->totalSinImpuestos('99999999999999.999999999999')
            ->construir();
        self::assertSame('1.0112', $r->detalles[0]->precioUnitario);
        self::assertSame('0.000001', $r->detalles[0]->descuento);
        self::assertSame('15.00', $r->detalles[0]->impuestos[0]->tarifa);
        self::assertSame('99999999999999.999999999999', $r->info->totalSinImpuestos);
        self::assertStringContainsString('"totalSinImpuestos":99999999999999.999999999999', Serialization::encode($r));
    }

    public function testFloatsSeConviertenSinErrorBinario(): void
    {
        $r = $this->base('F-3')->linea('A', 'B', 1, 0.1 + 0.2, 0, Tarifa::iva('0', 0))->construir();
        self::assertSame('0.3', $r->detalles[0]->precioUnitario);
    }

    public function testConstruirDevuelveCopiasIndependientes(): void
    {
        $builder = $this->base('F-4')->linea('A', 'B', 1, '10.00', 0, Tarifa::iva('4', 15))->importeTotal('11.50');
        $primera = $builder->construir();
        $primera->info->importeTotal = '0';
        $builder->linea('C', 'D', 1, '5.00', 0, Tarifa::iva('4', 15));
        $segunda = $builder->construir();
        self::assertSame('11.50', $segunda->info->importeTotal);
        self::assertCount(2, $segunda->detalles);
        self::assertCount(1, $primera->detalles);
    }

    public function testNotaDebitoYNotaCreditoSoloEnsamblan(): void
    {
        $nd = ComprobanteBuilder::notaDebito(self::RUC, '001', '001')
            ->fechaEmision('07/10/2026')
            ->comprador('05', '0123456789', 'CONTRIBUYENTE')
            ->documentoModificado('01', '002-001-000000123', '01/10/2026')
            ->motivo('INTERESES', '10.00')
            ->iva(Tarifa::iva('4', 15))
            ->valorTotal('11.50')
            ->construir();
        self::assertSame('11.50', $nd->info->valorTotal);
        self::assertNull($nd->info->impuestos[0]->baseImponible);
        self::assertNull($nd->info->impuestos[0]->valor);
        self::assertSame('10.00', $nd->motivos[0]->valor);
        self::assertNull($nd->info->moneda);

        $nc = ComprobanteBuilder::notaCredito(self::RUC, '001', '001')
            ->motivo('DEVOLUCION')
            ->valorModificacion('5.00')
            ->linea('SERV-001', 'DEVOLUCION', 1, '5.00', 0, Tarifa::iva('4', 15))
            ->construir();
        self::assertSame('5.00', $nc->info->valorModificacion);
        self::assertSame('SERV-001', $nc->detalles[0]->codigoInterno);
        self::assertNull($nc->detalles[0]->precioTotalSinImpuesto);
        self::assertNull($nc->info->moneda);
    }

    public function testRetencionNoCompletaPagoLocExtNiImportes(): void
    {
        $r = ComprobanteBuilder::retencion(self::RUC, '001', '001')
            ->docSustento('01', '01', '002-001-000000123', '01/10/2026', '01/10/2026')
            ->impuestoSustento('2', '4', '100.00', 15)
            ->lineaRetencion('1', '303', '100.00', 10)
            ->construir();
        $doc = $r->docsSustento[0];
        self::assertNull($doc->pagoLocExt);
        self::assertNull($doc->totalSinImpuestos);
        self::assertNull($doc->importeTotal);
        self::assertNull($doc->impuestosDocSustento[0]->valorImpuesto);
        self::assertNull($doc->retenciones[0]->valorRetenido);
        $json = Serialization::encode($r);
        self::assertStringNotContainsString('pagoLocExt', $json);
        self::assertStringNotContainsString('valorRetenido', $json);

        $conValor = ComprobanteBuilder::retencion(self::RUC, '001', '001')
            ->docSustento('01', '01', '002-001-000000123', '01/10/2026', '01/10/2026', '100.00', '115.00', null, '02')
            ->construir();
        self::assertSame('02', $conValor->docsSustento[0]->pagoLocExt);
        self::assertSame('115.00', $conValor->docsSustento[0]->importeTotal);
    }

    public function testGuiaDeRemisionEnsambla(): void
    {
        $g = ComprobanteBuilder::guiaRemision(self::RUC, '001', '001')
            ->transportista('05', '0123456789', 'TRANSPORTISTA', 'ABC1234')
            ->traslado('DIR', '07/10/2026', '08/10/2026')
            ->destinatario('0123456789', 'DEST', 'DIR DEST', 'VENTA')
            ->linea('P-1', 'PRODUCTO', 2)
            ->construir();
        self::assertInstanceOf(GuiaRemisionRequest::class, $g);
        self::assertInstanceOf(InfoGuiaRemision::class, $g->info);
        self::assertInstanceOf(DetalleGuia::class, $g->destinatarios[0]->detalles[0]);
        self::assertSame('2', $g->destinatarios[0]->detalles[0]->cantidad);
    }

    public function testLineaRechazaImportesNoDecimales(): void
    {
        $this->expectException(EcuafactSdkException::class);
        $this->base('F-9')->linea('A', 'B', 1, '10,50', 0, Tarifa::iva('4', 15));
    }

    public function testTarifaRechazaValoresInvalidos(): void
    {
        $this->expectException(EcuafactSdkException::class);
        Tarifa::iva('4', 'quince');
    }

    public function testRetencionSinDocSustentoLanza(): void
    {
        $this->expectException(EcuafactSdkException::class);
        ComprobanteBuilder::retencion(self::RUC, '001', '001')->lineaRetencion('1', '303', '1.00', 1);
    }

    public function testNormalizarImportes(): void
    {
        self::assertSame('7.50', DecimalMath::normalizar('+007.50'));
        self::assertSame('-0.5', DecimalMath::normalizar('-0.5'));
        self::assertSame('0', DecimalMath::normalizar('-0'));
        self::assertTrue(DecimalMath::esNumero('1.5'));
        self::assertFalse(DecimalMath::esNumero('1e5'));
        self::assertFalse(DecimalMath::esNumero(NAN));
    }

    // ------------------------------------------------------------------ apoyo

    private function base(string $referencia): FacturaBuilder
    {
        return ComprobanteBuilder::factura(self::RUC, '002', '001')
            ->referencias('MiERP', $referencia)
            ->fechaEmision('07/10/2026')
            ->comprador('05', '0123456789', 'CONTRIBUYENTE DE PRUEBAS');
    }

    /** Compara el JSON que enviaria el SDK con el esperado: mismas claves y mismos valores numericos. */
    private static function assertJsonIgual(string $esperado, SolicitudComprobante $comprobante): void
    {
        $enviado = Serialization::encode($comprobante);
        $a = json_decode($esperado, true, 512, JSON_THROW_ON_ERROR);
        $b = json_decode($enviado, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(self::canonico($a), self::canonico($b), $enviado);
    }

    private static function canonico(mixed $valor): mixed
    {
        if (is_array($valor)) {
            $out = [];
            foreach ($valor as $k => $v) {
                $out[$k] = self::canonico($v);
            }
            if (!array_is_list($out)) {
                ksort($out);
            }
            return $out;
        }
        if (is_int($valor) || is_float($valor)) {
            return 'n:' . DecimalMath::normalizar($valor);
        }
        return $valor;
    }
}
