<?php

declare(strict_types=1);

namespace Ecuafact\Sdk;

/** La respuesta supero `maxResponseBytes`; se aborto la lectura. */
class EcuafactResponseTooLargeException extends EcuafactSdkException
{
    public function __construct(
        /** Tope configurado, en bytes. */
        public readonly int $limite,
        /** Bytes leidos al abortar. */
        public readonly int $bytesLeidos,
        public readonly ?string $idempotencyKey = null,
        /** Valor de X-Correlation-Id enviado con `RequestOptions::$correlationId`, si lo hubo. */
        public readonly ?string $correlationId = null,
    ) {
        parent::__construct(
            'La respuesta supera el tope de ' . $limite . ' bytes (maxResponseBytes); se leyeron '
            . $bytesLeidos . ' bytes.'
        );
    }

    /** Copia la excepcion agregando la Idempotency-Key y la correlacion de la solicitud. */
    public function conContexto(?string $idempotencyKey, ?string $correlationId): static
    {
        return new static($this->limite, $this->bytesLeidos, $idempotencyKey, $correlationId);
    }
}
