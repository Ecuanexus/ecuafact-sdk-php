<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Builders;

/**
 * Una inconsistencia detectada por `validar()`: ruta del campo, descripcion y, cuando es un
 * descuadre, el valor esperado y el valor actual.
 */
final class Inconsistencia implements \Stringable
{
    public function __construct(
        /** Ruta JSON del campo, por ejemplo `info.importeTotal` o `detalles[1].impuestos[0].valor`. */
        public readonly string $campo,
        public readonly string $mensaje,
        /** Valor que corresponde segun las reglas del API (solo en descuadres). */
        public readonly ?string $esperado = null,
        /** Valor que tiene el comprobante (solo en descuadres). */
        public readonly ?string $actual = null,
    ) {
    }

    public function __toString(): string
    {
        $texto = $this->campo . ': ' . $this->mensaje;
        if ($this->esperado !== null || $this->actual !== null) {
            $texto .= ' (esperado ' . ($this->esperado ?? '-') . ', actual ' . ($this->actual ?? '-') . ')';
        }
        return $texto;
    }
}
