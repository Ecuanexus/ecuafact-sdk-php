<?php

declare(strict_types=1);

namespace Ecuafact\Sdk;

/**
 * Firma y verificacion del webhook saliente de Ecuafact. La cabecera tiene la
 * forma {@code Ecuafact-Signature: t=<unix>,v1=<hex>} sobre
 * {@code timestamp + "." + body} con HMAC-SHA256.
 */
final class WebhookSignature
{
    /** Calcula el valor de la cabecera Ecuafact-Signature. */
    public static function sign(string $secret, int $unixSeconds, string $body): string
    {
        $timestamp = (string) $unixSeconds;
        return 't=' . $timestamp . ',v1=' . self::hmacHex($secret, $timestamp . '.' . $body);
    }

    /** Verifica un encabezado Ecuafact-Signature contra el cuerpo crudo recibido. */
    public static function verify(
        string $secret,
        string $header,
        string $body,
        ?int $now = null,
        int $toleranceSeconds = 300
    ): bool {
        if ($secret === '' || $header === '') {
            return false;
        }
        $parts = [];
        foreach (explode(',', $header) as $raw) {
            $item = trim($raw);
            $index = strpos($item, '=');
            if ($index !== false && $index > 0) {
                $parts[substr($item, 0, $index)] = substr($item, $index + 1);
            }
        }
        if (!isset($parts['t'], $parts['v1']) || !ctype_digit($parts['t'])) {
            return false;
        }
        $timestamp = (int) $parts['t'];
        $current = $now ?? time();
        if (abs($current - $timestamp) > $toleranceSeconds) {
            return false;
        }
        $expected = self::hmacHex($secret, $timestamp . '.' . $body);
        return hash_equals($expected, strtolower($parts['v1']));
    }

    private static function hmacHex(string $secret, string $canonical): string
    {
        return hash_hmac('sha256', $canonical, $secret);
    }
}
