<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Transport;

use Ecuafact\Sdk\EcuafactConnectionException;
use Ecuafact\Sdk\EcuafactTimeoutException;

/**
 * Adaptador opcional para usar un cliente PSR-18 (Guzzle, Symfony HttpClient, etc.).
 *
 * No agrega dependencias: requiere que tu proyecto ya tenga `psr/http-client` y `psr/http-factory`
 * (y una implementacion PSR-7/PSR-17). El timeout, el proxy y TLS los configura tu cliente PSR-18;
 * las opciones `timeout`, `connectTimeout`, `proxy`, `caInfo`, `verifyPeer` y `curlOptions` del SDK
 * solo aplican a CurlTransport.
 *
 * ```php
 * $transport = new Psr18Transport($guzzle, $psr17Factory, $psr17Factory);
 * $client = new EcuafactClient(new EcuafactClientOptions(transport: $transport));
 * ```
 */
final class Psr18Transport implements TransportInterface
{
    public function __construct(
        private readonly \Psr\Http\Client\ClientInterface $client,
        private readonly \Psr\Http\Message\RequestFactoryInterface $requestFactory,
        private readonly \Psr\Http\Message\StreamFactoryInterface $streamFactory,
    ) {
    }

    public function request(string $method, string $url, #[\SensitiveParameter] array $headers, ?string $body): TransportResponse
    {
        $request = $this->requestFactory->createRequest($method, $url);
        foreach ($headers as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $request = $request->withHeader(trim($parts[0]), trim($parts[1]));
            }
        }
        if ($body !== null) {
            $request = $request->withBody($this->streamFactory->createStream($body));
        }
        try {
            $response = $this->client->sendRequest($request);
            $content = (string) $response->getBody();
        } catch (\Throwable $exception) {
            if (stripos($exception->getMessage(), 'timed out') !== false
                || stripos($exception->getMessage(), 'timeout') !== false) {
                throw new EcuafactTimeoutException('Se agoto el tiempo de espera de la solicitud.', null, null, $exception);
            }
            throw new EcuafactConnectionException('No hay conexion con el API.', null, null, $exception);
        }
        $responseHeaders = [];
        foreach ($response->getHeaders() as $name => $values) {
            $responseHeaders[(string) $name] = is_array($values) ? implode(', ', $values) : (string) $values;
        }
        return new TransportResponse($response->getStatusCode(), $content, $responseHeaders);
    }
}
