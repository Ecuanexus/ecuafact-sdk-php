<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Builders;

use Ecuafact\Sdk\Builders\Concerns\ConPagos;
use Ecuafact\Sdk\Contracts\Detalle;

/** Liquidacion de compra (codDoc 03). Crealo con `ComprobanteBuilder::liquidacionCompra()`. Sin propina. */
final class LiquidacionCompraBuilder extends AbstractLineasBuilder
{
    use ConPagos;

    public function __construct(string $ruc, string $estab, string $ptoEmi, ?Precision $precision = null)
    {
        parent::__construct('03', $ruc, $estab, $ptoEmi, $precision ?? new Precision());
        $this->comprobante->info->moneda = 'DOLAR';
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

    protected function codDoc(): string
    {
        return '03';
    }

    protected function asignarCodigos(Detalle $detalle, string $codigo, ?string $codigoAuxiliar): void
    {
        $detalle->codigoPrincipal = $codigo;
        $detalle->codigoAuxiliar = $codigoAuxiliar;
    }
}
