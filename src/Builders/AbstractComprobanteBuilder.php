<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Builders;

use Ecuafact\Sdk\Contracts\CampoAdicional;
use Ecuafact\Sdk\Contracts\ComprobanteRequest;
use Ecuafact\Sdk\Contracts\InfoDocumento;
use Ecuafact\Sdk\Contracts\InfoTributaria;
use Ecuafact\Sdk\Contracts\Pago;
use Ecuafact\Sdk\EcuafactSdkException;
use Ecuafact\Sdk\Support\DecimalMath;

/**
 * Base de los constructores de comprobantes. Produce el `ComprobanteRequest` del contrato.
 *
 * Reglas:
 * - Lo que tu asignas se respeta: el constructor nunca sobrescribe un valor con contenido.
 * - `calcularTotales()` es opcional: hace que `construir()` rellene solo los valores vacios
 *   (subtotales, impuestos, totales y el pago sin total) con las reglas del API.
 * - `validar()` devuelve la lista de inconsistencias; no lanza ni bloquea el envio.
 */
abstract class AbstractComprobanteBuilder
{
    protected ComprobanteRequest $comprobante;
    private bool $calcular = false;

    protected function __construct(
        string $codDoc,
        string $ruc,
        string $estab,
        string $ptoEmi,
        protected Precision $precision,
    ) {
        $this->comprobante = new ComprobanteRequest();
        $this->comprobante->infoTributaria = new InfoTributaria();
        $this->comprobante->infoTributaria->ruc = $ruc;
        $this->comprobante->infoTributaria->codDoc = $codDoc;
        $this->comprobante->infoTributaria->estab = $estab;
        $this->comprobante->infoTributaria->ptoEmi = $ptoEmi;
        $this->comprobante->info = new InfoDocumento();
    }

    /** Crea el constructor sobre una copia de un comprobante existente (por ejemplo para calcular o validar). */
    public static function sobre(ComprobanteRequest $comprobante, ?Precision $precision = null): static
    {
        $builder = (new \ReflectionClass(static::class))->newInstanceWithoutConstructor();
        $builder->comprobante = self::copiar($comprobante);
        $builder->comprobante->info ??= new InfoDocumento();
        $builder->precision = $precision ?? new Precision();
        return $builder;
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
     * Acceso directo al comprobante para asignar cualquier campo del contrato. Lo que asignes aqui
     * tambien se respeta en el calculo.
     *
     * @param callable(ComprobanteRequest): void $cambios
     */
    public function configurar(callable $cambios): static
    {
        $cambios($this->comprobante);
        return $this;
    }

    /** Pide que `construir()` y `validar()` rellenen los valores vacios. Opcional. */
    public function calcularTotales(bool $calcular = true): static
    {
        $this->calcular = $calcular;
        return $this;
    }

    public function conPrecision(Precision $precision): static
    {
        $this->precision = $precision;
        return $this;
    }

    public function precision(): Precision
    {
        return $this->precision;
    }

    /** Devuelve una copia del comprobante (calculado si pediste `calcularTotales()`). */
    public function construir(): ComprobanteRequest
    {
        $copia = self::copiar($this->comprobante);
        if ($this->calcular) {
            (new CalculadoraComprobante($this->precision))->calcular($copia);
        }
        return $copia;
    }

    /**
     * Inconsistencias de lo que devolveria `construir()`: totales que no cuadran, campos obligatorios
     * del tipo, `codDoc` y formatos. Lista vacia si no encontro ninguna. No lanza.
     *
     * @return Inconsistencia[]
     */
    public function validar(): array
    {
        try {
            $comprobante = $this->construir();
        } catch (EcuafactSdkException $e) {
            return [new Inconsistencia('comprobante', $e->getMessage())];
        }
        return (new CalculadoraComprobante($this->precision))->validar($comprobante, $this->codDoc());
    }

    abstract protected function codDoc(): string;

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

    private static function copiar(ComprobanteRequest $comprobante): ComprobanteRequest
    {
        /** @var ComprobanteRequest $copia */
        $copia = unserialize(serialize($comprobante));
        return $copia;
    }
}
