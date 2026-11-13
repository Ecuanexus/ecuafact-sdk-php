<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Builders;

use Ecuafact\Sdk\Contracts\CampoAdicional;
use Ecuafact\Sdk\Contracts\DetalleCompraVenta;
use Ecuafact\Sdk\Contracts\DetalleNotaCredito;
use Ecuafact\Sdk\Contracts\Impuesto;

/**
 * Base de los comprobantes con lineas e impuestos: factura (01), liquidacion de compra (03) y
 * nota de credito (04). Cada uno agrega `detalle()` con su propia clase de linea (`DetalleCompraVenta` o
 * `DetalleNotaCredito`).
 */
abstract class AbstractLineasBuilder extends AbstractComprobanteBuilder
{
    /** Fecha del comprobante (`dd/MM/yyyy` o una fecha). */
    public function fechaEmision(string|\DateTimeInterface $fecha): static
    {
        $this->comprobante->info->fechaEmision = self::fechaTexto($fecha);
        return $this;
    }

    /**
     * Agrega una linea. `descuento` es un importe en dolares (no porcentaje). Los importes aceptan
     * int, float o string decimal (`'12.50'`, la forma exacta). El SDK no calcula nada: asigna
     * `precioTotalSinImpuesto` y los demas importes con `detalle()` o `configurar()`. `iva`: la `Tarifa`
     * (`Tarifa::iva('4', 15)`) con el `codigoPorcentaje` y la `tarifa` que defines.
     *
     * @param array<string, string>|null $detallesAdicionales Hasta 3 pares nombre => valor.
     */
    public function linea(
        string $codigo,
        string $descripcion,
        int|float|string $cantidad,
        int|float|string $precioUnitario,
        int|float|string $descuento,
        Tarifa $iva,
        ?Tarifa $ice = null,
        ?string $codigoAuxiliar = null,
        ?array $detallesAdicionales = null,
    ): static {
        $detalle = $this->nuevoDetalle($codigo, $codigoAuxiliar);
        $detalle->descripcion = $descripcion;
        $detalle->cantidad = self::importe($cantidad, 'cantidad');
        $detalle->precioUnitario = self::importe($precioUnitario, 'precioUnitario');
        $detalle->descuento = self::importe($descuento, 'descuento');
        $detalle->impuestos = [];
        if ($ice !== null) {
            $detalle->impuestos[] = self::impuesto($ice);
        }
        $detalle->impuestos[] = self::impuesto($iva);
        if ($detallesAdicionales !== null) {
            $detalle->detallesAdicionales = [];
            foreach ($detallesAdicionales as $nombre => $valor) {
                $campo = new CampoAdicional();
                $campo->nombre = (string) $nombre;
                $campo->valor = $valor;
                $detalle->detallesAdicionales[] = $campo;
            }
        }
        $this->comprobante->detalles[] = $detalle;
        return $this;
    }

    public function moneda(string $moneda): static
    {
        $this->comprobante->info->moneda = $moneda;
        return $this;
    }

    public function totalSinImpuestos(int|float|string $valor): static
    {
        $this->comprobante->info->totalSinImpuestos = self::importe($valor, 'totalSinImpuestos');
        return $this;
    }

    /** Crea la linea del tipo con su codigo (y codigo auxiliar o adicional) asignado. */
    abstract protected function nuevoDetalle(string $codigo, ?string $codigoAuxiliar): DetalleCompraVenta|DetalleNotaCredito;

    private static function impuesto(Tarifa $tarifa): Impuesto
    {
        $impuesto = new Impuesto();
        $impuesto->codigo = $tarifa->codigo;
        $impuesto->codigoPorcentaje = $tarifa->codigoPorcentaje;
        $impuesto->tarifa = self::importe($tarifa->tarifa, 'tarifa');
        return $impuesto;
    }
}
