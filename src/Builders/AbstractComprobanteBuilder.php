<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Builders;

use Ecuafact\Sdk\Contracts\CampoAdicional;
use Ecuafact\Sdk\Contracts\InfoTributaria;
use Ecuafact\Sdk\Contracts\Pago;
use Ecuafact\Sdk\Contracts\SolicitudComprobante;
use Ecuafact\Sdk\EcuafactSdkException;
use Ecuafact\Sdk\Support\DecimalMath;

/**
 * Base de los constructores de comprobantes. Produce la solicitud del tipo de comprobante
 * (`FacturaRequest`, `NotaCreditoRequest`...), con solo las propiedades de su esquema.
 *
 * Reglas:
 * - Lo que tu asignas se envia tal cual.
 * - No calcula ni completa nada: subtotales, impuestos, totales y valores por defecto los das tu y se envian
 *   tal cual. Los importes conservan su precision sin perdida.
 * - Las reglas de negocio (campos, formatos, catalogos, topes, importes) viven solo en el API: usa
 *   `EcuafactClient::validar()` o emite y propaga el 422.
 */
abstract class AbstractComprobanteBuilder
{
    /**
     * Solicitud del tipo del constructor. Todas llevan `origenReferencia`, `referenciaExterna`, `infoTributaria`,
     * `info` (con `dirEstablecimiento`, `telefono` y `correo`) e `infoAdicional`.
     */
    protected SolicitudComprobante $comprobante;

    /** @param SolicitudComprobante $solicitud Solicitud nueva del tipo, con su `info` ya creado. */
    protected function __construct(SolicitudComprobante $solicitud, string $ruc, string $estab, string $ptoEmi)
    {
        $this->comprobante = $solicitud;
        $this->comprobante->infoTributaria = new InfoTributaria();
        $this->comprobante->infoTributaria->ruc = $ruc;
        $this->comprobante->infoTributaria->estab = $estab;
        $this->comprobante->infoTributaria->ptoEmi = $ptoEmi;
    }

    /** `origenReferencia` (nombre de tu sistema) y `referenciaExterna` (tu numero interno). */
    public function referencias(string $origen, string $externa): static
    {
        $this->comprobante->origenReferencia = $origen;
        $this->comprobante->referenciaExterna = $externa;
        return $this;
    }

    /** Secuencial propio (hasta 9 digitos). Sin el, la plataforma asigna el siguiente de la serie. */
    public function secuencial(string $secuencial): static
    {
        $this->comprobante->infoTributaria->secuencial = $secuencial;
        return $this;
    }

    public function dirEstablecimiento(string $direccion): static
    {
        $this->comprobante->info->dirEstablecimiento = $direccion;
        return $this;
    }

    /** Correo de la contraparte al que se envia el comprobante. */
    public function correo(string $correo): static
    {
        $this->comprobante->info->correo = $correo;
        return $this;
    }

    public function telefono(string $telefono): static
    {
        $this->comprobante->info->telefono = $telefono;
        return $this;
    }

    public function infoAdicional(string $nombre, string $valor): static
    {
        $campo = new CampoAdicional();
        $campo->nombre = $nombre;
        $campo->valor = $valor;
        $this->comprobante->infoAdicional[] = $campo;
        return $this;
    }

    /**
     * Acceso directo al comprobante para asignar cualquier campo del contrato; se envia tal cual.
     *
     * @param callable(SolicitudComprobante): void $cambios
     */
    public function configurar(callable $cambios): static
    {
        $cambios($this->comprobante);
        return $this;
    }

    /** Devuelve una copia independiente del comprobante, con los valores tal como los asignaste. */
    public function construir(): SolicitudComprobante
    {
        return self::copiar($this->comprobante);
    }

    protected static function fechaTexto(string|\DateTimeInterface $fecha): string
    {
        return $fecha instanceof \DateTimeInterface ? $fecha->format('d/m/Y') : $fecha;
    }

    /** Convierte int y float a string decimal (float con 15 digitos significativos); un string se valida y queda igual. */
    protected static function importe(int|float|string $valor, string $campo): string
    {
        if (!is_string($valor)) {
            return DecimalMath::normalizar($valor, $campo);
        }
        if (!DecimalMath::esNumero($valor)) {
            throw new EcuafactSdkException($campo . ' debe ser un numero decimal (por ejemplo 11.50).');
        }
        return $valor;
    }

    protected static function nuevoPago(
        string $formaPago,
        int|float|string|null $total,
        ?int $plazo,
        ?string $unidadTiempo
    ): Pago {
        $pago = new Pago();
        $pago->formaPago = $formaPago;
        $pago->total = $total === null ? null : self::importe($total, 'total');
        $pago->plazo = $plazo === null ? null : (string) $plazo;
        $pago->unidadTiempo = $unidadTiempo;
        return $pago;
    }

    private static function copiar(SolicitudComprobante $comprobante): SolicitudComprobante
    {
        /** @var SolicitudComprobante $copia */
        $copia = unserialize(serialize($comprobante));
        return $copia;
    }
}
