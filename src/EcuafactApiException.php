<?php

declare(strict_types=1);

namespace Ecuafact\Sdk;

/**
 * Error devuelto por el API con el contrato publico (plano).
 *
 * El cliente lanza la subclase que corresponde al estado HTTP y al `codigo`:
 * {@see EcuafactAuthException} (401/403), {@see EcuafactValidationException} (400/422),
 * {@see EcuafactNotFoundException} (404), {@see EcuafactConflictException} (409),
 * {@see EcuafactRateLimitException} (429, codigo 104), {@see EcuafactQuotaException} (429, codigo 501)
 * y {@see EcuafactServerException} (5xx). Un `catch (EcuafactApiException)` las atrapa todas.
 */
class EcuafactApiException extends EcuafactSdkException
{
    /** Codigo publico del error (siempre string, aunque el API lo envie como numero). */
    public readonly ?string $codigo;
    public readonly ?string $mensaje;
    public readonly int $estadoHttp;
    /** Valor de la cabecera X-Correlation-Id. */
    public readonly ?string $idSeguimiento;
    /** @var string[] */
    public readonly array $errores;
    public readonly ?string $idempotencyKey;
    /** Segundos indicados por la cabecera Retry-After, o null si no vino. */
    public readonly ?int $retryAfter;
    /** True si repetir la misma solicitud (con la misma Idempotency-Key) puede tener exito. */
    public readonly bool $esReintentable;

    /** @param array<string, mixed>|null $error */
    public function __construct(
        ?array $error,
        int $estadoHttp,
        ?string $idSeguimiento,
        ?string $idempotencyKey,
        ?int $retryAfter = null,
    ) {
        $mensaje = self::texto(is_array($error) ? ($error['mensaje'] ?? null) : null);
        parent::__construct($mensaje ?? ('El API devolvio el estado HTTP ' . $estadoHttp . '.'));
        $this->codigo = self::texto(is_array($error) ? ($error['codigo'] ?? null) : null);
        $this->mensaje = $mensaje;
        $this->estadoHttp = $estadoHttp;
        $this->idSeguimiento = $idSeguimiento;
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
        $this->esReintentable = self::reintentable($estadoHttp, $this->codigo);
    }

    /**
     * Crea la excepcion tipada que corresponde al estado HTTP y al codigo publico.
     *
     * @param array<string, mixed>|null $error
     */
    public static function crear(
        ?array $error,
        int $estadoHttp,
        ?string $idSeguimiento,
        ?string $idempotencyKey,
        ?int $retryAfter = null,
    ): self {
        $codigo = self::texto(is_array($error) ? ($error['codigo'] ?? null) : null);
        $clase = match (true) {
            $estadoHttp === 401, $estadoHttp === 403 => EcuafactAuthException::class,
            $estadoHttp === 400, $estadoHttp === 422 => EcuafactValidationException::class,
            $estadoHttp === 404 => EcuafactNotFoundException::class,
            $estadoHttp === 409 => EcuafactConflictException::class,
            $estadoHttp === 429 && $codigo === '501' => EcuafactQuotaException::class,
            $estadoHttp === 429 => EcuafactRateLimitException::class,
            $estadoHttp >= 500 && $estadoHttp <= 599 => EcuafactServerException::class,
            default => self::class,
        };
        return new $clase($error, $estadoHttp, $idSeguimiento, $idempotencyKey, $retryAfter);
    }

    private static function reintentable(int $estadoHttp, ?string $codigo): bool
    {
        if ($estadoHttp === 429) {
            return $codigo !== '501';
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
