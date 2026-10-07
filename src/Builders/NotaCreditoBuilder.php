<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Builders;

use Ecuafact\Sdk\Builders\Concerns\ConComprador;
use Ecuafact\Sdk\Builders\Concerns\ConDocumentoModificado;
use Ecuafact\Sdk\Contracts\Detalle;

/**
 * Nota de credito (codDoc 04). Crealo con `ComprobanteBuilder::notaCredito()`. En `linea()`,
 * `codigo` va a `codigoInterno` y `codigoAuxiliar` a `codigoAdicional`. El total es `valorModificacion`.
 */
final class NotaCreditoBuilder extends AbstractLineasBuilder
{
    use ConComprador;
    use ConDocumentoModificado;

    public function __construct(string $ruc, string $estab, string $ptoEmi, ?Precision $precision = null)
    {
        parent::__construct('04', $ruc, $estab, $ptoEmi, $precision ?? new Precision());
        $this->comprobante->info->moneda = 'DOLAR';
    }

    /** Razon de la nota (`DEVOLUCION`, `DESCUENTO POR PRONTO PAGO`...). */
    public function motivo(string $motivo): static
    {
        $this->comprobante->info->motivo = $motivo;
        return $this;
    }

    /** `importeTotal` de la factura original. Si lo envias, `valorModificacion` no puede superarlo. */
    public function totalDocumentoSustento(int|float|string $valor): static
    {
        $this->comprobante->info->totalDocumentoSustento = self::importe($valor, 'totalDocumentoSustento');
        return $this;
    }

    public function valorModificacion(int|float|string $valor): static
    {
        $this->comprobante->info->valorModificacion = self::importe($valor, 'valorModificacion');
        return $this;
    }

    protected function codDoc(): string
    {
        return '04';
    }

    protected function asignarCodigos(Detalle $detalle, string $codigo, ?string $codigoAuxiliar): void
    {
        $detalle->codigoInterno = $codigo;
        $detalle->codigoAdicional = $codigoAuxiliar;
    }
}
