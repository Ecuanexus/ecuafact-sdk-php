<?php

declare(strict_types=1);

namespace Ecuafact\Sdk;

/**
 * Limite de solicitudes excedido (429). Con `codEstado` 5005 (`limite_solicitudes`) el cliente ya lo reintento
 * respetando `Retry-After` (salvo `retryRateLimited: false`); si llega aqui, espera `retryAfter` segundos. Un 429
 * sin cuerpo o con otro codigo (salvo 4004) tambien llega aqui, sin reintentos.
 */
class EcuafactRateLimitException extends EcuafactApiException
{
}
