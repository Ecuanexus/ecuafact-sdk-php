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
 * Una falla del cliente se convierte en {@see EcuafactTimeoutException} cuando el tipo de excepcion
 * indica timeout (errno 28 de cURL en `getHandlerContext()` de Guzzle, o `TimeoutExceptionInterface`
 * de Symfony); si no hay esa informacion, se usa el texto del mensaje como respaldo. Cualquier otra
 * falla es {@see EcuafactConnectionException}.
 *
 * ```php
 * $transport = new Psr18Transport($guzzle, $psr17Factory, $psr17Factory);
 * $client = new EcuafactClient(new EcuafactClientOptions(transport: $transport));
 * ```
 */
final class Psr18Transport implements TransportInterface
{
    /** CURLE_OPERATION_TIMEDOUT; se define aqui para no depender de ext-curl. */
    private const CURLE_OPERATION_TIMEDOUT = 28;
    private const SYMFONY_TIMEOUT = 'Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface';
    private const PSR_CLIENT_EXCEPTION = 'Psr\Http\Client\ClientExceptionInterface';
    private const PSR_NETWORK_EXCEPTION = 'Psr\Http\Client\NetworkExceptionInterface';

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
            if (self::esTimeout($exception)) {
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

    /**
     * Decide por el tipo de excepcion. Recorre la cadena (`getPrevious()`):
     * - `TimeoutExceptionInterface` de Symfony: timeout.
     * - `getHandlerContext()['errno']` (Guzzle): timeout solo si es 28.
     * Sin esa informacion, una excepcion PSR-18 que no es de red (`RequestExceptionInterface`) no es
     * timeout; en los demas casos se revisa el texto del mensaje como respaldo.
     */
    private static function esTimeout(\Throwable $exception): bool
    {
        for ($actual = $exception; $actual !== null; $actual = $actual->getPrevious()) {
            if (is_a($actual, self::SYMFONY_TIMEOUT)) {
                return true;
            }
            $errno = self::errnoDelHandler($actual);
            if ($errno !== null) {
                return $errno === self::CURLE_OPERATION_TIMEDOUT;
            }
        }
        if (is_a($exception, self::PSR_CLIENT_EXCEPTION) && !is_a($exception, self::PSR_NETWORK_EXCEPTION)) {
            return false;
        }
        $mensaje = $exception->getMessage();
        return stripos($mensaje, 'timed out') !== false || stripos($mensaje, 'timeout') !== false;
    }

    private static function errnoDelHandler(\Throwable $exception): ?int
    {
        if (!method_exists($exception, 'getHandlerContext')) {
            return null;
        }
        try {
            $contexto = $exception->getHandlerContext();
        } catch (\Throwable) {
            return null;
        }
        if (is_array($contexto) && isset($contexto['errno']) && is_numeric($contexto['errno'])) {
            return (int) $contexto['errno'];
        }
        return null;
    }
}
