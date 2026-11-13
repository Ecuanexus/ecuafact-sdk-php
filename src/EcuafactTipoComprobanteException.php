<?php

declare(strict_types=1);

namespace Ecuafact\Sdk;

/**
 * El tipo de comprobante de un filtro (por ejemplo `tipoDocumento` de `listarOperaciones`) no es valido. En `emitir` y
 * `validar` no se lanza: el tipo lo da la clase de la solicitud.
 */
class EcuafactTipoComprobanteException extends EcuafactSdkException
{
}
