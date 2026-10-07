<?php

declare(strict_types=1);

namespace Ecuafact\Sdk;

/** Conflicto de idempotencia o comprobante duplicado (409). */
class EcuafactConflictException extends EcuafactApiException
{
}
