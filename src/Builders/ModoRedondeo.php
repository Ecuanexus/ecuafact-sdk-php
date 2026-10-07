<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Builders;

/**
 * Modo de redondeo de los constructores de comprobantes. El API usa `HALF_UP`
 * (el 5 se aleja del cero: `0.455` -> `0.46`, `-0.455` -> `-0.46`).
 */
enum ModoRedondeo: string
{
    /** El 5 se aleja del cero (AwayFromZero / HALF_UP). Es el que usa el API. */
    case HALF_UP = 'half_up';
    /** El 5 va al par mas cercano (redondeo bancario). */
    case HALF_EVEN = 'half_even';
    /** El 5 se acerca al cero. */
    case HALF_DOWN = 'half_down';
    /** Siempre se aleja del cero. */
    case UP = 'up';
    /** Siempre se acerca al cero (trunca). */
    case DOWN = 'down';
    /** Hacia +infinito. */
    case CEILING = 'ceiling';
    /** Hacia -infinito. */
    case FLOOR = 'floor';
}
