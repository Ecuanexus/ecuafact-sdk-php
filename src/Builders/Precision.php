<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Builders;

use Ecuafact\Sdk\EcuafactConfigurationException;

/**
 * Precision de los calculos de un constructor de comprobantes.
 *
 * - `escalaImportes`: decimales de los importes calculados (subtotales, impuestos, totales). Default 2,
 *   que es lo que admite el API.
 * - `escalaCantidades`: decimales maximos de `cantidad` y `precioUnitario`. Default 6. Solo se usa en
 *   `validar()`: el constructor nunca redondea lo que tu asignas.
 * - `redondeo`: modo de redondeo. Default `ModoRedondeo::HALF_UP`, el del API.
 */
final class Precision
{
    public function __construct(
        public readonly int $escalaImportes = 2,
        public readonly int $escalaCantidades = 6,
        public readonly ModoRedondeo $redondeo = ModoRedondeo::HALF_UP,
    ) {
        if ($escalaImportes < 0 || $escalaImportes > 12) {
            throw new EcuafactConfigurationException('escalaImportes debe estar entre 0 y 12.');
        }
        if ($escalaCantidades < 0 || $escalaCantidades > 12) {
            throw new EcuafactConfigurationException('escalaCantidades debe estar entre 0 y 12.');
        }
    }
}
