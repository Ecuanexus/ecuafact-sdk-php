<?php

declare(strict_types=1);

namespace Ecuafact\Sdk;

use Ecuafact\Sdk\Contracts\Admission;

/** Resultado de una emision: admision, clave de idempotencia efectiva y correlacion. */
final class EmisionResultado
{
    public function __construct(
        public readonly Admission $admission,
        public readonly string $idempotencyKey,
        public readonly ?string $correlationId = null,
    ) {
    }
}
