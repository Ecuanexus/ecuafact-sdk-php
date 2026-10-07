<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Builders\Concerns;

/** Datos del comprador (factura, nota de credito y nota de debito). */
trait ConComprador
{
    /** `tipoIdentificacion`: 04 RUC, 05 cedula, 06 pasaporte, 07 consumidor final (solo factura) u 08 exterior. */
    public function comprador(
        string $tipoIdentificacion,
        string $identificacion,
        string $razonSocial,
        ?string $direccion = null
    ): static {
        $info = $this->comprobante->info;
        $info->tipoIdentificacionComprador = $tipoIdentificacion;
        $info->identificacionComprador = $identificacion;
        $info->razonSocialComprador = $razonSocial;
        if ($direccion !== null) {
            $info->direccionComprador = $direccion;
        }
        return $this;
    }
}
