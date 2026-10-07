<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Tests;

use Ecuafact\Sdk\Builders\ComprobanteBuilder;
use Ecuafact\Sdk\Builders\FacturaBuilder;
use Ecuafact\Sdk\Builders\Inconsistencia;
use Ecuafact\Sdk\Builders\Iva;
use Ecuafact\Sdk\Builders\ModoRedondeo;
use Ecuafact\Sdk\Builders\Precision;
use Ecuafact\Sdk\Builders\Tarifa;
use Ecuafact\Sdk\Contracts\ComprobanteRequest;
use Ecuafact\Sdk\Contracts\Detalle;
use Ecuafact\Sdk\Contracts\Impuesto;
use Ecuafact\Sdk\EcuafactConfigurationException;
use Ecuafact\Sdk\EcuafactSdkException;
use Ecuafact\Sdk\Support\DecimalMath;
use Ecuafact\Sdk\Support\Serialization;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Los JSON esperados son los de las guias `emision.md` ("Como calcular los totales") y
 * `emision-01.md` a `emision-07.md` (copiados literalmente).
 */
final class ComprobanteBuilderTest extends TestCase
{
    private const RUC = '0123456789001';

    protected function tearDown(): void
    {
        DecimalMath::usarBcmath(null);
    }

    /** @return iterable<string, array{bool}> */
    public static function motores(): iterable
    {
        yield 'bcmath' => [true];
        yield 'aritmetica propia' => [false];
    }

    // ------------------------------------------------------------------ guias

    #[DataProvider('motores')]
    public function testComoCalcularLosTotales(bool $bcmath): void
    {
        DecimalMath::usarBcmath($bcmath);
        $factura = ComprobanteBuilder::factura(self::RUC, '002', '001')
            ->referencias('MiERP', 'FACTURA-2026-0001')
            ->fechaEmision('07/10/2026')
            ->comprador('05', '0123456789', 'CONTRIBUYENTE DE PRUEBAS', 'DIRECCION DE PRUEBAS')
            ->linea('SERV-001', 'CONSULTORIA', 3, '12.50', '2.50', Tarifa::iva15())
            ->linea('REP-002', 'REPUESTO', 3, '1.0112', 0, Tarifa::iva15())
            ->linea('LIB-003', 'LIBRO', 2, '4.35', 0, Tarifa::iva0())
            ->pago('01', '20.00')
            ->pago('19')
            ->infoAdicional('Pedido', 'PED-2026-0457')
            ->calcularTotales();

        self::assertJsonIgual(<<<'JSON'
{
  "origenReferencia": "MiERP",
  "referenciaExterna": "FACTURA-2026-0001",
  "infoTributaria": { "ruc": "0123456789001", "codDoc": "01", "estab": "002", "ptoEmi": "001" },
  "info": {
    "fechaEmision": "07/10/2026",
    "tipoIdentificacionComprador": "05",
    "identificacionComprador": "0123456789",
    "razonSocialComprador": "CONTRIBUYENTE DE PRUEBAS",
    "direccionComprador": "DIRECCION DE PRUEBAS",
    "totalSinImpuestos": 46.73,
    "totalDescuento": 2.50,
    "totalConImpuestos": [
      { "codigo": "2", "codigoPorcentaje": "4", "tarifa": 15, "baseImponible": 38.03, "valor": 5.71 },
      { "codigo": "2", "codigoPorcentaje": "0", "tarifa": 0, "baseImponible": 8.70, "valor": 0 }
    ],
    "propina": 0,
    "importeTotal": 52.44,
    "moneda": "DOLAR",
    "pagos": [
      { "formaPago": "01", "total": 20.00 },
      { "formaPago": "19", "total": 32.44 }
    ]
  },
  "detalles": [
    { "codigoPrincipal": "SERV-001", "descripcion": "CONSULTORIA", "cantidad": 3, "precioUnitario": 12.50,
      "descuento": 2.50, "precioTotalSinImpuesto": 35.00,
      "impuestos": [ { "codigo": "2", "codigoPorcentaje": "4", "tarifa": 15, "baseImponible": 35.00, "valor": 5.25 } ] },
    { "codigoPrincipal": "REP-002", "descripcion": "REPUESTO", "cantidad": 3, "precioUnitario": 1.0112,
      "descuento": 0, "precioTotalSinImpuesto": 3.03,
      "impuestos": [ { "codigo": "2", "codigoPorcentaje": "4", "tarifa": 15, "baseImponible": 3.03, "valor": 0.46 } ] },
    { "codigoPrincipal": "LIB-003", "descripcion": "LIBRO", "cantidad": 2, "precioUnitario": 4.35,
      "descuento": 0, "precioTotalSinImpuesto": 8.70,
      "impuestos": [ { "codigo": "2", "codigoPorcentaje": "0", "tarifa": 0, "baseImponible": 8.70, "valor": 0 } ] }
  ],
  "infoAdicional": [ { "nombre": "Pedido", "valor": "PED-2026-0457" } ]
}
JSON, $factura->construir());
        self::assertSame([], $factura->validar());
    }

    #[DataProvider('motores')]
    public function testFacturaEjemploMinimo(bool $bcmath): void
    {
        DecimalMath::usarBcmath($bcmath);
        $factura = ComprobanteBuilder::factura(self::RUC, '002', '001')
            ->referencias('MiERP', 'FACTURA-2026-0002')
            ->fechaEmision(new \DateTimeImmutable('2026-10-07'))
            ->comprador('05', '0123456789', 'CONTRIBUYENTE DE PRUEBAS', 'DIRECCION DE PRUEBAS')
            ->linea('SERV-001', 'SERVICIO DE MANTENIMIENTO', 1, 100.00, 0, Tarifa::iva15())
            ->pago('01')
            ->infoAdicional('Orden', 'OC-1001')
            ->calcularTotales();

        self::assertJsonIgual(<<<'JSON'
{
  "origenReferencia": "MiERP",
  "referenciaExterna": "FACTURA-2026-0002",
  "infoTributaria": { "ruc": "0123456789001", "codDoc": "01", "estab": "002", "ptoEmi": "001" },
  "info": {
    "fechaEmision": "07/10/2026",
    "tipoIdentificacionComprador": "05",
    "identificacionComprador": "0123456789",
    "razonSocialComprador": "CONTRIBUYENTE DE PRUEBAS",
    "direccionComprador": "DIRECCION DE PRUEBAS",
    "totalSinImpuestos": 100.00,
    "totalDescuento": 0,
    "totalConImpuestos": [
      { "codigo": "2", "codigoPorcentaje": "4", "tarifa": 15, "baseImponible": 100.00, "valor": 15.00 }
    ],
    "propina": 0,
    "importeTotal": 115.00,
    "moneda": "DOLAR",
    "pagos": [ { "formaPago": "01", "total": 115.00 } ]
  },
  "detalles": [
    { "codigoPrincipal": "SERV-001", "descripcion": "SERVICIO DE MANTENIMIENTO", "cantidad": 1, "precioUnitario": 100.00,
      "descuento": 0, "precioTotalSinImpuesto": 100.00,
      "impuestos": [ { "codigo": "2", "codigoPorcentaje": "4", "tarifa": 15, "baseImponible": 100.00, "valor": 15.00 } ] }
  ],
  "infoAdicional": [ { "nombre": "Orden", "valor": "OC-1001" } ]
}
JSON, $factura->construir());
        self::assertSame([], $factura->validar());
    }

    public function testFacturaVariasTarifas(): void
    {
        $factura = $this->base('FACTURA-2026-0003')
            ->linea('SERV-010', 'SOPORTE TECNICO POR HORA', 2, '25.00', 0, Iva::TARIFA_15)
            ->linea('LIB-020', 'LIBRO TECNICO', 1, '10.00', 0, Iva::TARIFA_0)
            ->pago('01')
            ->calcularTotales();

        self::assertJsonIgual(<<<'JSON'
{
  "origenReferencia": "MiERP",
  "referenciaExterna": "FACTURA-2026-0003",
  "infoTributaria": { "ruc": "0123456789001", "codDoc": "01", "estab": "002", "ptoEmi": "001" },
  "info": {
    "fechaEmision": "07/10/2026",
    "tipoIdentificacionComprador": "05",
    "identificacionComprador": "0123456789",
    "razonSocialComprador": "CONTRIBUYENTE DE PRUEBAS",
    "totalSinImpuestos": 60.00,
    "totalDescuento": 0,
    "totalConImpuestos": [
      { "codigo": "2", "codigoPorcentaje": "4", "tarifa": 15, "baseImponible": 50.00, "valor": 7.50 },
      { "codigo": "2", "codigoPorcentaje": "0", "tarifa": 0, "baseImponible": 10.00, "valor": 0 }
    ],
    "propina": 0,
    "importeTotal": 67.50,
    "moneda": "DOLAR",
    "pagos": [ { "formaPago": "01", "total": 67.50 } ]
  },
  "detalles": [
    { "codigoPrincipal": "SERV-010", "descripcion": "SOPORTE TECNICO POR HORA", "cantidad": 2, "precioUnitario": 25.00,
      "descuento": 0, "precioTotalSinImpuesto": 50.00,
      "impuestos": [ { "codigo": "2", "codigoPorcentaje": "4", "tarifa": 15, "baseImponible": 50.00, "valor": 7.50 } ] },
    { "codigoPrincipal": "LIB-020", "descripcion": "LIBRO TECNICO", "cantidad": 1, "precioUnitario": 10.00,
      "descuento": 0, "precioTotalSinImpuesto": 10.00,
      "impuestos": [ { "codigo": "2", "codigoPorcentaje": "0", "tarifa": 0, "baseImponible": 10.00, "valor": 0 } ] }
  ]
}
JSON, $factura->construir());
    }

    public function testFacturaConDescuento(): void
    {
        $factura = $this->base('FACTURA-2026-0004')
            ->linea('PROD-030', 'CAJA DE PAPEL A4', 3, '12.50', '3.75', Tarifa::iva15())
            ->pago('19')
            ->calcularTotales();

        self::assertJsonIgual(<<<'JSON'
{
  "origenReferencia": "MiERP",
  "referenciaExterna": "FACTURA-2026-0004",
  "infoTributaria": { "ruc": "0123456789001", "codDoc": "01", "estab": "002", "ptoEmi": "001" },
  "info": {
    "fechaEmision": "07/10/2026",
    "tipoIdentificacionComprador": "05",
    "identificacionComprador": "0123456789",
    "razonSocialComprador": "CONTRIBUYENTE DE PRUEBAS",
    "totalSinImpuestos": 33.75,
    "totalDescuento": 3.75,
    "totalConImpuestos": [
      { "codigo": "2", "codigoPorcentaje": "4", "tarifa": 15, "baseImponible": 33.75, "valor": 5.06 }
    ],
    "propina": 0,
    "importeTotal": 38.81,
    "moneda": "DOLAR",
    "pagos": [ { "formaPago": "19", "total": 38.81 } ]
  },
  "detalles": [
    { "codigoPrincipal": "PROD-030", "descripcion": "CAJA DE PAPEL A4", "cantidad": 3, "precioUnitario": 12.50,
      "descuento": 3.75, "precioTotalSinImpuesto": 33.75,
      "impuestos": [ { "codigo": "2", "codigoPorcentaje": "4", "tarifa": 15, "baseImponible": 33.75, "valor": 5.06 } ] }
  ]
}
JSON, $factura->construir());
    }

    public function testFacturaPagoACreditoConPlazo(): void
    {
        $factura = $this->base('FACTURA-2026-0005')
            ->linea('EQ-040', 'IMPRESORA LASER', 1, '200.00', 0, Tarifa::iva15())
            ->pago('19', '50.00')
            ->pago('01', null, 30, 'dias')
            ->calcularTotales();

        self::assertJsonIgual(<<<'JSON'
{
  "origenReferencia": "MiERP",
  "referenciaExterna": "FACTURA-2026-0005",
  "infoTributaria": { "ruc": "0123456789001", "codDoc": "01", "estab": "002", "ptoEmi": "001" },
  "info": {
    "fechaEmision": "07/10/2026",
    "tipoIdentificacionComprador": "05",
    "identificacionComprador": "0123456789",
    "razonSocialComprador": "CONTRIBUYENTE DE PRUEBAS",
    "totalSinImpuestos": 200.00,
    "totalDescuento": 0,
    "totalConImpuestos": [
      { "codigo": "2", "codigoPorcentaje": "4", "tarifa": 15, "baseImponible": 200.00, "valor": 30.00 }
    ],
    "propina": 0,
    "importeTotal": 230.00,
    "moneda": "DOLAR",
    "pagos": [
      { "formaPago": "19", "total": 50.00 },
      { "formaPago": "01", "total": 180.00, "plazo": 30, "unidadTiempo": "dias" }
    ]
  },
  "detalles": [
    { "codigoPrincipal": "EQ-040", "descripcion": "IMPRESORA LASER", "cantidad": 1, "precioUnitario": 200.00,
      "descuento": 0, "precioTotalSinImpuesto": 200.00,
      "impuestos": [ { "codigo": "2", "codigoPorcentaje": "4", "tarifa": 15, "baseImponible": 200.00, "valor": 30.00 } ] }
  ]
}
JSON, $factura->construir());
    }

    #[DataProvider('motores')]
    public function testFacturaProductoConIce(bool $bcmath): void
    {
        DecimalMath::usarBcmath($bcmath);
        $factura = $this->base('FACTURA-2026-0006')
            ->linea('PRD-ICE-01', 'PRODUCTO GRAVADO CON ICE', 1, '100.00', 0, Tarifa::iva15(), Tarifa::ice('3011', 10))
            ->pago('01')
            ->calcularTotales();

        self::assertJsonIgual(<<<'JSON'
{
  "origenReferencia": "MiERP",
  "referenciaExterna": "FACTURA-2026-0006",
  "infoTributaria": { "ruc": "0123456789001", "codDoc": "01", "estab": "002", "ptoEmi": "001" },
  "info": {
    "fechaEmision": "07/10/2026",
    "tipoIdentificacionComprador": "05",
    "identificacionComprador": "0123456789",
    "razonSocialComprador": "CONTRIBUYENTE DE PRUEBAS",
    "totalSinImpuestos": 100.00,
    "totalDescuento": 0,
    "totalConImpuestos": [
      { "codigo": "2", "codigoPorcentaje": "4", "tarifa": 15, "baseImponible": 110.00, "valor": 16.50 },
      { "codigo": "3", "codigoPorcentaje": "3011", "tarifa": 10, "baseImponible": 100.00, "valor": 10.00 }
    ],
    "propina": 0,
    "importeTotal": 126.50,
    "moneda": "DOLAR",
    "pagos": [ { "formaPago": "01", "total": 126.50 } ]
  },
  "detalles": [
    {
      "codigoPrincipal": "PRD-ICE-01",
      "descripcion": "PRODUCTO GRAVADO CON ICE",
      "cantidad": 1,
      "precioUnitario": 100.00,
      "descuento": 0,
      "precioTotalSinImpuesto": 100.00,
      "impuestos": [
        { "codigo": "3", "codigoPorcentaje": "3011", "tarifa": 10, "baseImponible": 100.00, "valor": 10.00 },
        { "codigo": "2", "codigoPorcentaje": "4", "tarifa": 15, "baseImponible": 110.00, "valor": 16.50 }
      ]
    }
  ]
}
JSON, $factura->construir());
        self::assertSame([], $factura->validar());
    }

    public function testLiquidacionDeCompra(): void
    {
        $liquidacion = ComprobanteBuilder::liquidacionCompra(self::RUC, '002', '001')
            ->referencias('MiERP', 'LIQUIDACION-2026-0001')
            ->fechaEmision('07/10/2026')
            ->proveedor('05', '0123456789', 'PROVEEDOR DE PRUEBAS', 'DIRECCION DE PRUEBAS')
            ->linea('MAIZ-001', 'MAIZ EN GRANO (KG)', 50, '1.20', 0, Tarifa::iva0())
            ->linea('SERV-002', 'SERVICIO DE SECADO DE GRANO', 1, '20.00', 0, Tarifa::iva15())
            ->pago('01')
            ->infoAdicional('Lote', 'COSECHA-2026-10')
            ->calcularTotales();

        self::assertJsonIgual(<<<'JSON'
{
  "origenReferencia": "MiERP",
  "referenciaExterna": "LIQUIDACION-2026-0001",
  "infoTributaria": { "ruc": "0123456789001", "codDoc": "03", "estab": "002", "ptoEmi": "001" },
  "info": {
    "fechaEmision": "07/10/2026",
    "tipoIdentificacionProveedor": "05",
    "identificacionProveedor": "0123456789",
    "razonSocialProveedor": "PROVEEDOR DE PRUEBAS",
    "direccionProveedor": "DIRECCION DE PRUEBAS",
    "totalSinImpuestos": 80.00,
    "totalDescuento": 0,
    "totalConImpuestos": [
      { "codigo": "2", "codigoPorcentaje": "0", "tarifa": 0, "baseImponible": 60.00, "valor": 0 },
      { "codigo": "2", "codigoPorcentaje": "4", "tarifa": 15, "baseImponible": 20.00, "valor": 3.00 }
    ],
    "importeTotal": 83.00,
    "moneda": "DOLAR",
    "pagos": [ { "formaPago": "01", "total": 83.00 } ]
  },
  "detalles": [
    { "codigoPrincipal": "MAIZ-001", "descripcion": "MAIZ EN GRANO (KG)", "cantidad": 50, "precioUnitario": 1.20,
      "descuento": 0, "precioTotalSinImpuesto": 60.00,
      "impuestos": [ { "codigo": "2", "codigoPorcentaje": "0", "tarifa": 0, "baseImponible": 60.00, "valor": 0 } ] },
    { "codigoPrincipal": "SERV-002", "descripcion": "SERVICIO DE SECADO DE GRANO", "cantidad": 1, "precioUnitario": 20.00,
      "descuento": 0, "precioTotalSinImpuesto": 20.00,
      "impuestos": [ { "codigo": "2", "codigoPorcentaje": "4", "tarifa": 15, "baseImponible": 20.00, "valor": 3.00 } ] }
  ],
  "infoAdicional": [ { "nombre": "Lote", "valor": "COSECHA-2026-10" } ]
}
JSON, $liquidacion->construir());
        self::assertSame([], $liquidacion->validar());
    }

    public function testNotaDeCredito(): void
    {
        $nota = ComprobanteBuilder::notaCredito(self::RUC, '002', '001')
            ->referencias('MiERP', 'NOTACREDITO-2026-0001')
            ->fechaEmision('07/10/2026')
            ->comprador('05', '0123456789', 'CONTRIBUYENTE DE PRUEBAS')
            ->documentoModificado('01', '002-001-000000123', '01/10/2026')
            ->totalDocumentoSustento('115.00')
            ->motivo('DEVOLUCION PARCIAL DEL SERVICIO')
            ->linea('SERV-001', 'DEVOLUCION SERVICIO DE MANTENIMIENTO', 1, '20.00', 0, Tarifa::iva15())
            ->calcularTotales();

        self::assertJsonIgual(<<<'JSON'
{
  "origenReferencia": "MiERP",
  "referenciaExterna": "NOTACREDITO-2026-0001",
  "infoTributaria": { "ruc": "0123456789001", "codDoc": "04", "estab": "002", "ptoEmi": "001" },
  "info": {
    "fechaEmision": "07/10/2026",
    "tipoIdentificacionComprador": "05",
    "identificacionComprador": "0123456789",
    "razonSocialComprador": "CONTRIBUYENTE DE PRUEBAS",
    "codDocModificado": "01",
    "numDocModificado": "002-001-000000123",
    "fechaEmisionDocSustento": "01/10/2026",
    "totalDocumentoSustento": 115.00,
    "totalSinImpuestos": 20.00,
    "totalConImpuestos": [
      { "codigo": "2", "codigoPorcentaje": "4", "tarifa": 15, "baseImponible": 20.00, "valor": 3.00 }
    ],
    "valorModificacion": 23.00,
    "moneda": "DOLAR",
    "motivo": "DEVOLUCION PARCIAL DEL SERVICIO"
  },
  "detalles": [
    { "codigoInterno": "SERV-001", "descripcion": "DEVOLUCION SERVICIO DE MANTENIMIENTO", "cantidad": 1, "precioUnitario": 20.00,
      "descuento": 0, "precioTotalSinImpuesto": 20.00,
      "impuestos": [ { "codigo": "2", "codigoPorcentaje": "4", "tarifa": 15, "baseImponible": 20.00, "valor": 3.00 } ] }
  ]
}
JSON, $nota->construir());
        self::assertSame([], $nota->validar());
    }

    #[DataProvider('motores')]
    public function testNotaDeDebito(bool $bcmath): void
    {
        DecimalMath::usarBcmath($bcmath);
        $nota = ComprobanteBuilder::notaDebito(self::RUC, '001', '001')
            ->secuencial('000000000')
            ->referencias('MiERP', 'ND-2026-0001')
            ->fechaEmision('07/10/2026')
            ->comprador('05', '0123456789', 'CLIENTE DE PRUEBAS')
            ->documentoModificado('01', '001-001-000000123', '01/10/2026')
            ->motivo('INTERESES POR MORA', '20.00')
            ->motivo('GASTOS DE COBRANZA', '10.00')
            ->iva(Iva::TARIFA_15)
            ->pago('20', null, 15, 'dias')
            ->infoAdicional('email', 'cliente@example.com')
            ->calcularTotales();

        self::assertJsonIgual(<<<'JSON'
{
  "origenReferencia": "MiERP",
  "referenciaExterna": "ND-2026-0001",
  "infoTributaria": { "ruc": "0123456789001", "codDoc": "05", "estab": "001", "ptoEmi": "001", "secuencial": "000000000" },
  "info": {
    "fechaEmision": "07/10/2026",
    "tipoIdentificacionComprador": "05",
    "identificacionComprador": "0123456789",
    "razonSocialComprador": "CLIENTE DE PRUEBAS",
    "codDocModificado": "01",
    "numDocModificado": "001-001-000000123",
    "fechaEmisionDocSustento": "01/10/2026",
    "totalSinImpuestos": 30.00,
    "impuestos": [
      { "codigo": "2", "codigoPorcentaje": "4", "tarifa": 15, "baseImponible": 30.00, "valor": 4.50 }
    ],
    "valorTotal": 34.50,
    "pagos": [
      { "formaPago": "20", "total": 34.50, "plazo": 15, "unidadTiempo": "dias" }
    ]
  },
  "motivos": [
    { "razon": "INTERESES POR MORA", "valor": 20.00 },
    { "razon": "GASTOS DE COBRANZA", "valor": 10.00 }
  ],
  "infoAdicional": [ { "nombre": "email", "valor": "cliente@example.com" } ]
}
JSON, $nota->construir());
        self::assertSame([], $nota->validar());
    }

    public function testGuiaDeRemision(): void
    {
        $guia = ComprobanteBuilder::guiaRemision(self::RUC, '001', '001')
            ->secuencial('000000000')
            ->referencias('MiERP', 'GR-2026-0001')
            ->traslado('BODEGA PRINCIPAL DE PRUEBAS', '07/10/2026', '08/10/2026')
            ->transportista('05', '0123456789', 'TRANSPORTISTA DE PRUEBAS', 'PBA0001')
            ->destinatario('0123456789001', 'CLIENTE DE PRUEBAS', 'BODEGA DEL CLIENTE DE PRUEBAS', 'VENTA', '001', 'QUITO - GUAYAQUIL')
            ->linea('PROD-001', 'CAJA DE PRODUCTO DE PRUEBAS', 10)
            ->linea('PROD-002', 'REPUESTO DE PRUEBAS', '2.5')
            ->infoAdicional('email', 'cliente@example.com')
            ->calcularTotales();

        self::assertJsonIgual(<<<'JSON'
{
  "origenReferencia": "MiERP",
  "referenciaExterna": "GR-2026-0001",
  "infoTributaria": { "ruc": "0123456789001", "codDoc": "06", "estab": "001", "ptoEmi": "001", "secuencial": "000000000" },
  "info": {
    "dirPartida": "BODEGA PRINCIPAL DE PRUEBAS",
    "razonSocialTransportista": "TRANSPORTISTA DE PRUEBAS",
    "tipoIdentificacionTransportista": "05",
    "rucTransportista": "0123456789",
    "fechaIniTransporte": "07/10/2026",
    "fechaFinTransporte": "08/10/2026",
    "placa": "PBA0001"
  },
  "destinatarios": [
    {
      "identificacionDestinatario": "0123456789001",
      "razonSocialDestinatario": "CLIENTE DE PRUEBAS",
      "dirDestinatario": "BODEGA DEL CLIENTE DE PRUEBAS",
      "motivoTraslado": "VENTA",
      "codEstabDestino": "001",
      "ruta": "QUITO - GUAYAQUIL",
      "detalles": [
        { "codigoInterno": "PROD-001", "descripcion": "CAJA DE PRODUCTO DE PRUEBAS", "cantidad": 10 },
        { "codigoInterno": "PROD-002", "descripcion": "REPUESTO DE PRUEBAS", "cantidad": 2.5 }
      ]
    }
  ],
  "infoAdicional": [ { "nombre": "email", "valor": "cliente@example.com" } ]
}
JSON, $guia->construir());
        self::assertSame([], $guia->validar());
    }

    #[DataProvider('motores')]
    public function testRetencion(bool $bcmath): void
    {
        DecimalMath::usarBcmath($bcmath);
        $retencion = ComprobanteBuilder::retencion(self::RUC, '001', '001')
            ->secuencial('000000000')
            ->referencias('MiERP', 'RET-2026-0001')
            ->fechaEmision('05/10/2026')
            ->sujetoRetenido('05', '0123456789', 'PROVEEDOR DE PRUEBAS')
            ->periodo('10/2026')
            ->docSustento('01', '01', '001001000000123', '01/10/2026', '01/10/2026',
                numAutDocSustento: '0110202601012345678900110010010000001231234567814')
            ->impuestoSustento('2', '4', '100.00', 15)
            ->lineaRetencion('1', '303', '100.00', 10)
            ->lineaRetencion('2', '3', '15.00', 100)
            ->pago('20')
            ->infoAdicional('email', 'proveedor@example.com')
            ->calcularTotales();

        self::assertJsonIgual(<<<'JSON'
{
  "origenReferencia": "MiERP",
  "referenciaExterna": "RET-2026-0001",
  "infoTributaria": { "ruc": "0123456789001", "codDoc": "07", "estab": "001", "ptoEmi": "001", "secuencial": "000000000" },
  "info": {
    "fechaEmision": "05/10/2026",
    "tipoIdentificacionSujetoRetenido": "05",
    "identificacionSujetoRetenido": "0123456789",
    "razonSocialSujetoRetenido": "PROVEEDOR DE PRUEBAS",
    "periodoFiscal": "10/2026",
    "parteRel": "NO"
  },
  "docsSustento": [
    {
      "codSustento": "01",
      "codDocSustento": "01",
      "numDocSustento": "001001000000123",
      "fechaEmisionDocSustento": "01/10/2026",
      "fechaRegistroContable": "01/10/2026",
      "numAutDocSustento": "0110202601012345678900110010010000001231234567814",
      "pagoLocExt": "01",
      "totalSinImpuestos": 100.00,
      "importeTotal": 115.00,
      "impuestosDocSustento": [
        { "codImpuestoDocSustento": "2", "codigoPorcentaje": "4", "baseImponible": 100.00, "tarifa": 15, "valorImpuesto": 15.00 }
      ],
      "retenciones": [
        { "codigo": "1", "codigoRetencion": "303", "baseImponible": 100.00, "porcentajeRetener": 10, "valorRetenido": 10.00 },
        { "codigo": "2", "codigoRetencion": "3", "baseImponible": 15.00, "porcentajeRetener": 100, "valorRetenido": 15.00 }
      ],
      "pagos": [ { "formaPago": "20", "total": 115.00 } ]
    }
  ],
  "infoAdicional": [ { "nombre": "email", "valor": "proveedor@example.com" } ]
}
JSON, $retencion->construir());
        self::assertSame([], $retencion->validar());
    }

    // ------------------------------------------------------------------ el cliente manda

    public function testSinCalcularTotalesNoSeRellenaNada(): void
    {
        $json = json_decode(Serialization::encode(
            $this->base('F-1')->linea('A', 'B', 1, '10.00', 0, Tarifa::iva15())->pago('01')->construir()
        ), true);
        self::assertArrayNotHasKey('precioTotalSinImpuesto', $json['detalles'][0]);
        self::assertArrayNotHasKey('importeTotal', $json['info']);
        self::assertArrayNotHasKey('total', $json['info']['pagos'][0]);
    }

    public function testLosValoresDelClienteNoSeSobrescriben(): void
    {
        $linea = new Detalle();
        $linea->codigoPrincipal = 'X-1';
        $linea->descripcion = 'LINEA CON VALORES PROPIOS';
        $linea->cantidad = '1';
        $linea->precioUnitario = '10.00';
        $linea->descuento = '0';
        $linea->precioTotalSinImpuesto = '9.99';
        $iva = new Impuesto();
        $iva->codigo = '2';
        $iva->codigoPorcentaje = '4';
        $iva->tarifa = 15;
        $iva->valor = '1.49';
        $linea->impuestos = [$iva];

        $builder = $this->base('F-2')
            ->detalle($linea)
            ->propina('1.00')
            ->importeTotal('999.99')
            ->pago('01', '5.00')
            ->pago('19')
            ->configurar(static function (ComprobanteRequest $c): void {
                $c->info->totalDescuento = '0.50';
            })
            ->calcularTotales();
        $resultado = $builder->construir();

        $d = $resultado->detalles[0];
        self::assertSame('9.99', $d->precioTotalSinImpuesto);
        self::assertSame('1.49', $d->impuestos[0]->valor);
        self::assertSame('9.99', $d->impuestos[0]->baseImponible, 'La base vacia se rellena con el subtotal asignado.');
        self::assertSame('999.99', $resultado->info->importeTotal);
        self::assertSame('0.50', $resultado->info->totalDescuento);
        self::assertSame('1.00', $resultado->info->propina);
        self::assertSame('9.99', $resultado->info->totalSinImpuestos);
        self::assertSame('5.00', $resultado->info->pagos[0]->total);
        self::assertSame('994.99', $resultado->info->pagos[1]->total, 'El pago sin total recibe lo que falta del importeTotal asignado.');

        $campos = array_map(static fn (Inconsistencia $i): string => $i->campo, $builder->validar());
        self::assertContains('detalles[0].precioTotalSinImpuesto', $campos);
        self::assertContains('detalles[0].impuestos[0].valor', $campos);
        self::assertContains('info.totalDescuento', $campos);
        self::assertContains('info.importeTotal', $campos);
    }

    public function testCabeceraParcialSoloRellenaLoVacio(): void
    {
        $fila = new Impuesto();
        $fila->codigo = '2';
        $fila->codigoPorcentaje = '4';
        $fila->valor = '7.77';
        $resultado = $this->base('F-3')
            ->linea('A', 'B', 2, '25.00', 0, Tarifa::iva15())
            ->configurar(static function (ComprobanteRequest $c) use ($fila): void {
                $c->info->totalConImpuestos = [$fila];
            })
            ->calcularTotales()
            ->construir();
        $cabecera = $resultado->info->totalConImpuestos;
        self::assertCount(1, $cabecera);
        self::assertSame('7.77', $cabecera[0]->valor);
        self::assertSame('50.00', $cabecera[0]->baseImponible);
        self::assertSame('15', $cabecera[0]->tarifa);
    }

    public function testConstruirDevuelveCopiasIndependientes(): void
    {
        $builder = $this->base('F-4')->linea('A', 'B', 1, '10.00', 0, Tarifa::iva15())->pago('01')->calcularTotales();
        $primera = $builder->construir();
        $primera->info->importeTotal = '0';
        $builder->linea('C', 'D', 1, '5.00', 0, Tarifa::iva15());
        $segunda = $builder->construir();
        self::assertSame('17.25', $segunda->info->importeTotal);
        self::assertSame('17.25', $segunda->info->pagos[0]->total);
    }

    public function testDesdeCalculaYValidaUnComprobanteExistente(): void
    {
        $original = $this->base('F-5')->linea('A', 'B', 3, '1.0112', 0, Tarifa::iva15())->pago('01')->construir();
        $builder = ComprobanteBuilder::desde($original)->calcularTotales();
        self::assertInstanceOf(FacturaBuilder::class, $builder);
        $calculado = $builder->construir();
        self::assertSame('3.03', $calculado->detalles[0]->precioTotalSinImpuesto);
        self::assertSame('0.46', $calculado->detalles[0]->impuestos[0]->valor);
        self::assertSame('3.49', $calculado->info->importeTotal);
        self::assertNull($original->info->importeTotal, 'desde() trabaja sobre una copia.');
        self::assertSame([], $builder->validar());

        $this->expectException(EcuafactSdkException::class);
        ComprobanteBuilder::desde(new ComprobanteRequest());
    }

    public function testFloatsSeConviertenSinErrorBinario(): void
    {
        $resultado = $this->base('F-6')
            ->linea('A', 'B', 1, 0.1 + 0.2, 0, Tarifa::iva0())
            ->pago('01')
            ->calcularTotales()
            ->construir();
        self::assertSame('0.3', $resultado->detalles[0]->precioUnitario);
        self::assertStringContainsString('"importeTotal":0.30', Serialization::encode($resultado));
    }

    // ------------------------------------------------------------------ precision

    public function testPrecisionPersonalizada(): void
    {
        $hacia = fn (Precision $p) => ComprobanteBuilder::factura(self::RUC, '002', '001', $p)
            ->linea('A', 'B', 1, '0.125', 0, Tarifa::iva0())
            ->linea('C', 'D', 1, '1.0005', 0, Tarifa::iva15())
            ->calcularTotales()
            ->construir();

        $api = $hacia(new Precision());
        self::assertSame('0.13', $api->detalles[0]->precioTotalSinImpuesto);
        self::assertSame('1.00', $api->detalles[1]->precioTotalSinImpuesto);
        self::assertSame('0.15', $api->detalles[1]->impuestos[0]->valor);

        $bancario = $hacia(new Precision(redondeo: ModoRedondeo::HALF_EVEN));
        self::assertSame('0.12', $bancario->detalles[0]->precioTotalSinImpuesto);

        $tres = $hacia(new Precision(escalaImportes: 3));
        self::assertSame('0.125', $tres->detalles[0]->precioTotalSinImpuesto);
        self::assertSame('1.001', $tres->detalles[1]->precioTotalSinImpuesto);
        self::assertSame('0.150', $tres->detalles[1]->impuestos[0]->valor);
        self::assertSame('1.276', $tres->info->importeTotal);

        $truncar = $hacia(new Precision(redondeo: ModoRedondeo::DOWN));
        self::assertSame('0.12', $truncar->detalles[0]->precioTotalSinImpuesto);

        // escalaCantidades solo afecta a validar().
        $estricta = ComprobanteBuilder::factura(self::RUC, '002', '001', new Precision(escalaCantidades: 2))
            ->linea('A', 'B', 1, '1.0005', 0, Tarifa::iva15());
        $campos = array_map(static fn (Inconsistencia $i): string => $i->campo, $estricta->validar());
        self::assertContains('detalles[0].precioUnitario', $campos);
    }

    public function testPrecisionInvalidaLanzaConfiguracion(): void
    {
        $this->expectException(EcuafactConfigurationException::class);
        new Precision(escalaImportes: -1);
    }

    // ------------------------------------------------------------------ validar

    public function testValidarDetectaDescuadres(): void
    {
        $builder = $this->base('F-7')
            ->linea('A', 'B', 3, '1.0112', 0, Tarifa::iva15())
            ->pago('01', '3.00')
            ->configurar(static function (ComprobanteRequest $c): void {
                $c->detalles[0]->impuestos[0]->valor = '0.45'; // calculado sobre la base redondeada
                $c->info->totalConImpuestos = null;
            })
            ->importeTotal('3.49')
            ->calcularTotales();
        $errores = $builder->validar();
        $texto = implode("\n", array_map('strval', $errores));
        self::assertStringContainsString('detalles[0].impuestos[0].valor: No coincide con base (sin redondear) y tarifa. (esperado 0.46, actual 0.45)', $texto);
        $valor = array_values(array_filter($errores, static fn (Inconsistencia $i): bool => $i->campo === 'detalles[0].impuestos[0].valor'))[0];
        self::assertSame('0.46', $valor->esperado);
        self::assertSame('0.45', $valor->actual);
        self::assertStringContainsString('info.importeTotal', $texto);
        self::assertStringContainsString('info.pagos: La suma de los pagos no coincide con info.importeTotal', $texto);
    }

    public function testValidarCamposObligatoriosYFormatos(): void
    {
        $builder = ComprobanteBuilder::factura('ABC', '2', '0001')
            ->fechaEmision('2026-10-07')
            ->linea('A', 'B', 1, '10.00', 0, Tarifa::iva15())
            ->configurar(static function (ComprobanteRequest $c): void {
                $c->info->moneda = null;
                $c->infoTributaria->codDoc = '04';
            });
        $campos = array_map(static fn (Inconsistencia $i): string => $i->campo, $builder->validar());
        foreach ([
            'origenReferencia', 'referenciaExterna', 'infoTributaria.ruc', 'infoTributaria.codDoc',
            'infoTributaria.estab', 'infoTributaria.ptoEmi', 'info.fechaEmision', 'info.moneda', 'info.propina',
            'info.tipoIdentificacionComprador', 'info.totalConImpuestos', 'info.importeTotal', 'info.pagos',
            'detalles[0].precioTotalSinImpuesto',
        ] as $campo) {
            self::assertContains($campo, $campos, $campo);
        }
    }

    public function testValidarNotaCreditoYGuiaYRetencion(): void
    {
        $nc = ComprobanteBuilder::notaCredito(self::RUC, '002', '001')
            ->referencias('MiERP', 'NC-1')
            ->fechaEmision('31/02/2026')
            ->comprador('05', '0123456789', 'X')
            ->documentoModificado('01', '002001000000123', '01/10/2026')
            ->totalDocumentoSustento('10.00')
            ->linea('A', 'B', 1, '20.00', 0, Tarifa::iva15())
            ->calcularTotales();
        $campos = array_map(static fn (Inconsistencia $i): string => $i->campo, $nc->validar());
        self::assertContains('info.fechaEmision', $campos);
        self::assertContains('info.numDocModificado', $campos);
        self::assertContains('info.motivo', $campos);
        self::assertContains('info.valorModificacion', $campos);

        $guia = ComprobanteBuilder::guiaRemision(self::RUC, '001', '001')
            ->referencias('MiERP', 'GR-1')
            ->traslado('A', '08/10/2026', '07/10/2026')
            ->transportista('05', '0123456789', 'T', 'P')
            ->destinatario('AB123456', 'C', 'D', 'VENTA')
            ->linea('P', 'Q', 0);
        $campos = array_map(static fn (Inconsistencia $i): string => $i->campo, $guia->validar());
        self::assertContains('info.fechaFinTransporte', $campos);
        self::assertContains('destinatarios[0].identificacionDestinatario', $campos);
        self::assertContains('destinatarios[0].detalles[0].cantidad', $campos);

        $ret = ComprobanteBuilder::retencion(self::RUC, '001', '001')
            ->referencias('MiERP', 'RET-1')
            ->fechaEmision('05/10/2026')
            ->sujetoRetenido('05', '0123456789', 'P')
            ->periodo('2026-10')
            ->docSustento('01', '01', '001001000000123', '01/10/2026', '01/10/2026', '100.00', '120.00')
            ->impuestoSustento('2', '4', '100.00', 15)
            ->lineaRetencion('3', '303', '15.00', 30, '4.00')
            ->pago('20', '120.00')
            ->calcularTotales();
        $campos = array_map(static fn (Inconsistencia $i): string => $i->campo, $ret->validar());
        self::assertContains('info.periodoFiscal', $campos);
        self::assertContains('docsSustento[0].importeTotal', $campos);
        self::assertContains('docsSustento[0].retenciones[0].codigo', $campos);
        self::assertContains('docsSustento[0].retenciones[0].valorRetenido', $campos);
    }

    public function testValidarNoLanzaConNumerosInvalidos(): void
    {
        $builder = $this->base('F-8')
            ->linea('A', 'B', 1, '10.00', 0, Tarifa::iva15())
            ->configurar(static function (ComprobanteRequest $c): void {
                $c->detalles[0]->cantidad = 'uno';
            })
            ->calcularTotales();
        $errores = $builder->validar();
        self::assertNotEmpty($errores);
        self::assertSame('comprobante', $errores[0]->campo);
    }

    public function testLineaRechazaImportesNoDecimales(): void
    {
        $this->expectException(EcuafactSdkException::class);
        $this->base('F-9')->linea('A', 'B', 1, '10,50', 0, Tarifa::iva15());
    }

    public function testRetencionSinDocSustentoLanza(): void
    {
        $this->expectException(EcuafactSdkException::class);
        ComprobanteBuilder::retencion(self::RUC, '001', '001')->lineaRetencion('1', '303', '1.00', 1);
    }

    // ------------------------------------------------------------------ DecimalMath

    #[DataProvider('motores')]
    public function testAritmeticaDecimalExacta(bool $bcmath): void
    {
        DecimalMath::usarBcmath($bcmath);
        self::assertSame('0.3', DecimalMath::sumar('0.1', '0.2'));
        self::assertSame('0.455040', DecimalMath::porcentaje('3.0336', '15'));
        self::assertSame('0.46', DecimalMath::redondear('0.455', 2));
        self::assertSame('-0.46', DecimalMath::redondear('-0.455', 2));
        self::assertSame('0.12', DecimalMath::redondear('0.125', 2, ModoRedondeo::HALF_EVEN));
        self::assertSame('0.14', DecimalMath::redondear('0.135', 2, ModoRedondeo::HALF_EVEN));
        self::assertSame('0.12', DecimalMath::redondear('0.125', 2, ModoRedondeo::HALF_DOWN));
        self::assertSame('0.13', DecimalMath::redondear('0.1251', 2, ModoRedondeo::HALF_DOWN));
        self::assertSame('0.01', DecimalMath::redondear('0.001', 2, ModoRedondeo::UP));
        self::assertSame('0.01', DecimalMath::redondear('0.001', 2, ModoRedondeo::CEILING));
        self::assertSame('0.00', DecimalMath::redondear('-0.001', 2, ModoRedondeo::CEILING));
        self::assertSame('-0.01', DecimalMath::redondear('-0.001', 2, ModoRedondeo::FLOOR));
        self::assertSame('10.00', DecimalMath::redondear('9.995', 2));
        self::assertSame('-1.9664', DecimalMath::restar('3.0336', '5'));
        self::assertSame('-100000099999999.9998999999', DecimalMath::multiplicar('-999999999999.999999', '100.0001'));
        self::assertSame('100000000000000.000000000001', DecimalMath::sumar('99999999999999.999999999999', '0.000000000002'));
        self::assertSame(0, DecimalMath::comparar('52.44', '52.440'));
        self::assertSame(-1, DecimalMath::comparar('-1', '0'));
        self::assertSame('7.50', DecimalMath::normalizar('+007.50'));
    }

    public function testBcmathYAritmeticaPropiaCoinciden(): void
    {
        $valores = ['0', '1', '-1', '0.005', '12.345678', '-98765.4321', '3.0336', '999999999999.99', '0.000001'];
        foreach ($valores as $a) {
            foreach ($valores as $b) {
                DecimalMath::usarBcmath(true);
                $bc = [DecimalMath::sumar($a, $b), DecimalMath::restar($a, $b), DecimalMath::multiplicar($a, $b)];
                DecimalMath::usarBcmath(false);
                $propio = [DecimalMath::sumar($a, $b), DecimalMath::restar($a, $b), DecimalMath::multiplicar($a, $b)];
                foreach ($bc as $i => $valor) {
                    self::assertSame(0, DecimalMath::comparar($valor, $propio[$i]), "$a, $b, op $i");
                }
            }
        }
    }

    public function testFuncionesSueltasCalcularYValidar(): void
    {
        $original = $this->base('F-10')->linea('A', 'B', 2, '25.00', 0, Iva::TARIFA_15)->pago('01')
            ->configurar(static function (ComprobanteRequest $c): void {
                $c->info->moneda = null;
            })
            ->construir();
        $errores = ComprobanteBuilder::validarComprobante($original);
        self::assertNotEmpty($errores);

        $calculado = ComprobanteBuilder::calcularTotales($original);
        self::assertNull($original->info->importeTotal, 'calcularTotales devuelve una copia.');
        self::assertSame('DOLAR', $calculado->info->moneda);
        self::assertSame('0.00', $calculado->info->propina);
        self::assertSame('57.50', $calculado->info->importeTotal);
        self::assertSame('57.50', $calculado->info->pagos[0]->total);
        self::assertSame([], ComprobanteBuilder::validarComprobante($calculado));
    }

    public function testIvaEnum(): void
    {
        $esperado = ['TARIFA_0' => ['0', 0], 'TARIFA_5' => ['5', 5], 'TARIFA_8' => ['8', 8], 'TARIFA_15' => ['4', 15],
            'NO_OBJETO' => ['6', 0], 'EXENTO' => ['7', 0]];
        foreach (Iva::cases() as $caso) {
            $tarifa = $caso->tarifa();
            self::assertSame('2', $tarifa->codigo);
            self::assertSame($esperado[$caso->name], [$tarifa->codigoPorcentaje, $tarifa->tarifa]);
        }
    }

    // ------------------------------------------------------------------ apoyo

    private function base(string $referencia): FacturaBuilder
    {
        return ComprobanteBuilder::factura(self::RUC, '002', '001')
            ->referencias('MiERP', $referencia)
            ->fechaEmision('07/10/2026')
            ->comprador('05', '0123456789', 'CONTRIBUYENTE DE PRUEBAS');
    }

    /** Compara el JSON que enviaria el SDK con el de la guia: mismas claves y mismos valores numericos. */
    private static function assertJsonIgual(string $esperado, ComprobanteRequest $comprobante): void
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
            return 'n:' . DecimalMath::redondear(DecimalMath::normalizar($valor), 10);
        }
        return $valor;
    }
}
