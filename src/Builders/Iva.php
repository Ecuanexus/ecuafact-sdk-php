<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Builders;

/**
 * Tarifas de IVA frecuentes del catalogo `GET /v1/catalogos/tarifas-iva`. El valor del caso es el
 * `codigoPorcentaje`. Para otra tarifa usa `Tarifa::iva($codigoPorcentaje, $tarifa)`.
 */
enum Iva: string
{
    /** IVA 0 % (`codigoPorcentaje` 0). */
    case TARIFA_0 = '0';
    /** IVA 5 % (`codigoPorcentaje` 5). */
    case TARIFA_5 = '5';
    /** IVA 8 % (`codigoPorcentaje` 8). */
    case TARIFA_8 = '8';
    /** IVA 15 % (`codigoPorcentaje` 4). */
    case TARIFA_15 = '4';
    /** No objeto de IVA (`codigoPorcentaje` 6, tarifa 0). */
    case NO_OBJETO = '6';
    /** Exento de IVA (`codigoPorcentaje` 7, tarifa 0). */
    case EXENTO = '7';

    /** Porcentaje de la tarifa (`15`, no `0.15`). */
    public function porcentaje(): int
    {
        return match ($this) {
            self::TARIFA_5 => 5,
            self::TARIFA_8 => 8,
            self::TARIFA_15 => 15,
            self::TARIFA_0, self::NO_OBJETO, self::EXENTO => 0,
        };
    }

    public function tarifa(): Tarifa
    {
        return Tarifa::iva($this->value, $this->porcentaje());
    }
}
