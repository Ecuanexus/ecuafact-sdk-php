<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Contracts;

/** Valores de texto de `estado` publicados por el API. El enum tipado es {@see EstadoOperacion}. */
final class EstadosPublicos
{
    public const RECIBIDO = 'recibido';
    public const AUTORIZADO = 'autorizado';
    public const RECHAZADO = 'rechazado';
    public const RECHAZO_CONFIGURACION = 'rechazo_configuracion';
    public const ERROR = 'error';
    public const CANCELADO = 'cancelado';

    private function __construct()
    {
    }
}
