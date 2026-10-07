<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Support;

use Ecuafact\Sdk\EcuafactSdkException;

/**
 * Hidrata JSON decodificado (stdClass o arreglo asociativo) en las clases del contrato.
 *
 * Convierte cada valor al tipo declarado de la propiedad: string numerico a float (o se conserva
 * como string en importes `float|string`), int y bool desde string, y null al valor por defecto
 * cuando la propiedad no admite null. Un valor que no se puede convertir lanza
 * {@see EcuafactSdkException}, nunca un TypeError.
 */
final class Hydrator
{
    private const DECIMAL = '/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/D';

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T|mixed
     */
    public static function hydrate(string $class, mixed $data): mixed
    {
        try {
            return self::hydrateObject($class, $data);
        } catch (EcuafactSdkException $exception) {
            throw $exception;
        } catch (\TypeError | \ValueError | \ReflectionException $exception) {
            throw new EcuafactSdkException('Datos no reconocidos al leer ' . self::shortName($class) . '.', 0, $exception);
        }
    }

    private static function hydrateObject(string $class, mixed $data): mixed
    {
        if (is_array($data) && ($data === [] || !array_is_list($data))) {
            $data = (object) $data;
        }
        if ($data === null || !is_object($data)) {
            return $data;
        }
        $reflection = new \ReflectionClass($class);
        $instance = $reflection->newInstanceWithoutConstructor();
        foreach (get_object_vars($data) as $key => $value) {
            $key = (string) $key;
            if (!$reflection->hasProperty($key)) {
                continue;
            }
            $property = $reflection->getProperty($key);
            if (!$property->isPublic() || $property->isStatic()) {
                continue;
            }
            $property->setValue($instance, self::coerce($class, $property, $value));
        }
        return $instance;
    }

    private static function coerce(string $class, \ReflectionProperty $property, mixed $value): mixed
    {
        $type = $property->getType();
        if ($value === null) {
            if ($type === null || $type->allowsNull()) {
                return null;
            }
            return $property->hasDefaultValue() ? $property->getDefaultValue() : self::fail($class, $property);
        }
        if ($type === null) {
            return $value;
        }
        $names = self::typeNames($type);
        if (in_array('mixed', $names, true)) {
            return $value;
        }

        // Clase anidada.
        foreach ($names as $name) {
            if (!self::isBuiltin($name)) {
                if (is_object($value) || (is_array($value) && ($value === [] || !array_is_list($value)))) {
                    return self::hydrateObject($name, $value);
                }
                return self::fail($class, $property);
            }
        }

        if (in_array('array', $names, true)) {
            if (!is_array($value)) {
                return self::fail($class, $property);
            }
            $element = self::listElement($property);
            if ($element === null) {
                return array_values($value);
            }
            $out = [];
            foreach ($value as $item) {
                $out[] = $item === null ? null : self::hydrateObject($element, $item);
            }
            return $out;
        }

        $hasFloat = in_array('float', $names, true);
        $hasString = in_array('string', $names, true);
        $hasInt = in_array('int', $names, true);
        $hasBool = in_array('bool', $names, true);

        // Importe float|string: el string decimal se conserva exacto.
        if ($hasFloat && $hasString) {
            if (is_int($value) || is_float($value)) {
                return (float) $value;
            }
            if (is_string($value) && preg_match(self::DECIMAL, trim($value)) === 1) {
                return trim($value);
            }
            return self::fail($class, $property);
        }
        if ($hasFloat) {
            if (is_int($value) || is_float($value)) {
                return (float) $value;
            }
            if (is_string($value) && is_numeric(trim($value))) {
                return (float) trim($value);
            }
            return self::fail($class, $property);
        }
        if ($hasInt) {
            if (is_int($value)) {
                return $value;
            }
            if (is_float($value) && floor($value) === $value && abs($value) <= PHP_INT_MAX) {
                return (int) $value;
            }
            if (is_string($value) && preg_match('/^-?\d+$/D', trim($value)) === 1) {
                return (int) trim($value);
            }
            return self::fail($class, $property);
        }
        if ($hasBool) {
            if (is_bool($value)) {
                return $value;
            }
            if ($value === 1 || $value === 0) {
                return $value === 1;
            }
            if (is_string($value)) {
                $normalized = strtolower(trim($value));
                if ($normalized === 'true' || $normalized === '1') {
                    return true;
                }
                if ($normalized === 'false' || $normalized === '0') {
                    return false;
                }
            }
            return self::fail($class, $property);
        }
        if ($hasString) {
            if (is_string($value)) {
                return $value;
            }
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }
            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
            return self::fail($class, $property);
        }
        return $value;
    }

    /** @return string[] */
    private static function typeNames(\ReflectionType $type): array
    {
        if ($type instanceof \ReflectionNamedType) {
            return [$type->getName()];
        }
        if ($type instanceof \ReflectionUnionType) {
            $names = [];
            foreach ($type->getTypes() as $inner) {
                if ($inner instanceof \ReflectionNamedType) {
                    $names[] = $inner->getName();
                }
            }
            return $names;
        }
        return ['mixed'];
    }

    private static function isBuiltin(string $name): bool
    {
        return in_array($name, ['int', 'float', 'string', 'bool', 'array', 'null', 'mixed', 'false', 'true', 'iterable', 'object'], true);
    }

    private static function fail(string $class, \ReflectionProperty $property): never
    {
        throw new EcuafactSdkException(
            'Datos no reconocidos: el campo ' . self::shortName($class) . '.' . $property->getName()
            . ' no admite el valor recibido.'
        );
    }

    private static function shortName(string $class): string
    {
        $position = strrpos($class, '\\');
        return $position === false ? $class : substr($class, $position + 1);
    }

    private static function listElement(\ReflectionProperty $property): ?string
    {
        $doc = $property->getDocComment();
        if ($doc === false) {
            return null;
        }
        if (preg_match('/@var\s+([^\s]+)\[\]/', $doc, $matches) === 1) {
            $name = ltrim($matches[1], '\\');
            if (!str_contains($name, '\\')) {
                $name = 'Ecuafact\\Sdk\\Contracts\\' . $name;
            }
            return class_exists($name) ? $name : null;
        }
        return null;
    }
}
