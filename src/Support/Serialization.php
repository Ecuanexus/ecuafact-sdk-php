<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Support;

use Ecuafact\Sdk\EcuafactSdkException;

/** Serializa objetos del contrato a JSON omitiendo los valores nulos. */
final class Serialization
{
    public static function encode(mixed $value): string
    {
        $json = json_encode(self::clean($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new EcuafactSdkException('No fue posible serializar la solicitud.');
        }
        return $json;
    }

    private static function clean(mixed $value): mixed
    {
        if (is_object($value)) {
            $out = [];
            foreach (get_object_vars($value) as $key => $item) {
                if ($item === null) {
                    continue;
                }
                $out[$key] = self::clean($item);
            }
            return (object) $out;
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                if ($item === null) {
                    continue;
                }
                $out[$key] = self::clean($item);
            }
            return $out;
        }
        return $value;
    }
}
