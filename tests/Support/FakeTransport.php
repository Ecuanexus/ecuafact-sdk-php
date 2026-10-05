<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Tests\Support;

use Ecuafact\Sdk\Transport\TransportInterface;
use Ecuafact\Sdk\Transport\TransportResponse;

/** Transporte falso que registra peticiones y devuelve respuestas predefinidas. */
final class FakeTransport implements TransportInterface
{
    /** @var array<int, array{method: string, url: string, headers: string[], body: ?string}> */
    public array $peticiones = [];

    /** @var TransportResponse[] */
    private array $respuestas;

    public function __construct(TransportResponse ...$respuestas)
    {
        $this->respuestas = array_values($respuestas);
    }

    public function request(string $method, string $url, array $headers, ?string $body): TransportResponse
    {
        $this->peticiones[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        return count($this->respuestas) > 1 ? array_shift($this->respuestas) : $this->respuestas[0];
    }

    public function header(int $index, string $name): ?string
    {
        foreach ($this->peticiones[$index]['headers'] as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2 && strcasecmp(trim($parts[0]), $name) === 0) {
                return trim($parts[1]);
            }
        }
        return null;
    }
}
