<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Transport;

/**
 * Transporte que acepta un tiempo maximo por solicitud. El cliente lo usa para que
 * `totalTimeoutSeconds` tambien recorte el intento en curso.
 */
interface TimeoutAwareTransportInterface extends TransportInterface
{
    /**
     * @param string[] $headers Cabeceras con formato "Nombre: valor".
     * @param float $timeoutSeconds Tiempo maximo de esta solicitud (mayor que 0).
     */
    public function requestWithTimeout(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        float $timeoutSeconds
    ): TransportResponse;
}
