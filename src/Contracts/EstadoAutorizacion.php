<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Contracts;

/** Valor de `estadoAutorizacion`. Un valor nuevo del API se lee como Desconocido. */
enum EstadoAutorizacion: string
{
    case NoDisponible = 'no_disponible';
    case Pendiente = 'pendiente';
    case Autorizado = 'autorizado';
    case Error = 'error';
    case Desconocido = 'desconocido';

    /** Convierte el texto del API; nunca falla ante un valor nuevo o nulo. */
    public static function desdeValor(?string $valor): self
    {
        return $valor === null ? self::Desconocido : (self::tryFrom(strtolower(trim($valor))) ?? self::Desconocido);
    }
}
