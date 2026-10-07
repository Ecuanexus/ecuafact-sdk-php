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
    /** Tolerancia por defecto entre `t` y la hora actual, en segundos. */
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    /** Mayor `t` aceptado (9999-12-31T23:59:59Z). */
    private const MAX_TIMESTAMP = 253402300799;

    /** Calcula el valor de la cabecera Ecuafact-Signature. */
    public static function sign(#[\SensitiveParameter] string $secret, int $unixSeconds, string $body): string
    {
        $timestamp = (string) $unixSeconds;
        return 't=' . $timestamp . ',v1=' . self::hmacHex($secret, $timestamp . '.' . $body);
    }

    /**
     * Verifica un encabezado Ecuafact-Signature contra el cuerpo crudo recibido.
     *
     * Acepta un secreto o una lista de secretos (rotacion: el actual y el anterior). Compara en tiempo
     * constante y evalua todas las firmas `v1=` de la cabecera. Nunca lanza: una cabecera malformada,
     * vencida o con firma distinta devuelve false.
     *
     * @param string|string[] $secret Secreto o lista de secretos del destino.
     * @param string $body Cuerpo crudo tal como llego (bytes), sin decodificar ni reformatear.
     */
    public static function verify(
        #[\SensitiveParameter] string|array $secret,
        string $header,
        string $body,
        ?int $now = null,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS
    ): bool {
        try {
            $secrets = self::secrets($secret);
            if ($secrets === [] || $toleranceSeconds < 0) {
                return false;
            }
            $parsed = self::parseHeader($header);
            if ($parsed === null) {
                return false;
            }
            [$timestamp, $signatures] = $parsed;
            $current = $now ?? time();
            if (abs($current - $timestamp) > $toleranceSeconds) {
                return false;
            }
            $canonical = $timestamp . '.' . $body;
            $valid = false;
            foreach ($secrets as $item) {
                $expected = self::hmacHex($item, $canonical);
                foreach ($signatures as $signature) {
                    // Sin cortocircuito: se comparan todas las combinaciones.
                    $valid = hash_equals($expected, $signature) || $valid;
                }
            }
            return $valid;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Verifica la firma y devuelve el evento tipado.
     *
     * @param string|string[] $secrets Secreto o lista de secretos del destino.
     * @throws EcuafactWebhookException Si la firma no es valida, esta vencida o el cuerpo no es un evento.
     */
    public static function construirEvento(
        #[\SensitiveParameter] string|array $secrets,
        string $header,
        string $body,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        ?int $now = null
    ): WebhookEvent {
        return WebhookEvent::construir($secrets, $header, $body, $toleranceSeconds, $now);
    }

    /**
     * @param string|string[] $secret
     * @return string[]
     */
    private static function secrets(#[\SensitiveParameter] string|array $secret): array
    {
        $list = is_array($secret) ? $secret : [$secret];
        $out = [];
        foreach ($list as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }
        return $out;
    }

    /** @return array{0: int, 1: string[]}|null */
    private static function parseHeader(string $header): ?array
    {
        if ($header === '' || strlen($header) > 8192) {
            return null;
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $raw) {
            $item = trim($raw);
            $index = strpos($item, '=');
            if ($index === false || $index === 0) {
                continue;
            }
            $name = substr($item, 0, $index);
            $value = substr($item, $index + 1);
            if ($name === 't') {
                if ($timestamp !== null) {
                    return null;
                }
                if (preg_match('/^[0-9]{1,12}$/D', $value) !== 1) {
                    return null;
                }
                $timestamp = (int) $value;
                if ($timestamp <= 0 || $timestamp > self::MAX_TIMESTAMP) {
                    return null;
                }
            } elseif ($name === 'v1') {
                $value = strtolower($value);
                if (preg_match('/^[0-9a-f]{64}$/D', $value) === 1) {
                    $signatures[] = $value;
                }
            }
        }
        if ($timestamp === null || $signatures === []) {
            return null;
        }
        return [$timestamp, $signatures];
    }

    private static function hmacHex(#[\SensitiveParameter] string $secret, string $canonical): string
    {
        return hash_hmac('sha256', $canonical, $secret);
    }
}
