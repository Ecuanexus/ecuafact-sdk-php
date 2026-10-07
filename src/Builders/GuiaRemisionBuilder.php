<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Builders;

use Ecuafact\Sdk\Contracts\Destinatario;
use Ecuafact\Sdk\Contracts\Detalle;

/**
 * Guia de remision (codDoc 06). Crealo con `ComprobanteBuilder::guiaRemision()`. No lleva importes:
 * `calcularTotales()` no cambia nada. Lleva exactamente un destinatario.
 */
final class GuiaRemisionBuilder extends AbstractComprobanteBuilder
{
    public function __construct(string $ruc, string $estab, string $ptoEmi, ?Precision $precision = null)
    {
        parent::__construct('06', $ruc, $estab, $ptoEmi, $precision ?? new Precision());
    }

    /** `identificacion` (rucTransportista) sigue al tipo: con 04, 13 digitos; con 05, 10 digitos. */
    public function transportista(
        string $tipoIdentificacion,
        string $identificacion,
        string $razonSocial,
        string $placa
    ): static {
        $info = $this->comprobante->info;
        $info->tipoIdentificacionTransportista = $tipoIdentificacion;
        $info->rucTransportista = $identificacion;
        $info->razonSocialTransportista = $razonSocial;
        $info->placa = $placa;
        return $this;
    }

    /** Direccion de partida y fechas del traslado (`dd/MM/yyyy` o fechas). */
    public function traslado(
        string $dirPartida,
        string|\DateTimeInterface $fechaInicio,
        string|\DateTimeInterface $fechaFin
    ): static {
        $info = $this->comprobante->info;
        $info->dirPartida = $dirPartida;
        $info->fechaIniTransporte = self::fechaTexto($fechaInicio);
        $info->fechaFinTransporte = self::fechaTexto($fechaFin);
        return $this;
    }

    /** Define el destinatario (reemplaza al anterior y conserva sus bienes). `identificacion`: cedula o RUC. */
    public function destinatario(
        string $identificacion,
        string $razonSocial,
        string $direccion,
        string $motivoTraslado,
        ?string $codEstabDestino = null,
        ?string $ruta = null,
        ?string $docAduaneroUnico = null
    ): static {
        $destinatario = new Destinatario();
        $destinatario->identificacionDestinatario = $identificacion;
        $destinatario->razonSocialDestinatario = $razonSocial;
        $destinatario->dirDestinatario = $direccion;
        $destinatario->motivoTraslado = $motivoTraslado;
        $destinatario->codEstabDestino = $codEstabDestino;
        $destinatario->ruta = $ruta;
        $destinatario->docAduaneroUnico = $docAduaneroUnico;
        $destinatario->detalles = $this->comprobante->destinatarios[0]->detalles ?? null;
        $this->comprobante->destinatarios = [$destinatario];
        return $this;
    }

    /** Agrega un bien trasladado al destinatario. */
    public function linea(
        string $codigoInterno,
        string $descripcion,
        int|float|string $cantidad,
        ?string $codigoAdicional = null
    ): static {
        if (!isset($this->comprobante->destinatarios[0])) {
            $this->comprobante->destinatarios = [new Destinatario()];
        }
        $detalle = new Detalle();
        $detalle->codigoInterno = $codigoInterno;
        $detalle->codigoAdicional = $codigoAdicional;
        $detalle->descripcion = $descripcion;
        $detalle->cantidad = self::importe($cantidad, 'cantidad');
        $this->comprobante->destinatarios[0]->detalles[] = $detalle;
        return $this;
    }

    protected function codDoc(): string
    {
        return '06';
    }
}
