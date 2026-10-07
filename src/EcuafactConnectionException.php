<?php

declare(strict_types=1);

namespace Ecuafact\Sdk;

/** No hubo respuesta HTTP: fallo de red, DNS, TLS o del transporte. */
class EcuafactConnectionException extends EcuafactSdkException
{
    public function __construct(
        string $message,
        public readonly ?int $curlErrno = null,
        public readonly ?string $idempotencyKey = null,
        ?\Throwable $previous = null,
        /** Valor de X-Correlation-Id enviado con `RequestOptions::$correlationId`, si lo hubo. */
        public readonly ?string $correlationId = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** Copia la excepcion agregando la Idempotency-Key (y la correlacion enviada) de la solicitud. */
    public function withIdempotencyKey(?string $idempotencyKey, ?string $correlationId = null): static
    {
        return new static(
            $this->getMessage(),
            $this->curlErrno,
            $idempotencyKey,
            $this,
            $correlationId ?? $this->correlationId
        );
    }
}
