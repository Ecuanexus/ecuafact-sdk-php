<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Contracts;

/** Tipo de comprobante (`codDoc`). */
enum TipoComprobante: string
{
    case Factura = '01';
    case LiquidacionCompra = '03';
    case NotaCredito = '04';
    case NotaDebito = '05';
    case GuiaRemision = '06';
    case Retencion = '07';

    /** Convierte el `codDoc`; devuelve null ante un codigo no reconocido (nunca falla). */
    public static function desdeValor(?string $codDoc): ?self
    {
        return $codDoc === null ? null : self::tryFrom(trim($codDoc));
    }
}
