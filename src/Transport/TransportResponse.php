<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Transport;

/** Respuesta cruda del transporte. */
final class TransportResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        private readonly array $headers = [],
    ) {
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return is_array($value) ? ($value[0] ?? null) : (string) $value;
            }
        }
        return null;
    }
}
