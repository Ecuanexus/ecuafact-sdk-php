<?php

declare(strict_types=1);

namespace Ecuafact\Sdk;

use Ecuafact\Sdk\Contracts\ComprobanteRequest;
use Ecuafact\Sdk\Contracts\PaginaComprobantes;
use Ecuafact\Sdk\Contracts\QuotaBucket;

/** Vista de emision y consulta fijada a un RUC (multi-RUC). */
final class EcuafactContribuyente
{
    public function __construct(
        private readonly EcuafactClient $client,
        private readonly string $identificacion,
    ) {
        if (trim($identificacion) === '') {
            throw new EcuafactSdkException('El RUC es obligatorio.');
        }
    }

    public function identificacion(): string
    {
        return $this->identificacion;
    }

    public function emitir(ComprobanteRequest $comprobante, ?string $idempotencyKey = null): EmisionResultado
    {
        return $this->client->emitirEn($this->identificacion, $comprobante, $idempotencyKey);
    }

    public function listarEmitidos(?ListadoRequest $filtros = null): PaginaComprobantes
    {
        return $this->client->listarEmitidosEn($this->identificacion, $filtros);
    }

    public function listarRecibidos(?ListadoRequest $filtros = null): PaginaComprobantes
    {
        return $this->client->listarRecibidosEn($this->identificacion, $filtros);
    }

    public function getConsumo(): QuotaBucket
    {
        return $this->client->getConsumo();
    }
}
