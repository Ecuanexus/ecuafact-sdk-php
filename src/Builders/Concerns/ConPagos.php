<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Builders\Concerns;

/** Formas de pago en `info.pagos` (factura, liquidacion de compra y nota de debito). */
trait ConPagos
{
    /**
     * Agrega una forma de pago. Si dejas `total` en null, no se envia y lo resuelve o rechaza el API.
     */
    public function pago(
        string $formaPago,
        int|float|string|null $total = null,
        ?int $plazo = null,
        ?string $unidadTiempo = null
    ): static {
        $this->comprobante->info->pagos[] = self::nuevoPago($formaPago, $total, $plazo, $unidadTiempo);
        return $this;
    }
}
