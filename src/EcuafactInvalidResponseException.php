<?php

declare(strict_types=1);

namespace Ecuafact\Sdk;

/**
 * La API respondio con un estado de exito (2xx) pero el cuerpo esta vacio o no se pudo leer como JSON.
 *
 * En una emision, la solicitud pudo haberse registrado: no emitas de nuevo con otra `Idempotency-Key`; repite la
 * llamada con `$idempotencyKey` o consulta el resultado. Trae el estado HTTP, la `Idempotency-Key` y el
 * `X-Correlation-Id` para pedir soporte.
 */
class EcuafactInvalidResponseException extends EcuafactSdkException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $correlationId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
