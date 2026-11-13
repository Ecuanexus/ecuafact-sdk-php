<?php

declare(strict_types=1);

namespace Ecuafact\Sdk;

/**
 * Resultado de validar un comprobante sin emitirlo. `valido` es true con el 200 del API (entonces
 * `codEstado` y `msgError` son null y `errores` esta vacio) y false con el 422 y `codEstado` 3002
 * (`comprobante_invalido`; entonces `errores` trae el detalle por campo).
 *
 * `advertencias` trae los avisos que el API devuelve junto al resultado (por ejemplo, cuando el XML enviado
 * trae firma, que el API ignora). Un aviso no impide emitir.
 */
final class ValidacionResultado
{
    /**
     * @param string[] $errores
     * @param string[] $advertencias
     */
    public function __construct(
        public readonly bool $valido,
        public readonly ?int $codEstado = null,
        public readonly ?string $msgError = null,
        public readonly array $errores = [],
        public readonly ?string $correlationId = null,
        public readonly array $advertencias = [],
    ) {
    }
}
