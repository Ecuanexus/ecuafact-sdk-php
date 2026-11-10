<?php

declare(strict_types=1);

namespace Ecuafact\Sdk;

use Ecuafact\Sdk\Contracts\CodEstado;

/**
 * Error devuelto por el API con la forma unica de error.
 *
 * Cuerpo de error: `{ "estado": "rechazado", "codEstado": 3002, "msgError": "...", "errores": [...] }`.
 * `codEstado` es numerico de 4 digitos (ver {@see CodEstado}); `msgError` es la descripcion general del codigo
 * y `errores`, el detalle especifico. El cliente lanza la subclase que corresponde al estado HTTP y al
 * `codEstado`: {@see EcuafactAuthException} (401/403), {@see EcuafactValidationException} (400/422),
 * {@see EcuafactNotFoundException} (404), {@see EcuafactConflictException} (409),
 * {@see EcuafactRateLimitException} (429, 5005 `limite_solicitudes`),
 * {@see EcuafactQuotaException} (429, 4004 `cupo_agotado`)
 * y {@see EcuafactServerException} (5xx). Un `catch (EcuafactApiException)` las atrapa todas.
 */
class EcuafactApiException extends EcuafactSdkException
{
    /** Codigo numerico del error (`CodEstado::COMPROBANTE_INVALIDO`...); 0 si el cuerpo no lo traia. */
    public readonly int $codEstado;
    /** `estado` del cuerpo (`rechazado`, `rechazo_configuracion`, `error`...); null si no vino. */
    public readonly ?string $estado;
    /** Descripcion general del codigo que envio el API. */
    public readonly ?string $msgError;
    /** Codigo HTTP de la respuesta. */
    public readonly int $status;
    /** Igual que `$status` (nombre anterior, se conserva por compatibilidad). */
    public readonly int $estadoHttp;
    /** Valor de la cabecera X-Correlation-Id o, si no llego, el `correlationId` enviado en la llamada. */
    public readonly ?string $correlationId;
    /** @var string[] Detalle especifico del error (ruta del campo, mensaje del SRI...); vacio si no hay. */
    public readonly array $errores;
    public readonly ?string $idempotencyKey;
    /** Segundos indicados por la cabecera Retry-After, o null si no vino. */
    public readonly ?int $retryAfter;
    /**
     * True si repetir la misma solicitud (con la misma Idempotency-Key) puede tener exito: 408, 425, 500, 502,
     * 503, 504 y el 429 con `codEstado` 5005. El 429 4004 (`cupo_agotado`) nunca.
     */
    public readonly bool $esReintentable;

    /** @param array<string, mixed>|null $error Cuerpo de error decodificado, o null si no era JSON. */
    public function __construct(
        ?array $error,
        int $estadoHttp,
        ?string $correlationId,
        ?string $idempotencyKey,
        ?int $retryAfter = null,
    ) {
        $msgError = self::texto(is_array($error) ? ($error['msgError'] ?? null) : null);
        parent::__construct($msgError ?? ('El API devolvio el estado HTTP ' . $estadoHttp . '.'));
        $this->codEstado = self::codEstadoDe($error);
        $this->estado = self::texto(is_array($error) ? ($error['estado'] ?? null) : null);
        $this->msgError = $msgError;
        $this->status = $estadoHttp;
        $this->estadoHttp = $estadoHttp;
        $this->correlationId = $correlationId;
        $errores = [];
        if (is_array($error) && isset($error['errores']) && is_array($error['errores'])) {
            foreach ($error['errores'] as $item) {
                $texto = self::texto($item);
                if ($texto !== null) {
                    $errores[] = $texto;
                }
            }
        }
        $this->errores = $errores;
        $this->idempotencyKey = $idempotencyKey;
        $this->retryAfter = $retryAfter;
        $this->esReintentable = self::reintentable($estadoHttp, $this->codEstado);
    }

    /**
     * Crea la excepcion tipada que corresponde al estado HTTP y al `codEstado`.
     *
     * @param array<string, mixed>|null $error
     */
    public static function crear(
        ?array $error,
        int $estadoHttp,
        ?string $correlationId,
        ?string $idempotencyKey,
        ?int $retryAfter = null,
    ): self {
        $codEstado = self::codEstadoDe($error);
        $clase = match (true) {
            $estadoHttp === 401, $estadoHttp === 403 => EcuafactAuthException::class,
            $estadoHttp === 400, $estadoHttp === 422 => EcuafactValidationException::class,
            $estadoHttp === 404 => EcuafactNotFoundException::class,
            $estadoHttp === 409 => EcuafactConflictException::class,
            $estadoHttp === 429 && $codEstado === CodEstado::CUPO_AGOTADO => EcuafactQuotaException::class,
            $estadoHttp === 429 => EcuafactRateLimitException::class,
            $estadoHttp >= 500 && $estadoHttp <= 599 => EcuafactServerException::class,
            default => self::class,
        };
        return new $clase($error, $estadoHttp, $correlationId, $idempotencyKey, $retryAfter);
    }

    /**
     * Lee `codEstado` de un cuerpo de error decodificado; 0 si falta o no es un entero.
     *
     * @param array<string, mixed>|null $error
     */
    public static function codEstadoDe(?array $error): int
    {
        $valor = is_array($error) ? ($error['codEstado'] ?? null) : null;
        if (is_int($valor)) {
            return $valor;
        }
        if (is_string($valor) && preg_match('/^\d{1,9}$/D', $valor) === 1) {
            return (int) $valor;
        }
        return 0;
    }

    private static function reintentable(int $estadoHttp, int $codEstado): bool
    {
        if ($estadoHttp === 429) {
            return $codEstado === CodEstado::LIMITE_SOLICITUDES;
        }
        return in_array($estadoHttp, [408, 425, 500, 502, 503, 504], true);
    }

    private static function texto(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        if (is_bool($valor)) {
            return $valor ? 'true' : 'false';
        }
        if (is_scalar($valor)) {
            return (string) $valor;
        }
        $json = json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? null : $json;
    }
}
