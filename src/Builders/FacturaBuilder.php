<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Builders;

use Ecuafact\Sdk\Builders\Concerns\ConComprador;
use Ecuafact\Sdk\Builders\Concerns\ConPagos;
use Ecuafact\Sdk\Contracts\Detalle;

/** Factura (codDoc 01). Crealo con `ComprobanteBuilder::factura()`. `moneda` empieza en `DOLAR`. */
final class FacturaBuilder extends AbstractLineasBuilder
{
    use ConComprador;
    use ConPagos;

    public function __construct(string $ruc, string $estab, string $ptoEmi, ?Precision $precision = null)
    {
        parent::__construct('01', $ruc, $estab, $ptoEmi, $precision ?? new Precision());
        $this->comprobante->info->moneda = 'DOLAR';
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

    protected function codDoc(): string
    {
        return '01';
    }

    protected function asignarCodigos(Detalle $detalle, string $codigo, ?string $codigoAuxiliar): void
    {
        $detalle->codigoPrincipal = $codigo;
        $detalle->codigoAuxiliar = $codigoAuxiliar;
    }
}
