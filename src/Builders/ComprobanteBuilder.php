<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Builders;

use Ecuafact\Sdk\Contracts\ComprobanteRequest;
use Ecuafact\Sdk\EcuafactSdkException;

/**
 * Punto de entrada de los constructores (opcionales) de comprobantes. Cada uno produce el
 * `ComprobanteRequest` del contrato, listo para `emitir()`.
 *
 * ```php
 * $factura = ComprobanteBuilder::factura('0123456789001', '002', '001')
 *     ->referencias('MiERP', 'FACTURA-2026-0002')
 *     ->fechaEmision('07/10/2026')
 *     ->comprador('05', '0123456789', 'CONTRIBUYENTE DE PRUEBAS')
 *     ->linea('SERV-001', 'SERVICIO DE MANTENIMIENTO', 1, '100.00', 0, Tarifa::iva15())
 *     ->pago('01')
 *     ->calcularTotales()
 *     ->construir();
 * ```
 */
final class ComprobanteBuilder
{
    private function __construct()
    {
    }

    public static function factura(string $ruc, string $estab, string $ptoEmi, ?Precision $precision = null): FacturaBuilder
    {
        return new FacturaBuilder($ruc, $estab, $ptoEmi, $precision);
    }

    public static function liquidacionCompra(
        string $ruc,
        string $estab,
        string $ptoEmi,
        ?Precision $precision = null
    ): LiquidacionCompraBuilder {
        return new LiquidacionCompraBuilder($ruc, $estab, $ptoEmi, $precision);
    }

    public static function notaCredito(string $ruc, string $estab, string $ptoEmi, ?Precision $precision = null): NotaCreditoBuilder
    {
        return new NotaCreditoBuilder($ruc, $estab, $ptoEmi, $precision);
    }

    public static function notaDebito(string $ruc, string $estab, string $ptoEmi, ?Precision $precision = null): NotaDebitoBuilder
    {
        return new NotaDebitoBuilder($ruc, $estab, $ptoEmi, $precision);
    }

    public static function guiaRemision(string $ruc, string $estab, string $ptoEmi, ?Precision $precision = null): GuiaRemisionBuilder
    {
        return new GuiaRemisionBuilder($ruc, $estab, $ptoEmi, $precision);
    }

    public static function retencion(string $ruc, string $estab, string $ptoEmi, ?Precision $precision = null): RetencionBuilder
    {
        return new RetencionBuilder($ruc, $estab, $ptoEmi, $precision);
    }

    /**
     * Devuelve una copia de `$comprobante` con los valores vacios calculados (mismas reglas que
     * `calcularTotales()` de los constructores). Nunca cambia lo que ya tiene valor.
     */
    public static function calcularTotales(ComprobanteRequest $comprobante, ?Precision $precision = null): ComprobanteRequest
    {
        /** @var ComprobanteRequest $copia */
        $copia = unserialize(serialize($comprobante));
        (new CalculadoraComprobante($precision ?? new Precision()))->calcular($copia);
        return $copia;
    }

    /**
     * Inconsistencias de `$comprobante` (lista vacia si no hay). No lanza ni lo modifica.
     *
     * @return Inconsistencia[]
     */
    public static function validarComprobante(ComprobanteRequest $comprobante, ?Precision $precision = null): array
    {
        try {
            /** @var ComprobanteRequest $copia */
            $copia = unserialize(serialize($comprobante));
            return (new CalculadoraComprobante($precision ?? new Precision()))->validar($copia);
        } catch (EcuafactSdkException $e) {
            return [new Inconsistencia('comprobante', $e->getMessage())];
        }
    }

    /**
     * Constructor del tipo de `infoTributaria.codDoc` sobre una copia de un comprobante existente,
     * para calcular sus valores vacios o validarlo. Lanza si `codDoc` no es 01, 03, 04, 05, 06 o 07.
     */
    public static function desde(ComprobanteRequest $comprobante, ?Precision $precision = null): AbstractComprobanteBuilder
    {
        $clase = match ($comprobante->infoTributaria?->codDoc) {
            '01' => FacturaBuilder::class,
            '03' => LiquidacionCompraBuilder::class,
            '04' => NotaCreditoBuilder::class,
            '05' => NotaDebitoBuilder::class,
            '06' => GuiaRemisionBuilder::class,
            '07' => RetencionBuilder::class,
            default => throw new EcuafactSdkException('infoTributaria.codDoc debe ser 01, 03, 04, 05, 06 o 07.'),
        };
        return $clase::sobre($comprobante, $precision);
    }
}
