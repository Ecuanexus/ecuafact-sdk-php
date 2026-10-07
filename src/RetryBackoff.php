<?php

declare(strict_types=1);

namespace Ecuafact\Sdk;

/** Forma de crecer la espera entre reintentos. */
enum RetryBackoff: string
{
    /** base x intento (200 ms, 400 ms, 600 ms...). Es el default. */
    case Linear = 'linear';
    /** base x 2^(intento-1) (200 ms, 400 ms, 800 ms...). */
    case Exponential = 'exponential';
}
