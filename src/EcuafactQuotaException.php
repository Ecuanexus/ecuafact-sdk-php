<?php

declare(strict_types=1);

namespace Ecuafact\Sdk;

/** Cupo de documentos agotado (429, `codEstado` 4004 `cupo_agotado`). Nunca se reintenta. */
class EcuafactQuotaException extends EcuafactApiException
{
}
