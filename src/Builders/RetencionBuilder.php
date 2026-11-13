<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Builders;

use Ecuafact\Sdk\Contracts\DocsSustento;
use Ecuafact\Sdk\Contracts\ImpuestoDocSustento;
use Ecuafact\Sdk\Contracts\InfoComprobanteRetencion;
use Ecuafact\Sdk\Contracts\RetencionLinea;
use Ecuafact\Sdk\Contracts\RetencionRequest;
use Ecuafact\Sdk\EcuafactSdkException;

/**
 * Comprobante de retencion (`RetencionRequest`, tipo 07). Crealo con `ComprobanteBuilder::retencion()`. Lleva un solo
 * documento de sustento: llama a `docSustento()` antes de `impuestoSustento()`, `lineaRetencion()` y `pago()`.
 */
final class RetencionBuilder extends AbstractComprobanteBuilder
{
    public function __construct(string $ruc, string $estab, string $ptoEmi)
    {
        $solicitud = new RetencionRequest();
        $solicitud->info = new InfoComprobanteRetencion();
        parent::__construct($solicitud, $ruc, $estab, $ptoEmi);
    }

    public function fechaEmision(string|\DateTimeInterface $fecha): static
    {
        $this->comprobante->info->fechaEmision = self::fechaTexto($fecha);
        return $this;
    }

    /** Proveedor retenido. `tipoIdentificacion`: 04 RUC, 05 cedula, 06 pasaporte u 08 exterior. */
    public function sujetoRetenido(string $tipoIdentificacion, string $identificacion, string $razonSocial): static
    {
        $info = $this->comprobante->info;
        $info->tipoIdentificacionSujetoRetenido = $tipoIdentificacion;
        $info->identificacionSujetoRetenido = $identificacion;
        $info->razonSocialSujetoRetenido = $razonSocial;
        return $this;
    }

    /** `periodoFiscal` en `MM/yyyy` y si tu y el proveedor son partes relacionadas (`parteRel` SI o NO). */
    public function periodo(string $periodoFiscal, bool $parteRelacionada = false): static
    {
        $this->comprobante->info->periodoFiscal = $periodoFiscal;
        $this->comprobante->info->parteRel = $parteRelacionada ? 'SI' : 'NO';
        return $this;
    }

    /**
     * Define el documento de sustento (la factura del proveedor). `numDocSustento`: 15 digitos o
     * `ddd-ddd-ddddddddd`. Los importes se envian tal cual; si los dejas en null no se envian. `pagoLocExt`
     * tampoco tiene valor por defecto: se envia solo si lo indicas.
     */
    public function docSustento(
        string $codSustento,
        string $codDocSustento,
        string $numDocSustento,
        string|\DateTimeInterface $fechaEmisionDocSustento,
        string|\DateTimeInterface $fechaRegistroContable,
        int|float|string|null $totalSinImpuestos = null,
        int|float|string|null $importeTotal = null,
        ?string $numAutDocSustento = null,
        ?string $pagoLocExt = null
    ): static {
        $doc = new DocsSustento();
        $doc->codSustento = $codSustento;
        $doc->codDocSustento = $codDocSustento;
        $doc->numDocSustento = $numDocSustento;
        $doc->fechaEmisionDocSustento = self::fechaTexto($fechaEmisionDocSustento);
        $doc->fechaRegistroContable = self::fechaTexto($fechaRegistroContable);
        $doc->numAutDocSustento = $numAutDocSustento;
        $doc->pagoLocExt = $pagoLocExt;
        $doc->totalSinImpuestos = $totalSinImpuestos === null ? null : self::importe($totalSinImpuestos, 'totalSinImpuestos');
        $doc->importeTotal = $importeTotal === null ? null : self::importe($importeTotal, 'importeTotal');
        $this->comprobante->docsSustento = [$doc];
        return $this;
    }

    /** Impuesto de la factura del proveedor (`2` IVA, `3` ICE). `valorImpuesto` null: no se envia. */
    public function impuestoSustento(
        string $codImpuesto,
        string $codigoPorcentaje,
        int|float|string $baseImponible,
        int|float|string $tarifa,
        int|float|string|null $valorImpuesto = null
    ): static {
        $impuesto = new ImpuestoDocSustento();
        $impuesto->codImpuestoDocSustento = $codImpuesto;
        $impuesto->codigoPorcentaje = $codigoPorcentaje;
        $impuesto->baseImponible = self::importe($baseImponible, 'baseImponible');
        $impuesto->tarifa = self::importe($tarifa, 'tarifa');
        $impuesto->valorImpuesto = $valorImpuesto === null ? null : self::importe($valorImpuesto, 'valorImpuesto');
        $this->doc()->impuestosDocSustento[] = $impuesto;
        return $this;
    }

    /** Retencion: `codigo` 1 renta, 2 IVA o 6 ISD. `valorRetenido` null: no se envia. */
    public function lineaRetencion(
        string $codigo,
        string $codigoRetencion,
        int|float|string $baseImponible,
        int|float|string $porcentajeRetener,
        int|float|string|null $valorRetenido = null
    ): static {
        $linea = new RetencionLinea();
        $linea->codigo = $codigo;
        $linea->codigoRetencion = $codigoRetencion;
        $linea->baseImponible = self::importe($baseImponible, 'baseImponible');
        $linea->porcentajeRetener = self::importe($porcentajeRetener, 'porcentajeRetener');
        $linea->valorRetenido = $valorRetenido === null ? null : self::importe($valorRetenido, 'valorRetenido');
        $this->doc()->retenciones[] = $linea;
        return $this;
    }

    /** Forma de pago de la factura del proveedor. */
    public function pago(
        string $formaPago,
        int|float|string|null $total = null,
        ?int $plazo = null,
        ?string $unidadTiempo = null
    ): static {
        $this->doc()->pagos[] = self::nuevoPago($formaPago, $total, $plazo, $unidadTiempo);
        return $this;
    }

    public function construir(): RetencionRequest
    {
        /** @var RetencionRequest $solicitud */
        $solicitud = parent::construir();
        return $solicitud;
    }

    private function doc(): DocsSustento
    {
        $doc = $this->comprobante->docsSustento[0] ?? null;
        if (!$doc instanceof DocsSustento) {
            throw new EcuafactSdkException('Llama a docSustento() antes de agregar impuestos, retenciones o pagos.');
        }
        return $doc;
    }
}
