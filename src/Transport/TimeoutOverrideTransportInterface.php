<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Transport;

/**
 * Transporte que acepta un tiempo por intento que REEMPLAZA a su timeout configurado
 * (a diferencia de {@see TimeoutAwareTransportInterface::requestWithTimeout}, que solo lo recorta).
 * El cliente lo usa para `RequestOptions::$timeout`.
 */
interface TimeoutOverrideTransportInterface extends TimeoutAwareTransportInterface
{
    /**
     * @param string[] $headers Cabeceras con formato "Nombre: valor".
     * @param float $timeoutSeconds Tiempo maximo exacto de esta solicitud (mayor que 0).
     */
    public function requestWithAttemptTimeout(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        float $timeoutSeconds
    ): TransportResponse;
}
