<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Builders;

use Ecuafact\Sdk\Contracts\DocsSustento;
use Ecuafact\Sdk\Contracts\ImpuestoDocSustento;
use Ecuafact\Sdk\Contracts\RetencionLinea;
use Ecuafact\Sdk\EcuafactSdkException;

/**
 * Comprobante de retencion (codDoc 07). Crealo con `ComprobanteBuilder::retencion()`. Lleva un solo
 * documento de sustento: llama a `docSustento()` antes de `impuestoSustento()`, `lineaRetencion()` y `pago()`.
 */
final class RetencionBuilder extends AbstractComprobanteBuilder
{
    public function __construct(string $ruc, string $estab, string $ptoEmi, ?Precision $precision = null)
    {
        parent::__construct('07', $ruc, $estab, $ptoEmi, $precision ?? new Precision());
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
     * `ddd-ddd-ddddddddd`. Con `calcularTotales()` se rellenan, si los dejas en null, `totalSinImpuestos`
     * (suma de las bases de `impuestoSustento()`) e `importeTotal` (`totalSinImpuestos` + impuestos).
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
        string $pagoLocExt = '01'
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

    /** Impuesto de la factura del proveedor (`2` IVA, `3` ICE). `valorImpuesto` null: se calcula. */
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

    /** Retencion: `codigo` 1 renta, 2 IVA o 6 ISD. `valorRetenido` null: se calcula. */
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

    /** Forma de pago de la factura del proveedor. Un unico pago sin total recibe lo que falta del `importeTotal`. */
    public function pago(
        string $formaPago,
        int|float|string|null $total = null,
        ?int $plazo = null,
        ?string $unidadTiempo = null
    ): static {
        $this->doc()->pagos[] = self::nuevoPago($formaPago, $total, $plazo, $unidadTiempo);
        return $this;
    }

    protected function codDoc(): string
    {
        return '07';
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
