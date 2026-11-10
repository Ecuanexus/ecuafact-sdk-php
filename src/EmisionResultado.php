<?php

declare(strict_types=1);

namespace Ecuafact\Sdk;

use Ecuafact\Sdk\Contracts\Admission;

/**
 * Resultado de una emision: admision (misma forma que la operacion: `estado`, `codEstado`, `idOperacion`,
 * `urlEstado` absoluta...), clave de idempotencia efectiva y correlacion. En envio directo la admision ya puede
 * traer un estado final (2001 autorizado, 3xxx o 4xxx).
 */
final class EmisionResultado
{
    public function __construct(
        public readonly Admission $admission,
        public readonly string $idempotencyKey,
        public readonly ?string $correlationId = null,
    ) {
    }
}
