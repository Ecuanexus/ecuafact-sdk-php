<?php

declare(strict_types=1);

namespace Ecuafact\Sdk;

/** Error devuelto por el API con el contrato publico (plano). */
final class EcuafactApiException extends EcuafactSdkException
{
    public readonly ?string $codigo;
    public readonly ?string $mensaje;
    public readonly int $estadoHttp;
    public readonly ?string $idSeguimiento;
    /** @var string[] */
    public readonly array $errores;
    public readonly ?string $idempotencyKey;

    /** @param array<string, mixed>|null $error */
    public function __construct(?array $error, int $estadoHttp, ?string $idSeguimiento, ?string $idempotencyKey)
    {
        $mensaje = is_array($error) ? ($error['mensaje'] ?? null) : null;
        parent::__construct($mensaje ?? ('El API devolvio el estado HTTP ' . $estadoHttp . '.'));
        $this->codigo = is_array($error) ? ($error['codigo'] ?? null) : null;
        $this->mensaje = $mensaje;
        $this->estadoHttp = $estadoHttp;
        $this->idSeguimiento = $idSeguimiento;
        $this->errores = is_array($error) && isset($error['errores']) && is_array($error['errores'])
            ? array_values(array_map('strval', $error['errores']))
            : [];
        $this->idempotencyKey = $idempotencyKey;
    }
}
