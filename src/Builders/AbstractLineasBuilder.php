<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Builders;

use Ecuafact\Sdk\Contracts\CampoAdicional;
use Ecuafact\Sdk\Contracts\Detalle;
use Ecuafact\Sdk\Contracts\Impuesto;

/**
 * Base de los comprobantes con lineas e impuestos: factura (01), liquidacion de compra (03) y
 * nota de credito (04).
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
     * int, float o string decimal (`'12.50'`, la forma exacta). Con `calcularTotales()` se rellenan
     * `precioTotalSinImpuesto`, la base y el valor de cada impuesto. `iva`: `Iva::TARIFA_15` o una `Tarifa`.
     *
     * @param array<string, string>|null $detallesAdicionales Hasta 3 pares nombre => valor.
     */
    public function linea(
        string $codigo,
        string $descripcion,
        int|float|string $cantidad,
        int|float|string $precioUnitario,
        int|float|string $descuento,
        Tarifa|Iva $iva,
        ?Tarifa $ice = null,
        ?string $codigoAuxiliar = null,
        ?array $detallesAdicionales = null,
    ): static {
        $detalle = new Detalle();
        $this->asignarCodigos($detalle, $codigo, $codigoAuxiliar);
        $detalle->descripcion = $descripcion;
        $detalle->cantidad = self::importe($cantidad, 'cantidad');
        $detalle->precioUnitario = self::importe($precioUnitario, 'precioUnitario');
        $detalle->descuento = self::importe($descuento, 'descuento');
        $detalle->impuestos = [];
        if ($ice !== null) {
            $detalle->impuestos[] = self::impuesto($ice);
        }
        $detalle->impuestos[] = self::impuesto($iva instanceof Iva ? $iva->tarifa() : $iva);
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

    /** Agrega una linea armada por ti; sus valores asignados se respetan. */
    public function detalle(Detalle $detalle): static
    {
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

    abstract protected function asignarCodigos(Detalle $detalle, string $codigo, ?string $codigoAuxiliar): void;

    private static function impuesto(Tarifa $tarifa): Impuesto
    {
        $impuesto = new Impuesto();
        $impuesto->codigo = $tarifa->codigo;
        $impuesto->codigoPorcentaje = $tarifa->codigoPorcentaje;
        $impuesto->tarifa = self::importe($tarifa->tarifa, 'tarifa');
        return $impuesto;
    }
}
