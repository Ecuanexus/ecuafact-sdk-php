<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Support;

use Ecuafact\Sdk\EcuafactSdkException;

/**
 * Serializa objetos del contrato a JSON omitiendo los valores nulos.
 *
 * Importes exactos: en una propiedad declarada `float|string`, un string decimal ('11.50') se emite
 * como numero JSON literal (11.50) sin pasar por float. Todo float se emite con 15 digitos
 * significativos, de modo que 0.1 + 0.2 viaja como 0.3 y no como 0.30000000000000004.
 */
final class Serialization
{
    private const DECIMAL = '/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/D';

    /** @var array<string, array<string, bool>> clase => (propiedad => es importe float|string) */
    private static array $amountProperties = [];

    public static function encode(mixed $value): string
    {
        $literals = [];
        $nonce = bin2hex(random_bytes(8));
        $tree = self::clean($value, $literals, $nonce);
        $json = json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new EcuafactSdkException('No fue posible serializar la solicitud.');
        }
        if ($literals !== []) {
            $json = strtr($json, $literals);
        }
        return $json;
    }

    /** Convierte un float a su representacion decimal JSON con 15 digitos significativos. */
    public static function formatFloat(float $value): string
    {
        if (!is_finite($value)) {
            throw new EcuafactSdkException('No fue posible serializar la solicitud: hay un numero no finito (NAN o INF).');
        }
        $text = sprintf('%.15G', $value);
        if (str_contains($text, 'E')) {
            $text = number_format($value, 6, '.', '');
        }
        if (str_contains($text, '.')) {
            $text = rtrim(rtrim($text, '0'), '.');
        }
        if ($text === '-0' || $text === '') {
            $text = '0';
        }
        return $text;
    }

    /** @param array<string, string> $literals */
    private static function clean(mixed $value, array &$literals, string $nonce): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if (is_float($value)) {
            return self::literal(self::formatFloat($value), $literals, $nonce);
        }
        if (is_object($value)) {
            $amounts = self::amountProperties($value);
            $out = [];
            foreach (get_object_vars($value) as $key => $item) {
                if ($item === null) {
                    continue;
                }
                if (is_string($item) && isset($amounts[$key])) {
                    $trimmed = trim($item);
                    if (preg_match(self::DECIMAL, $trimmed) !== 1) {
                        throw new EcuafactSdkException(
                            'El campo ' . $key . ' debe ser un numero decimal (por ejemplo 11.50).'
                        );
                    }
                    $out[$key] = self::literal($trimmed, $literals, $nonce);
                    continue;
                }
                $out[$key] = self::clean($item, $literals, $nonce);
            }
            return (object) $out;
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                if ($item === null) {
                    continue;
                }
                $out[$key] = self::clean($item, $literals, $nonce);
            }
            return $out;
        }
        return $value;
    }

    /** @param array<string, string> $literals */
    private static function literal(string $number, array &$literals, string $nonce): string
    {
        $token = '__ecuafact_num_' . $nonce . '_' . count($literals) . '__';
        $literals['"' . $token . '"'] = $number;
        return $token;
    }

    /** @return array<string, bool> */
    private static function amountProperties(object $value): array
    {
        $class = $value::class;
        if (isset(self::$amountProperties[$class])) {
            return self::$amountProperties[$class];
        }
        $result = [];
        if (!$value instanceof \stdClass) {
            foreach ((new \ReflectionClass($value))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                $type = $property->getType();
                if (!$type instanceof \ReflectionUnionType) {
                    continue;
                }
                $names = [];
                foreach ($type->getTypes() as $inner) {
                    if ($inner instanceof \ReflectionNamedType) {
                        $names[] = $inner->getName();
                    }
                }
                if (in_array('float', $names, true) && in_array('string', $names, true)) {
                    $result[$property->getName()] = true;
                }
            }
        }
        return self::$amountProperties[$class] = $result;
    }
}
