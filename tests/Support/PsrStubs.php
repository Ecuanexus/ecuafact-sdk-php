<?php

// Interfaces PSR minimas para probar Psr18Transport sin instalar psr/http-client ni psr/http-factory.
// Solo se cargan si los paquetes reales no estan instalados.

declare(strict_types=1);

namespace Psr\Http\Client {
    if (!interface_exists(ClientInterface::class)) {
        interface ClientInterface
        {
            public function sendRequest($request);
        }
    }
}

namespace Psr\Http\Message {
    if (!interface_exists(RequestFactoryInterface::class)) {
        interface RequestFactoryInterface
        {
            public function createRequest(string $method, $uri);
        }
    }
    if (!interface_exists(StreamFactoryInterface::class)) {
        interface StreamFactoryInterface
        {
            public function createStream(string $content = '');
        }
    }
}
