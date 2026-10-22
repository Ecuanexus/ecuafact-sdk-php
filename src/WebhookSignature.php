<?php

declare(strict_types=1);

namespace Ecuafact\Sdk;

/**
 * Firma y verificacion del webhook saliente de Ecuafact. La cabecera tiene la
 * forma {@code Ecuafact-Signature: t=<unix>,v1=<hex>} sobre
 * {@code timestamp + "." + body} con HMAC-SHA256.
 *
 * Cada entrega trae tambien las cabeceras de Standard Webhooks ({@code webhook-id},
 * {@code webhook-timestamp} y {@code webhook-signature}), firmadas con el mismo secreto
 * decodificado de Base64. Basta con verificar una de las dos.
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
     * Calcula el valor de la cabecera webhook-signature de Standard Webhooks (`v1,<base64>`).
     *
     * @param string $secret Secreto del destino, con o sin prefijo `whsec_` (Base64).
     * @throws \InvalidArgumentException Si el secreto no es Base64 valido.
     */
    public static function firmarStandardWebhooks(
        #[\SensitiveParameter] string $secret,
        string $webhookId,
        int $unixSeconds,
        string $body
    ): string {
        $key = self::standardKey($secret);
        if ($key === null) {
            throw new \InvalidArgumentException('El secreto no es Base64 valido.');
        }
        return 'v1,' . self::hmacBase64($key, $webhookId . '.' . $unixSeconds . '.' . $body);
    }

    /**
     * Verifica una entrega con las cabeceras de Standard Webhooks contra el cuerpo crudo recibido.
     *
     * La clave del HMAC es el secreto decodificado de Base64 (con o sin prefijo `whsec_`). Acepta un
     * secreto o una lista de secretos (rotacion), compara en tiempo constante, acepta la entrega si
     * coincide cualquiera de las firmas `v1,` de la lista e ignora las demas. Nunca lanza: cabeceras
     * vacias o malformadas, secreto que no es Base64, marca vencida o firma distinta devuelven false.
     *
     * @param string|string[] $secret Secreto o lista de secretos del destino.
     * @param string $webhookId Valor de la cabecera webhook-id.
     * @param string $webhookTimestamp Valor de la cabecera webhook-timestamp (segundos Unix).
     * @param string $webhookSignature Valor de la cabecera webhook-signature (lista separada por espacios).
     * @param string $body Cuerpo crudo tal como llego (bytes), sin decodificar ni reformatear.
     */
    public static function verificarStandardWebhooks(
        #[\SensitiveParameter] string|array $secret,
        string $webhookId,
        string $webhookTimestamp,
        string $webhookSignature,
        string $body,
        ?int $now = null,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS
    ): bool {
        try {
            $secrets = self::secrets($secret);
            if ($secrets === [] || $toleranceSeconds < 0) {
                return false;
            }
            $webhookId = trim($webhookId);
            $webhookTimestamp = trim($webhookTimestamp);
            if ($webhookId === '' || strlen($webhookId) > 1024 || strlen($webhookSignature) > 8192) {
                return false;
            }
            if (preg_match('/^[0-9]{1,12}$/D', $webhookTimestamp) !== 1) {
                return false;
            }
            $timestamp = (int) $webhookTimestamp;
            if ($timestamp <= 0 || $timestamp > self::MAX_TIMESTAMP) {
                return false;
            }
            $current = $now ?? time();
            if (abs($current - $timestamp) > $toleranceSeconds) {
                return false;
            }
            $signatures = [];
            foreach (preg_split('/\s+/', trim($webhookSignature)) ?: [] as $item) {
                if (str_starts_with($item, 'v1,') && strlen($item) > 3) {
                    $signatures[] = substr($item, 3);
                }
            }
            if ($signatures === []) {
                return false;
            }
            $canonical = $webhookId . '.' . $webhookTimestamp . '.' . $body;
            $valid = false;
            foreach ($secrets as $item) {
                $key = self::standardKey($item);
                if ($key === null) {
                    continue;
                }
                $expected = self::hmacBase64($key, $canonical);
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
     * Verifica una entrega con cualquiera de las dos firmas a partir de las cabeceras de la peticion.
     *
     * Verifica `Ecuafact-Signature` si viene y, si no viene o no es valida, las cabeceras de Standard
     * Webhooks. Los nombres de cabecera no distinguen mayusculas; acepta valores de texto o listas
     * (como `getHeaders()` de PSR-7) y tambien las claves de `$_SERVER` (`HTTP_WEBHOOK_ID`). Nunca lanza.
     *
     * @param string|string[] $secret Secreto o lista de secretos del destino.
     * @param array<string, string|string[]> $headers Cabeceras de la peticion.
     * @param string $body Cuerpo crudo tal como llego (bytes).
     */
    public static function verificarCabeceras(
        #[\SensitiveParameter] string|array $secret,
        array $headers,
        string $body,
        ?int $now = null,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS
    ): bool {
        try {
            $map = self::normalizeHeaders($headers);
            $ecuafact = $map['ecuafact-signature'] ?? null;
            if ($ecuafact !== null && self::verify($secret, $ecuafact, $body, $now, $toleranceSeconds)) {
                return true;
            }
            $id = $map['webhook-id'] ?? null;
            $timestamp = $map['webhook-timestamp'] ?? null;
            $signature = $map['webhook-signature'] ?? null;
            if ($id === null || $timestamp === null || $signature === null) {
                return false;
            }
            return self::verificarStandardWebhooks($secret, $id, $timestamp, $signature, $body, $now, $toleranceSeconds);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Verifica cualquiera de las dos firmas a partir de las cabeceras y devuelve el evento tipado.
     *
     * @param string|string[] $secrets Secreto o lista de secretos del destino.
     * @param array<string, string|string[]> $headers Cabeceras de la peticion.
     * @throws EcuafactWebhookException Si ninguna firma es valida o vigente, o el cuerpo no es un evento.
     */
    public static function construirEventoDesdeCabeceras(
        #[\SensitiveParameter] string|array $secrets,
        array $headers,
        string $body,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
        ?int $now = null
    ): WebhookEvent {
        return WebhookEvent::construirDesdeCabeceras($secrets, $headers, $body, $toleranceSeconds, $now);
    }

    /**
     * @param array<array-key, mixed> $headers
     * @return array<string, string>
     */
    private static function normalizeHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name)) {
                continue;
            }
            $key = str_replace('_', '-', strtolower(trim($name)));
            if (str_starts_with($key, 'http-')) {
                $key = substr($key, 5);
            }
            if (is_array($value)) {
                $parts = array_values(array_filter($value, 'is_string'));
                if ($parts === []) {
                    continue;
                }
                // webhook-signature es una lista separada por espacios; las demas toman el primer valor.
                $value = $key === 'webhook-signature' ? implode(' ', $parts) : $parts[0];
            }
            if (is_string($value)) {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /** Clave de Standard Webhooks: el secreto sin `whsec_` decodificado de Base64, o null si no es valido. */
    private static function standardKey(#[\SensitiveParameter] string $secret): ?string
    {
        $text = trim($secret);
        if (str_starts_with($text, 'whsec_')) {
            $text = substr($text, 6);
        }
        if ($text === '') {
            return null;
        }
        $key = base64_decode($text, true);
        return $key === false || $key === '' ? null : $key;
    }

    private static function hmacBase64(#[\SensitiveParameter] string $key, string $canonical): string
    {
        return base64_encode(hash_hmac('sha256', $canonical, $key, true));
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
