<?php

declare(strict_types=1);

namespace Ecuafact\Sdk;

/** Saldo agotado (429, `codEstado` 4004 `cupo_agotado`, 4006 `consultas_sandbox_agotadas` o 4007 `consultas_sri_agotadas`). Nunca se reintenta. */
class EcuafactQuotaException extends EcuafactApiException
{
}
