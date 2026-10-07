<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Contracts;

/** Valor de `estado` de la operacion. Un valor nuevo del API se lee como Desconocido. */
enum EstadoOperacion: string
{
    case EnCola = 'en_cola';
    case Enviando = 'enviando';
    case Enviado = 'enviado';
    /** No es final: la plataforma sigue verificando. */
    case ResultadoDesconocido = 'resultado_desconocido';
    case Rechazado = 'rechazado';
    case Cancelado = 'cancelado';
    case Desconocido = 'desconocido';

    /** Convierte el texto del API; nunca falla ante un valor nuevo o nulo. */
    public static function desdeValor(?string $valor): self
    {
        return $valor === null ? self::Desconocido : (self::tryFrom(strtolower(trim($valor))) ?? self::Desconocido);
    }
}
