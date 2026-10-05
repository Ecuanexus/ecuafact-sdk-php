<?php

declare(strict_types=1);

namespace Ecuafact\Sdk;

use Ecuafact\Sdk\Transport\TransportInterface;

/** Configuracion del cliente Ecuafact. */
final class EcuafactClientOptions
{
    public function __construct(
        public readonly string $baseAddress,
        public readonly string $apiKey,
        public readonly ?string $identificacion = null,
        public readonly float $timeout = 100.0,
        public readonly string $userAgent = 'Ecuafact.Sdk/1.0',
        public readonly bool $retryTransientFailures = true,
        public readonly int $maxAttempts = 3,
        public readonly ?TransportInterface $transport = null,
        public readonly bool $respectRetryAfter = true,
        public readonly float $maxRetryDelaySeconds = 60.0,
    ) {
        if (trim($this->apiKey) === '') {
            throw new EcuafactSdkException('Falta la credencial (apiKey / cabecera X-Api-Key).');
        }
        if (trim($this->baseAddress) === '') {
            throw new EcuafactSdkException('Falta la direccion base del API (baseAddress).');
        }
    }
}
