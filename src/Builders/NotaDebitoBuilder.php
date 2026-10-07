<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Builders;

use Ecuafact\Sdk\Builders\Concerns\ConComprador;
use Ecuafact\Sdk\Builders\Concerns\ConDocumentoModificado;
use Ecuafact\Sdk\Builders\Concerns\ConPagos;
use Ecuafact\Sdk\Contracts\Impuesto;
use Ecuafact\Sdk\Contracts\Motivo;

/**
 * Nota de debito (codDoc 05). Crealo con `ComprobanteBuilder::notaDebito()`. Lleva `motivos` con su
 * valor sin IVA y un unico IVA en `info.impuestos`; el total es `valorTotal`.
 */
final class NotaDebitoBuilder extends AbstractComprobanteBuilder
{
    use ConComprador;
    use ConDocumentoModificado;
    use ConPagos;

    public function __construct(string $ruc, string $estab, string $ptoEmi, ?Precision $precision = null)
    {
        parent::__construct('05', $ruc, $estab, $ptoEmi, $precision ?? new Precision());
    }

    public function fechaEmision(string|\DateTimeInterface $fecha): static
    {
        $this->comprobante->info->fechaEmision = self::fechaTexto($fecha);
        return $this;
    }

    /** Agrega un motivo con su valor SIN IVA. */
    public function motivo(string $razon, int|float|string $valor): static
    {
        $motivo = new Motivo();
        $motivo->razon = $razon;
        $motivo->valor = self::importe($valor, 'valor');
        $this->comprobante->motivos[] = $motivo;
        return $this;
    }

    /** IVA de la nota (uno solo). Con `calcularTotales()` se rellenan su base y su valor. */
    public function iva(Tarifa|Iva $iva): static
    {
        $iva = $iva instanceof Iva ? $iva->tarifa() : $iva;
        $impuesto = new Impuesto();
        $impuesto->codigo = $iva->codigo;
        $impuesto->codigoPorcentaje = $iva->codigoPorcentaje;
        $impuesto->tarifa = self::importe($iva->tarifa, 'tarifa');
        $this->comprobante->info->impuestos = [$impuesto];
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

    public function valorTotal(int|float|string $valor): static
    {
        $this->comprobante->info->valorTotal = self::importe($valor, 'valorTotal');
        return $this;
    }

    protected function codDoc(): string
    {
        return '05';
    }
}
