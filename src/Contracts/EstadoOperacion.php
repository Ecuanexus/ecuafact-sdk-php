<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Contracts;

/**
 * Valor de `estado` de la operacion y del webhook. `Recibido` no es final:
 * consulta la operacion hasta un estado final. Un valor nuevo del API se lee como Desconocido.
 */
enum EstadoOperacion: string
{
    /** El documento paso las validaciones y se acepto; aun no hay resultado final. */
    case Recibido = 'recibido';
    /** Autorizado por el SRI. Final. */
    case Autorizado = 'autorizado';
    /** Rechazado por el contenido del comprobante. Final. */
    case Rechazado = 'rechazado';
    /** Falta o esta mal una configuracion del emisor. Final. */
    case RechazoConfiguracion = 'rechazo_configuracion';
    /** Fallo que impidio definitivamente la autorizacion. Final. */
    case Error = 'error';
    /** Envio cancelado por la plataforma. Final. */
    case Cancelado = 'cancelado';
    /** Valor no reconocido por esta version del SDK. No es final. */
    case Desconocido = 'desconocido';

    /** Convierte el texto del API; nunca falla ante un valor nuevo o nulo. */
    public static function desdeValor(?string $valor): self
    {
        return $valor === null ? self::Desconocido : (self::tryFrom(strtolower(trim($valor))) ?? self::Desconocido);
    }

    /** True para autorizado, rechazado, rechazo_configuracion, error y cancelado. */
    public function esFinal(): bool
    {
        return match ($this) {
            self::Autorizado, self::Rechazado, self::RechazoConfiguracion, self::Error, self::Cancelado => true,
            self::Recibido, self::Desconocido => false,
        };
    }

    /** True cuando el estado es autorizado. */
    public function esAutorizado(): bool
    {
        return $this === self::Autorizado;
    }
}
