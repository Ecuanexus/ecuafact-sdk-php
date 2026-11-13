<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Builders;

use Ecuafact\Sdk\Builders\Concerns\ConComprador;
use Ecuafact\Sdk\Builders\Concerns\ConDocumentoModificado;
use Ecuafact\Sdk\Builders\Concerns\ConPagos;
use Ecuafact\Sdk\Contracts\Impuesto;
use Ecuafact\Sdk\Contracts\InfoNotaDebito;
use Ecuafact\Sdk\Contracts\Motivo;
use Ecuafact\Sdk\Contracts\NotaDebitoRequest;

/**
 * Nota de debito (`NotaDebitoRequest`, tipo 05). Crealo con `ComprobanteBuilder::notaDebito()`. Lleva `motivos` con su
 * valor sin IVA y un unico IVA en `info.impuestos`; el total es `valorTotal`.
 */
final class NotaDebitoBuilder extends AbstractComprobanteBuilder
{
    use ConComprador;
    use ConDocumentoModificado;
    use ConPagos;

    public function __construct(string $ruc, string $estab, string $ptoEmi)
    {
        $solicitud = new NotaDebitoRequest();
        $solicitud->info = new InfoNotaDebito();
        parent::__construct($solicitud, $ruc, $estab, $ptoEmi);
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

    /** IVA de la nota (uno solo). Su base y su valor los asignas con `configurar()`. */
    public function iva(Tarifa $iva): static
    {
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

    public function construir(): NotaDebitoRequest
    {
        /** @var NotaDebitoRequest $solicitud */
        $solicitud = parent::construir();
        return $solicitud;
    }
}
