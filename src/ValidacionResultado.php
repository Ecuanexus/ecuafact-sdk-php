<?php

declare(strict_types=1);

namespace Ecuafact\Sdk;

/**
 * Resultado de validar un comprobante sin emitirlo. `valido` es true con el 200 del API y false con el
 * 422/302 (comprobante invalido); `errores` trae los problemas que devolvio el API.
 */
final class ValidacionResultado
{
    /** @param string[] $errores */
    public function __construct(
        public readonly bool $valido,
        public readonly string $codigo,
        public readonly string $mensaje,
        public readonly array $errores = [],
        public readonly ?string $correlationId = null,
    ) {
    }
}
