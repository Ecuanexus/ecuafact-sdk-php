<?php

declare(strict_types=1);

namespace Ecuafact\Sdk;

/** Error local del SDK (configuracion, transporte o respuesta no reconocida). */
class EcuafactSdkException extends \RuntimeException
{
    public function __construct(string $message, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
