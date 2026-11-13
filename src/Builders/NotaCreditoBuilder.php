<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Builders;

use Ecuafact\Sdk\Builders\Concerns\ConComprador;
use Ecuafact\Sdk\Builders\Concerns\ConDocumentoModificado;
use Ecuafact\Sdk\Contracts\DetalleNotaCredito;
use Ecuafact\Sdk\Contracts\InfoNotaCredito;
use Ecuafact\Sdk\Contracts\NotaCreditoRequest;

/**
 * Nota de credito (`NotaCreditoRequest`, tipo 04). Crealo con `ComprobanteBuilder::notaCredito()`. En `linea()`,
 * `codigo` va a `codigoInterno` y `codigoAuxiliar` a `codigoAdicional`. El total es `valorModificacion`.
 */
final class NotaCreditoBuilder extends AbstractLineasBuilder
{
    use ConComprador;
    use ConDocumentoModificado;

    public function __construct(string $ruc, string $estab, string $ptoEmi)
    {
        $solicitud = new NotaCreditoRequest();
        $solicitud->info = new InfoNotaCredito();
        parent::__construct($solicitud, $ruc, $estab, $ptoEmi);
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

    /** Agrega una linea armada por ti; se envia tal cual. */
    public function detalle(DetalleNotaCredito $detalle): static
    {
        $this->comprobante->detalles[] = $detalle;
        return $this;
    }

    public function construir(): NotaCreditoRequest
    {
        /** @var NotaCreditoRequest $solicitud */
        $solicitud = parent::construir();
        return $solicitud;
    }

    protected function nuevoDetalle(string $codigo, ?string $codigoAuxiliar): DetalleNotaCredito
    {
        $detalle = new DetalleNotaCredito();
        $detalle->codigoInterno = $codigo;
        $detalle->codigoAdicional = $codigoAuxiliar;
        return $detalle;
    }
}
