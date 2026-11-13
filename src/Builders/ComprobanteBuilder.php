<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Builders;

/**
 * Punto de entrada de los constructores (opcionales) de comprobantes. Cada uno solo arma el
 * request de su tipo (`FacturaRequest`, `NotaCreditoRequest`...) con los valores que le das, listo para `emitir()` o `validar()`. No calcula
 * totales ni impuestos: eso lo hace y lo valida el API.
 *
 * ```php
 * $factura = ComprobanteBuilder::factura('0123456789001', '002', '001')
 *     ->referencias('MiERP', 'FACTURA-2026-0002')
 *     ->fechaEmision('07/10/2026')
 *     ->comprador('05', '0123456789', 'CONTRIBUYENTE DE PRUEBAS')
 *     ->linea('SERV-001', 'SERVICIO DE MANTENIMIENTO', 1, '100.00', 0, Tarifa::iva('4', 15))
 *     ->importeTotal('115.00')
 *     ->pago('01', '115.00')
 *     ->construir();
 * ```
 */
final class ComprobanteBuilder
{
    private function __construct()
    {
    }

    public static function factura(string $ruc, string $estab, string $ptoEmi): FacturaBuilder
    {
        return new FacturaBuilder($ruc, $estab, $ptoEmi);
    }

    public static function liquidacionCompra(
        string $ruc,
        string $estab,
        string $ptoEmi
    ): LiquidacionCompraBuilder {
        return new LiquidacionCompraBuilder($ruc, $estab, $ptoEmi);
    }

    public static function notaCredito(string $ruc, string $estab, string $ptoEmi): NotaCreditoBuilder
    {
        return new NotaCreditoBuilder($ruc, $estab, $ptoEmi);
    }

    public static function notaDebito(string $ruc, string $estab, string $ptoEmi): NotaDebitoBuilder
    {
        return new NotaDebitoBuilder($ruc, $estab, $ptoEmi);
    }

    public static function guiaRemision(string $ruc, string $estab, string $ptoEmi): GuiaRemisionBuilder
    {
        return new GuiaRemisionBuilder($ruc, $estab, $ptoEmi);
    }

    public static function retencion(string $ruc, string $estab, string $ptoEmi): RetencionBuilder
    {
        return new RetencionBuilder($ruc, $estab, $ptoEmi);
    }
}
