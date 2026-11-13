<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Builders;

use Ecuafact\Sdk\Builders\Concerns\ConComprador;
use Ecuafact\Sdk\Builders\Concerns\ConPagos;
use Ecuafact\Sdk\Contracts\DetalleCompraVenta;
use Ecuafact\Sdk\Contracts\FacturaRequest;
use Ecuafact\Sdk\Contracts\InfoFactura;

/** Factura (`FacturaRequest`, tipo 01). Crealo con `ComprobanteBuilder::factura()`. */
final class FacturaBuilder extends AbstractLineasBuilder
{
    use ConComprador;
    use ConPagos;

    public function __construct(string $ruc, string $estab, string $ptoEmi)
    {
        $solicitud = new FacturaRequest();
        $solicitud->info = new InfoFactura();
        parent::__construct($solicitud, $ruc, $estab, $ptoEmi);
    }

    public function propina(int|float|string $propina): static
    {
        $this->comprobante->info->propina = self::importe($propina, 'propina');
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

    /** Numero de la guia de remision que acompana la mercaderia (`ddd-ddd-ddddddddd`). */
    public function guiaRemision(string $numero): static
    {
        $this->comprobante->info->guiaRemision = $numero;
        return $this;
    }

    /** Agrega una linea armada por ti; se envia tal cual. */
    public function detalle(DetalleCompraVenta $detalle): static
    {
        $this->comprobante->detalles[] = $detalle;
        return $this;
    }

    public function construir(): FacturaRequest
    {
        /** @var FacturaRequest $solicitud */
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
