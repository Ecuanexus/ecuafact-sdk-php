<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Transport;

/**
 * Transporte que corta la lectura del cuerpo al superar un tope. El cliente lo fija antes de cada solicitud
 * con `maxResponseBytes` (o `maxDownloadBytes` en descargas); `null` = sin tope. Al superarlo lanza
 * {@see \Ecuafact\Sdk\EcuafactResponseTooLargeException}.
 */
interface ResponseLimitAwareTransportInterface
{
    public function limitarRespuesta(?int $maxBytes): void;
}
