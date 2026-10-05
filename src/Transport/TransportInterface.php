<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Transport;

/** Abstraccion del transporte HTTP; permite inyectar un doble en las pruebas. */
interface TransportInterface
{
    /**
     * @param string[] $headers Cabeceras con formato "Nombre: valor".
     */
    public function request(string $method, string $url, array $headers, ?string $body): TransportResponse;
}
