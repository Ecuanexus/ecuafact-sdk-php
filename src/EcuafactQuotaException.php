<?php

declare(strict_types=1);

namespace Ecuafact\Sdk;

/** Cupo de documentos agotado (429, codigo 501). Nunca se reintenta. */
class EcuafactQuotaException extends EcuafactApiException
{
}
