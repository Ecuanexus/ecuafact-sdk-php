<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Contracts;

/** Valor de `eventType` del webhook. Un tipo nuevo se lee como Desconocido. */
enum TipoEventoWebhook: string
{
    case DocumentoAutorizado = 'document.authorized';
    case DocumentoRechazado = 'document.rejected';
    case DocumentoFallido = 'document.failed';
    case OperacionRequiereAtencion = 'operation.requires_attention';
    case Desconocido = 'desconocido';

    /** Convierte el texto del evento; nunca falla ante un valor nuevo o nulo. */
    public static function desdeValor(?string $valor): self
    {
        if ($valor === null) {
            return self::Desconocido;
        }
        $tipo = self::tryFrom(strtolower(trim($valor)));
        return $tipo === null || $tipo === self::Desconocido ? self::Desconocido : $tipo;
    }
}
