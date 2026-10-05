<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Transport;

use Ecuafact\Sdk\EcuafactSdkException;

/** Transporte HTTP basado en cURL. */
final class CurlTransport implements TransportInterface
{
    public function __construct(private readonly float $timeout = 100.0)
    {
    }

    public function request(string $method, string $url, array $headers, ?string $body): TransportResponse
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new EcuafactSdkException('No fue posible iniciar la solicitud HTTP.');
        }
        $responseHeaders = [];
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => (int) ceil($this->timeout),
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[trim($parts[0])] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $result = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($result === false) {
            throw new EcuafactSdkException('No hay conexion con el API. ' . $error);
        }
        return new TransportResponse($status, (string) $result, $responseHeaders);
    }
}
