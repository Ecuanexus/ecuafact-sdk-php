<?php

declare(strict_types=1);

namespace Ecuafact\Sdk;

/**
 * Filtros del listado de comprobantes emitidos.
 *
 * `desde` y `hasta` son fechas de calendario de Ecuador con formato `Y-m-d`. El API las interpreta
 * en hora de Ecuador (UTC-5). Un string se envia tal cual; un \DateTimeInterface se formatea como
 * `Y-m-d` en su propia zona horaria (la fecha de calendario que ves en ese objeto).
 */
final class ListadoRequest
{
    /** Fecha de calendario `Y-m-d`. */
    public ?string $desde = null;
    /** Fecha de calendario `Y-m-d`. */
    public ?string $hasta = null;
    public ?string $codDoc = null;
    public ?string $buscar = null;
    public ?int $pagina = null;
    public ?int $tamanoPagina = null;

    /** Acepta `Y-m-d` o una fecha (\DateTimeInterface, se formatea como `Y-m-d`). */
    public function desde(\DateTimeInterface|string|null $value): self
    {
        $this->desde = self::fecha($value);
        return $this;
    }

    /** Acepta `Y-m-d` o una fecha (\DateTimeInterface, se formatea como `Y-m-d`). */
    public function hasta(\DateTimeInterface|string|null $value): self
    {
        $this->hasta = self::fecha($value);
        return $this;
    }

    private static function fecha(\DateTimeInterface|string|null $value): ?string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
    }

    public function codDoc(?string $value): self
    {
        $this->codDoc = $value;
        return $this;
    }

    public function buscar(?string $value): self
    {
        $this->buscar = $value;
        return $this;
    }

    public function pagina(?int $value): self
    {
        $this->pagina = $value;
        return $this;
    }

    public function tamanoPagina(?int $value): self
    {
        $this->tamanoPagina = $value;
        return $this;
    }
}
