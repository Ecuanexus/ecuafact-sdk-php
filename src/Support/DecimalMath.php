<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Support;

use Ecuafact\Sdk\EcuafactSdkException;

/**
 * Validacion y normalizacion de importes decimales (sin perdida de precision y sin ninguna operacion
 * aritmetica). No suma, no multiplica ni redondea: los importes se envian tal como los das.
 *
 * @internal Lo usan los constructores de comprobantes; no forma parte del API publico.
 */
final class DecimalMath
{
    private const PATTERN = '/^([+-]?)(\d*)(?:\.(\d*))?$/D';

    /** True si el valor es un numero decimal valido (int, float finito o string decimal). */
    public static function esNumero(mixed $value): bool
    {
        if (is_int($value)) {
            return true;
        }
        if (is_float($value)) {
            return is_finite($value);
        }
        if (!is_string($value)) {
            return false;
        }
        $value = trim($value);
        return $value !== '' && preg_match(self::PATTERN, $value, $m) === 1 && ($m[2] !== '' || ($m[3] ?? '') !== '');
    }

    /**
     * Convierte int, float o string decimal a un string decimal canonico (sin exponente).
     * Un float se toma con 15 digitos significativos (0.1 + 0.2 -> "0.3").
     */
    public static function normalizar(mixed $value, string $campo = 'valor'): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new EcuafactSdkException($campo . ' no es un numero finito.');
            }
            return Serialization::formatFloat($value);
        }
        if (is_string($value) && self::esNumero($value)) {
            [$neg, $digits, $scale] = self::parse(trim($value));
            return self::format($neg, $digits, $scale);
        }
        throw new EcuafactSdkException($campo . ' debe ser un numero decimal (por ejemplo 11.50).');
    }

    /** @return array{0: bool, 1: string, 2: int} signo negativo, digitos sin punto, escala */
    private static function parse(string $value): array
    {
        preg_match(self::PATTERN, $value, $m);
        $int = $m[2];
        $frac = $m[3] ?? '';
        $digits = ltrim($int . $frac, '0');
        if ($digits === '') {
            $digits = '0';
        }
        return [$m[1] === '-' && $digits !== '0', $digits, strlen($frac)];
    }

    private static function format(bool $neg, string $digits, int $scale): string
    {
        $digits = ltrim($digits, '0');
        if (strlen($digits) <= $scale) {
            $digits = str_repeat('0', $scale - strlen($digits) + 1) . $digits;
        }
        $int = substr($digits, 0, strlen($digits) - $scale);
        $frac = $scale > 0 ? substr($digits, -$scale) : '';
        $int = ltrim($int, '0');
        if ($int === '') {
            $int = '0';
        }
        $isZero = trim($int . $frac, '0') === '';
        return ($neg && !$isZero ? '-' : '') . $int . ($scale > 0 ? '.' . $frac : '');
    }
}
