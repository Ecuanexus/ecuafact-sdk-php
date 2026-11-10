<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Contracts;

/** Valor de `eventType` del webhook. Un tipo nuevo se lee como Desconocido. */
enum TipoEventoWebhook: string
{
    /** `estado` `autorizado`. Final. */
    case DocumentoAutorizado = 'document.authorized';
    /** `estado` `rechazado` con `codEstado` 3001 `rechazo_sri` (`errores` trae el mensaje del SRI). Final. */
    case DocumentoRechazado = 'document.rejected';
    /** `estado` `rechazado` con 3002 `comprobante_invalido`, `rechazo_configuracion` (4xxx) o `error` (5xxx). Siempre final. */
    case DocumentoFallido = 'document.failed';
    /** Aviso no final: el documento sigue `recibido`. Tambien es el tipo del evento de prueba (`probe`). */
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
