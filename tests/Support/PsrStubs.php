<?php

// Interfaces PSR minimas para probar Psr18Transport sin instalar psr/http-client ni psr/http-factory.
// Solo se cargan si los paquetes reales no estan instalados.

declare(strict_types=1);

namespace Psr\Http\Client {
    if (!interface_exists(ClientExceptionInterface::class)) {
        interface ClientExceptionInterface extends \Throwable
        {
        }
    }
    if (!interface_exists(NetworkExceptionInterface::class)) {
        interface NetworkExceptionInterface extends ClientExceptionInterface
        {
        }
    }
    if (!interface_exists(RequestExceptionInterface::class)) {
        interface RequestExceptionInterface extends ClientExceptionInterface
        {
        }
    }
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

namespace Symfony\Contracts\HttpClient\Exception {
    if (!interface_exists(TimeoutExceptionInterface::class)) {
        interface TimeoutExceptionInterface extends \Throwable
        {
        }
    }
}
