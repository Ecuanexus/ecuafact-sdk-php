<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Support;

use Ecuafact\Sdk\Builders\ModoRedondeo;
use Ecuafact\Sdk\EcuafactSdkException;

/**
 * Aritmetica decimal exacta sobre strings (nunca usa float para calcular).
 *
 * Usa la extension bcmath si esta disponible; si no, una implementacion propia en base 10.
 * El redondeo siempre es propio (bcmath no redondea en PHP 8.2/8.3).
 *
 * @internal Lo usan los constructores de comprobantes; no forma parte del API publico.
 */
final class DecimalMath
{
    private const PATTERN = '/^([+-]?)(\d*)(?:\.(\d*))?$/D';

    private static ?bool $bcmath = null;

    /** Fuerza (true/false) o restablece (null) el uso de bcmath. Solo para pruebas. */
    public static function usarBcmath(?bool $usar): void
    {
        self::$bcmath = $usar === null ? null : ($usar && extension_loaded('bcmath'));
    }

    public static function usaBcmath(): bool
    {
        return self::$bcmath ?? extension_loaded('bcmath');
    }

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

    public static function sumar(string $a, string $b): string
    {
        if (self::usaBcmath()) {
            return self::canon(bcadd($a, $b, max(self::escala($a), self::escala($b))));
        }
        [$na, $da, $sa] = self::parse($a);
        [$nb, $db, $sb] = self::parse($b);
        $scale = max($sa, $sb);
        $da .= str_repeat('0', $scale - $sa);
        $db .= str_repeat('0', $scale - $sb);
        if ($na === $nb) {
            return self::format($na, self::addMag($da, $db), $scale);
        }
        $cmp = self::cmpMag($da, $db);
        if ($cmp === 0) {
            return self::format(false, '0', $scale);
        }
        return $cmp > 0
            ? self::format($na, self::subMag($da, $db), $scale)
            : self::format($nb, self::subMag($db, $da), $scale);
    }

    public static function restar(string $a, string $b): string
    {
        return self::sumar($a, self::negar($b));
    }

    public static function multiplicar(string $a, string $b): string
    {
        if (self::usaBcmath()) {
            return self::canon(bcmul($a, $b, self::escala($a) + self::escala($b)));
        }
        [$na, $da, $sa] = self::parse($a);
        [$nb, $db, $sb] = self::parse($b);
        return self::format($na !== $nb, self::mulMag($da, $db), $sa + $sb);
    }

    /** Divide entre 100 (exacto: solo desplaza el punto decimal). */
    public static function porcentaje(string $base, string $tarifa): string
    {
        [$neg, $digits, $scale] = self::parse(self::multiplicar($base, $tarifa));
        return self::format($neg, $digits, $scale + 2);
    }

    public static function comparar(string $a, string $b): int
    {
        [$neg, $digits] = self::parse(self::restar($a, $b));
        if (trim($digits, '0') === '') {
            return 0;
        }
        return $neg ? -1 : 1;
    }

    public static function iguales(string $a, string $b): bool
    {
        return self::comparar($a, $b) === 0;
    }

    /** Cantidad de decimales significativos (sin ceros a la derecha). */
    public static function decimales(string $a): int
    {
        $parts = explode('.', $a, 2);
        return isset($parts[1]) ? strlen(rtrim($parts[1], '0')) : 0;
    }

    /** Redondea a `$escala` decimales y devuelve exactamente esa cantidad de decimales. */
    public static function redondear(string $a, int $escala, ModoRedondeo $modo = ModoRedondeo::HALF_UP): string
    {
        [$neg, $digits, $scale] = self::parse($a);
        if ($scale <= $escala) {
            return self::format($neg, $digits . str_repeat('0', $escala - $scale), $escala);
        }
        $drop = $scale - $escala;
        if (strlen($digits) <= $drop) {
            $digits = str_repeat('0', $drop - strlen($digits) + 1) . $digits;
        }
        $kept = substr($digits, 0, strlen($digits) - $drop);
        $dropped = substr($digits, strlen($digits) - $drop);
        $first = (int) $dropped[0];
        $restNonZero = trim(substr($dropped, 1), '0') !== '';
        $anyNonZero = $first !== 0 || $restNonZero;
        $lastKept = (int) substr($kept, -1);
        $away = match ($modo) {
            ModoRedondeo::HALF_UP => $first >= 5,
            ModoRedondeo::HALF_DOWN => $first > 5 || ($first === 5 && $restNonZero),
            ModoRedondeo::HALF_EVEN => $first > 5 || ($first === 5 && ($restNonZero || $lastKept % 2 === 1)),
            ModoRedondeo::UP => $anyNonZero,
            ModoRedondeo::DOWN => false,
            ModoRedondeo::CEILING => $anyNonZero && !$neg,
            ModoRedondeo::FLOOR => $anyNonZero && $neg,
        };
        if ($away) {
            $kept = self::addMag($kept, '1');
        }
        return self::format($neg, $kept, $escala);
    }

    private static function negar(string $a): string
    {
        $a = trim($a);
        if (str_starts_with($a, '-')) {
            return substr($a, 1);
        }
        return '-' . ltrim($a, '+');
    }

    private static function escala(string $a): int
    {
        $parts = explode('.', $a, 2);
        return isset($parts[1]) ? strlen($parts[1]) : 0;
    }

    private static function canon(string $a): string
    {
        [$neg, $digits, $scale] = self::parse($a);
        return self::format($neg, $digits, $scale);
    }

    /** @return array{0: bool, 1: string, 2: int} signo negativo, digitos sin punto, escala */
    private static function parse(string $value): array
    {
        if (preg_match(self::PATTERN, trim($value), $m) !== 1 || ($m[2] === '' && ($m[3] ?? '') === '')) {
            throw new EcuafactSdkException('Numero decimal no valido: ' . $value);
        }
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

    private static function cmpMag(string $a, string $b): int
    {
        $a = ltrim($a, '0');
        $b = ltrim($b, '0');
        if (strlen($a) !== strlen($b)) {
            return strlen($a) <=> strlen($b);
        }
        return strcmp($a, $b) <=> 0;
    }

    private static function addMag(string $a, string $b): string
    {
        $len = max(strlen($a), strlen($b));
        $a = str_pad($a, $len, '0', STR_PAD_LEFT);
        $b = str_pad($b, $len, '0', STR_PAD_LEFT);
        $carry = 0;
        $out = '';
        for ($i = $len - 1; $i >= 0; $i--) {
            $sum = (int) $a[$i] + (int) $b[$i] + $carry;
            $out = ($sum % 10) . $out;
            $carry = intdiv($sum, 10);
        }
        return $carry > 0 ? $carry . $out : $out;
    }

    /** a - b con a >= b. */
    private static function subMag(string $a, string $b): string
    {
        $len = strlen($a);
        $b = str_pad($b, $len, '0', STR_PAD_LEFT);
        $borrow = 0;
        $out = '';
        for ($i = $len - 1; $i >= 0; $i--) {
            $diff = (int) $a[$i] - (int) $b[$i] - $borrow;
            if ($diff < 0) {
                $diff += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $out = $diff . $out;
        }
        $out = ltrim($out, '0');
        return $out === '' ? '0' : $out;
    }

    private static function mulMag(string $a, string $b): string
    {
        $a = ltrim($a, '0');
        $b = ltrim($b, '0');
        if ($a === '' || $b === '') {
            return '0';
        }
        $la = strlen($a);
        $lb = strlen($b);
        $res = array_fill(0, $la + $lb, 0);
        for ($i = $la - 1; $i >= 0; $i--) {
            $da = (int) $a[$i];
            for ($j = $lb - 1; $j >= 0; $j--) {
                $res[$i + $j + 1] += $da * (int) $b[$j];
            }
        }
        for ($k = $la + $lb - 1; $k > 0; $k--) {
            $res[$k - 1] += intdiv($res[$k], 10);
            $res[$k] %= 10;
        }
        $out = ltrim(implode('', $res), '0');
        return $out === '' ? '0' : $out;
    }
}
