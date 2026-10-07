<?php

declare(strict_types=1);

namespace Ecuafact\Sdk;

/** Limite de solicitudes excedido (429, codigo 104). Se reintenta solo con retryRateLimited. */
class EcuafactRateLimitException extends EcuafactApiException
{
}
