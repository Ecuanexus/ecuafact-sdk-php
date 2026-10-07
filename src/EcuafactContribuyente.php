<?php

declare(strict_types=1);

namespace Ecuafact\Sdk;

use Ecuafact\Sdk\Contracts\ArchivoComprobante;
use Ecuafact\Sdk\Contracts\Comprobante;
use Ecuafact\Sdk\Contracts\ComprobanteRequest;
use Ecuafact\Sdk\Contracts\CorreoEnviado;
use Ecuafact\Sdk\Contracts\Operation;
use Ecuafact\Sdk\Contracts\PaginaComprobantes;
use Ecuafact\Sdk\Contracts\PerfilEmisor;
use Ecuafact\Sdk\Contracts\PerfilEmisorUpdate;
use Ecuafact\Sdk\Contracts\QuotaBucket;

/**
 * Vista de emision y consulta fijada a un contribuyente (multi-RUC). La `identificacion` es el valor
 * `identificacion` de `GET /v1/contexto` (por ejemplo `0123456789`).
 *
 * Cada metodo acepta como ultimo argumento `?RequestOptions $opciones` (solo para esa llamada).
 */
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

    public function emitir(
        ComprobanteRequest $comprobante,
        ?string $idempotencyKey = null,
        ?RequestOptions $opciones = null
    ): EmisionResultado {
        return $this->client->emitirEn($this->identificacion, $comprobante, $idempotencyKey, $opciones);
    }

    public function listarEmitidos(?ListadoRequest $filtros = null, ?RequestOptions $opciones = null): PaginaComprobantes
    {
        return $this->client->listarEmitidosEn($this->identificacion, $filtros, $opciones);
    }

    /** @return \Generator<int, Comprobante> */
    public function iterarEmitidos(?ListadoRequest $filtros = null, ?RequestOptions $opciones = null): \Generator
    {
        return $this->client->iterarEmitidosEn($this->identificacion, $filtros, $opciones);
    }

    public function descargarRide(string $claveAcceso, ?RequestOptions $opciones = null): ArchivoComprobante
    {
        return $this->client->descargarRide($this->identificacion, $claveAcceso, $opciones);
    }

    public function descargarXml(string $claveAcceso, ?RequestOptions $opciones = null): ArchivoComprobante
    {
        return $this->client->descargarXml($this->identificacion, $claveAcceso, $opciones);
    }

    public function enviarCorreo(string $claveAcceso, string $destinatario, ?RequestOptions $opciones = null): CorreoEnviado
    {
        return $this->client->enviarCorreo($this->identificacion, $claveAcceso, $destinatario, $opciones);
    }

    public function getPerfil(?RequestOptions $opciones = null): PerfilEmisor
    {
        return $this->client->getPerfil($this->identificacion, $opciones);
    }

    public function actualizarPerfil(PerfilEmisorUpdate $cambios, ?RequestOptions $opciones = null): PerfilEmisor
    {
        return $this->client->actualizarPerfil($this->identificacion, $cambios, $opciones);
    }

    public function actualizarLogo(
        string $contenido,
        string $contentType,
        string $nombreArchivo = 'logo',
        ?RequestOptions $opciones = null
    ): PerfilEmisor {
        return $this->client->actualizarLogo($this->identificacion, $contenido, $contentType, $nombreArchivo, $opciones);
    }

    /** Igual que EcuafactClient::esperarResultado (la operacion no depende del RUC). */
    public function esperarResultado(
        string $idOperacion,
        float $intervalo = 5.0,
        float $maximo = 120.0,
        ?RequestOptions $opciones = null
    ): Operation {
        return $this->client->esperarResultado($idOperacion, $intervalo, $maximo, $opciones);
    }

    public function getConsumo(?RequestOptions $opciones = null): QuotaBucket
    {
        return $this->client->getConsumo($opciones);
    }
}
