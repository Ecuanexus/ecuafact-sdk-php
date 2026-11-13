<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Builders;

use Ecuafact\Sdk\Builders\Concerns\ConPagos;
use Ecuafact\Sdk\Contracts\DetalleCompraVenta;
use Ecuafact\Sdk\Contracts\InfoLiquidacionCompra;
use Ecuafact\Sdk\Contracts\LiquidacionCompraRequest;

/** Liquidacion de compra (`LiquidacionCompraRequest`, tipo 03). Crealo con `ComprobanteBuilder::liquidacionCompra()`. Sin propina. */
final class LiquidacionCompraBuilder extends AbstractLineasBuilder
{
    use ConPagos;

    public function __construct(string $ruc, string $estab, string $ptoEmi)
    {
        $solicitud = new LiquidacionCompraRequest();
        $solicitud->info = new InfoLiquidacionCompra();
        parent::__construct($solicitud, $ruc, $estab, $ptoEmi);
    }

    /** `tipoIdentificacion`: 04 RUC, 05 cedula, 06 pasaporte u 08 exterior. */
    public function proveedor(
        string $tipoIdentificacion,
        string $identificacion,
        string $razonSocial,
        ?string $direccion = null
    ): static {
        $info = $this->comprobante->info;
        $info->tipoIdentificacionProveedor = $tipoIdentificacion;
        $info->identificacionProveedor = $identificacion;
        $info->razonSocialProveedor = $razonSocial;
        if ($direccion !== null) {
            $info->direccionProveedor = $direccion;
        }
        return $this;
    }

    public function totalDescuento(int|float|string $valor): static
    {
        $this->comprobante->info->totalDescuento = self::importe($valor, 'totalDescuento');
        return $this;
    }

    public function importeTotal(int|float|string $valor): static
    {
        $this->comprobante->info->importeTotal = self::importe($valor, 'importeTotal');
        return $this;
    }

    /** Agrega una linea armada por ti; se envia tal cual. */
    public function detalle(DetalleCompraVenta $detalle): static
    {
        $this->comprobante->detalles[] = $detalle;
        return $this;
    }

    public function construir(): LiquidacionCompraRequest
    {
        /** @var LiquidacionCompraRequest $solicitud */
        $solicitud = parent::construir();
        return $solicitud;
    }

    protected function nuevoDetalle(string $codigo, ?string $codigoAuxiliar): DetalleCompraVenta
    {
        $detalle = new DetalleCompraVenta();
        $detalle->codigoPrincipal = $codigo;
        $detalle->codigoAuxiliar = $codigoAuxiliar;
        return $detalle;
    }
}
