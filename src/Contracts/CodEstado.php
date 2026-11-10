<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Contracts;

/**
 * Catalogo de `codEstado`: numero de 4 digitos cuyo primer digito es el `estado` (1xxx `recibido`,
 * 2xxx `autorizado`, 3xxx `rechazado`, 4xxx `rechazo_configuracion`, 5xxx `error`, 6xxx `cancelado`).
 *
 * En el JSON solo viaja el numero; los nombres existen para escribir codigo legible, por ejemplo
 * `$e->codEstado === CodEstado::COMPROBANTE_INVALIDO`. `msgError` trae la descripcion general del codigo y
 * `errores` el detalle especifico. Un codigo nuevo se interpreta por su primer digito con {@see self::estado()}.
 */
final class CodEstado
{
    /** Recibido, todavia sin clave de acceso (202 / seguimiento). */
    public const COMPROBANTE_RECIBIDO = 1001;
    /** Recibido, con `claveAcceso` (202 / seguimiento). */
    public const COMPROBANTE_ENVIADO = 1002;
    /** Autorizado por el SRI (202 en envio directo / seguimiento). */
    public const COMPROBANTE_AUTORIZADO = 2001;
    /** Seguimiento: "El SRI rechazo el comprobante."; `errores` trae el mensaje del SRI. */
    public const RECHAZO_SRI = 3001;
    /** 422 / seguimiento: "El comprobante no cumple las validaciones." */
    public const COMPROBANTE_INVALIDO = 3002;
    /** 400: "La solicitud no es valida." */
    public const SOLICITUD_INVALIDA = 3003;
    /** 409: "Ya existe un comprobante con ese numero." */
    public const COMPROBANTE_DUPLICADO = 3004;
    /** 409: "La Idempotency-Key ya se uso con otro contenido." */
    public const CONFLICTO_IDEMPOTENCIA = 3005;
    /** 413: "El cuerpo supera el tamano permitido." */
    public const CUERPO_EXCEDIDO = 3006;
    /** 400: "Version de API no admitida." */
    public const VERSION_NO_SOPORTADA = 3007;
    /** 501: "Formato no admitido." */
    public const NO_IMPLEMENTADO = 3008;
    /** 401: "Credenciales invalidas." */
    public const CREDENCIALES_INVALIDAS = 3009;
    /** 403: "IP no autorizada." */
    public const IP_NO_AUTORIZADA = 3010;
    /** 403: "Contribuyente, tipo o suscripcion no habilitados." */
    public const ACCESO_DENEGADO = 3011;
    /** 400: "Filtros invalidos." */
    public const FILTROS_INVALIDOS = 3012;
    /** 404: "Recurso no encontrado." */
    public const NO_ENCONTRADO = 3013;
    /** 409: "Correo no disponible para el emisor." */
    public const CORREO_NO_DISPONIBLE = 3014;
    /** Seguimiento: "Al emisor le falta configuracion." */
    public const EMISOR_NO_CONFIGURADO = 4001;
    /** Seguimiento: "La firma electronica no es valida." */
    public const FIRMA_NO_VALIDA = 4002;
    /** Seguimiento: "La suscripcion no esta activa." */
    public const SUSCRIPCION_INACTIVA = 4003;
    /** 429 / seguimiento: "Cupo agotado." Nunca se reintenta. */
    public const CUPO_AGOTADO = 4004;
    /** 500 / seguimiento: "Error interno." */
    public const ERROR_INTERNO = 5001;
    /** 503: "Servicio temporalmente no disponible." */
    public const SERVICIO_NO_DISPONIBLE = 5002;
    /** 502: "Un servicio externo reporto un error." */
    public const ERROR_DEPENDENCIA = 5003;
    /** 504: "Un servicio externo no respondio a tiempo." */
    public const TIEMPO_AGOTADO = 5004;
    /** 429: "Limite de solicitudes excedido." Es el unico 429 que el cliente reintenta. */
    public const LIMITE_SOLICITUDES = 5005;
    /** Seguimiento: "El comprobante no se pudo emitir." */
    public const ENTREGA_NO_POSIBLE = 5006;
    /** Seguimiento: "No se pudo confirmar el resultado."; verificalo antes de emitir de nuevo. */
    public const RESULTADO_NO_CONFIRMADO = 5007;
    /** Seguimiento: "La plataforma cancelo el envio." */
    public const OPERACION_CANCELADA = 6001;

    private function __construct()
    {
    }

    /**
     * `estado` que corresponde al codigo segun su primer digito. Un codigo fuera de 1000-6999 devuelve
     * EstadoOperacion::Desconocido.
     */
    public static function estado(int $codEstado): EstadoOperacion
    {
        if ($codEstado < 1000 || $codEstado > 9999) {
            return EstadoOperacion::Desconocido;
        }
        return match (intdiv($codEstado, 1000)) {
            1 => EstadoOperacion::Recibido,
            2 => EstadoOperacion::Autorizado,
            3 => EstadoOperacion::Rechazado,
            4 => EstadoOperacion::RechazoConfiguracion,
            5 => EstadoOperacion::Error,
            6 => EstadoOperacion::Cancelado,
            default => EstadoOperacion::Desconocido,
        };
    }

    /** Nombre del codigo en el catalogo (por ejemplo `comprobante_invalido`), o null si no se conoce. */
    public static function nombre(int $codEstado): ?string
    {
        foreach ((new \ReflectionClass(self::class))->getConstants() as $nombre => $valor) {
            if ($valor === $codEstado) {
                return strtolower($nombre);
            }
        }
        return null;
    }
}
