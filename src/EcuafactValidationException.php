<?php

declare(strict_types=1);

namespace Ecuafact\Sdk;

/**
 * Solicitud rechazada (`estado` `rechazado`, `codEstado` 3xxx): 400, 413 (cuerpo excedido), 415 (formato de
 * contenido no admitido), 422 y 501 (formato no admitido).
 */
class EcuafactValidationException extends EcuafactApiException
{
}
