<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Builders\Concerns;

/** Referencia al documento modificado (nota de credito y nota de debito). */
trait ConDocumentoModificado
{
    /**
     * @param string $numDocModificado `ddd-ddd-ddddddddd` (por ejemplo `002-001-000000123`).
     * @param string|\DateTimeInterface $fechaEmisionDocSustento `dd/MM/yyyy` o una fecha.
     */
    public function documentoModificado(
        string $codDocModificado,
        string $numDocModificado,
        string|\DateTimeInterface $fechaEmisionDocSustento
    ): static {
        $info = $this->comprobante->info;
        $info->codDocModificado = $codDocModificado;
        $info->numDocModificado = $numDocModificado;
        $info->fechaEmisionDocSustento = self::fechaTexto($fechaEmisionDocSustento);
        return $this;
    }
}
