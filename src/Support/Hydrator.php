<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Support;

/** Hidrata las respuestas JSON (stdClass) en las clases del contrato. */
final class Hydrator
{
    public static function hydrate(string $class, mixed $data): mixed
    {
        if ($data === null || !is_object($data)) {
            return $data;
        }
        $reflection = new \ReflectionClass($class);
        $instance = $reflection->newInstanceWithoutConstructor();
        foreach (get_object_vars($data) as $key => $value) {
            if (!$reflection->hasProperty($key)) {
                continue;
            }
            $instance->{$key} = self::coerce($reflection->getProperty($key), $value);
        }
        return $instance;
    }

    private static function coerce(\ReflectionProperty $property, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        $element = self::listElement($property);
        if ($element !== null && is_array($value)) {
            return array_map(static fn ($item) => self::hydrate($element, $item), $value);
        }
        $type = $property->getType();
        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
            return self::hydrate($type->getName(), $value);
        }
        return $value;
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
