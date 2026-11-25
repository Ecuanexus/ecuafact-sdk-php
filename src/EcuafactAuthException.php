<?php

declare(strict_types=1);

namespace Ecuafact\Sdk;

/** Credencial invalida, IP no autorizada, permiso o condicion del plan rechazados (401/403: 3009, 3010, 3011, 4003, 4005 o 4008). */
class EcuafactAuthException extends EcuafactApiException
{
}
