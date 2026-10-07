<?php

declare(strict_types=1);

namespace Ecuafact\Sdk;

use Ecuafact\Sdk\Contracts\TipoEventoWebhook;

/**
 * Evento de webhook de Ecuafact ya verificado.
 *
 * Campos del cuerpo: `eventType`, `resourceId`, `occurredAtUtc` y, segun el evento, `idOperacion`,
 * `estado` (`authorized`, `rejected`, `failed`), `claveAcceso`, `motivo`, `codigoError` y `probe`
 * (`"true"` en el evento de prueba del portal).
 */
final class WebhookEvent
{
    /**
     * @param array<string, mixed> $datos Cuerpo completo decodificado (incluye campos nuevos).
     */
    public function __construct(
        public readonly TipoEventoWebhook $eventType,
        public readonly string $eventTypeRaw,
        public readonly ?string $resourceId,
        public readonly ?\DateTimeImmutable $occurredAtUtc,
        public readonly ?string $idOperacion,
        public readonly ?string $estado,
        public readonly ?string $claveAcceso,
        public readonly ?string $motivo,
        public readonly ?string $codigoError,
        public readonly bool $probe,
        public readonly array $datos = [],
    ) {
    }

    /**
     * Verifica la firma y parsea el evento en una sola llamada.
     *
     * @param string|string[] $secrets Secreto o lista de secretos del destino (rotacion).
     * @param string $body Cuerpo crudo tal como llego (bytes).
     * @throws EcuafactWebhookException Si la firma no es valida, esta vencida o el cuerpo no es un evento.
     */
    public static function construir(
        #[\SensitiveParameter] string|array $secrets,
        string $header,
        string $body,
        int $toleranceSeconds = WebhookSignature::DEFAULT_TOLERANCE_SECONDS,
        ?int $now = null
    ): self {
        if (!WebhookSignature::verify($secrets, $header, $body, $now, $toleranceSeconds)) {
            throw new EcuafactWebhookException('Firma del webhook invalida o vencida (cabecera Ecuafact-Signature).');
        }
        return self::desdeJson($body);
    }

    /**
     * Parsea el cuerpo sin verificar la firma. Usalo solo despues de verificar.
     *
     * @throws EcuafactWebhookException Si el cuerpo no es un objeto JSON.
     */
    public static function desdeJson(string $body): self
    {
        try {
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new EcuafactWebhookException('El cuerpo del webhook no es JSON valido.', 0, $exception);
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new EcuafactWebhookException('El cuerpo del webhook no es un objeto JSON.');
        }
        $raw = self::text($data['eventType'] ?? null) ?? '';
        $occurred = null;
        $occurredText = self::text($data['occurredAtUtc'] ?? null);
        if ($occurredText !== null) {
            $occurred = self::parseDate($occurredText);
        }
        $probe = $data['probe'] ?? null;
        return new self(
            TipoEventoWebhook::desdeValor($raw),
            $raw,
            self::text($data['resourceId'] ?? null),
            $occurred,
            self::text($data['idOperacion'] ?? null),
            self::text($data['estado'] ?? null),
            self::text($data['claveAcceso'] ?? null),
            self::text($data['motivo'] ?? null),
            self::text($data['codigoError'] ?? null),
            $probe === true || (is_string($probe) && strtolower(trim($probe)) === 'true'),
            $data,
        );
    }

    private static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return is_scalar($value) ? (string) $value : null;
    }

    private static function parseDate(string $value): ?\DateTimeImmutable
    {
        // .NET "o" emite 7 decimales; PHP admite 6.
        $normalized = preg_replace('/(\.\d{6})\d+/', '$1', trim($value)) ?? $value;
        try {
            $date = new \DateTimeImmutable($normalized, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
        return $date->setTimezone(new \DateTimeZone('UTC'));
    }
}
