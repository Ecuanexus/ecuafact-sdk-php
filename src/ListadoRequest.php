<?php

declare(strict_types=1);

namespace Ecuafact\Sdk;

/** Filtros del listado de comprobantes emitidos o recibidos. */
final class ListadoRequest
{
    public ?string $desde = null;
    public ?string $hasta = null;
    public ?string $codDoc = null;
    public ?string $buscar = null;
    public ?int $pagina = null;
    public ?int $tamanoPagina = null;

    public function desde(?string $value): self
    {
        $this->desde = $value;
        return $this;
    }

    public function hasta(?string $value): self
    {
        $this->hasta = $value;
        return $this;
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
