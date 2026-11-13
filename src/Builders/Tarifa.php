<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Builders;

use Ecuafact\Sdk\EcuafactSdkException;
use Ecuafact\Sdk\Support\DecimalMath;

/**
 * Impuesto de una linea para los constructores: `codigo` (`2` IVA, `3` ICE), `codigoPorcentaje`
 * del catalogo y `tarifa` en porcentaje (`15`, no `0.15`).
 *
 * El SDK no cruza `codigoPorcentaje` con `tarifa`: envia la pareja tal como la defines.
 */
final class Tarifa
{
    public function __construct(
        public readonly string $codigo,
        public readonly string $codigoPorcentaje,
        public readonly int|float|string $tarifa,
    ) {
        if (trim($codigo) === '' || trim($codigoPorcentaje) === '') {
            throw new EcuafactSdkException('codigo y codigoPorcentaje son obligatorios.');
        }
        DecimalMath::normalizar($tarifa, 'tarifa');
    }

    /** IVA con el `codigoPorcentaje` y la `tarifa` del catalogo (por ejemplo `'4'` y `15`). */
    public static function iva(string $codigoPorcentaje, int|float|string $tarifa): self
    {
        return new self('2', $codigoPorcentaje, $tarifa);
    }

    /** ICE porcentual con el `codigoPorcentaje` y la `tarifa` del catalogo de ICE. */
    public static function ice(string $codigoPorcentaje, int|float|string $tarifa): self
    {
        return new self('3', $codigoPorcentaje, $tarifa);
    }
}
