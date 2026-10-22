<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Tests;

use Ecuafact\Sdk\Contracts\TipoEventoWebhook;
use Ecuafact\Sdk\EcuafactWebhookException;
use Ecuafact\Sdk\WebhookEvent;
use Ecuafact\Sdk\WebhookSignature;
use PHPUnit\Framework\TestCase;

/** Vectores 1 y 3 de la guia de verificacion de firma (webhooks-firma.md). */
final class StandardWebhooksTest extends TestCase
{
    private const SECRETO = 'c2VjcmV0by1kZS1wcnVlYmFzLW5vLXVzYXItMzJieXQ=';
    private const ID = '9b2e4f7a-1c3d-4e5f-8a6b-7c8d9e0f1a2b';
    private const MARCA = 1791385446;
    private const FIRMA = 'v1,6YgtHWzoIeuArJTPh8Oq8lpg//3ldAAsy1IRzD5eiG0=';
    private const CABECERA_ECUAFACT = 't=1791385446,v1=a1ae78d763238168f09290d33a7647b3b3c685ff96ef3b60be203e453d772568';
    private const CUERPO = '{"idOperacion":"3f1c8c1e-6f2a-4b7e-9c3d-2a1b0c9d8e7f","estado":"authorized","claveAcceso":"0710202601012345678900110010010000001231234567811","eventType":"document.authorized","resourceId":"3f1c8c1e-6f2a-4b7e-9c3d-2a1b0c9d8e7f","occurredAtUtc":"2026-10-07T15:04:05.1234567Z"}';

    private static function verificar(string|array $secreto, string $firma, string $cuerpo = self::CUERPO, int $ahora = self::MARCA): bool
    {
        return WebhookSignature::verificarStandardWebhooks($secreto, self::ID, (string) self::MARCA, $firma, $cuerpo, $ahora);
    }

    public function testFirmarReproduceElVector3(): void
    {
        self::assertSame(self::FIRMA, WebhookSignature::firmarStandardWebhooks(self::SECRETO, self::ID, self::MARCA, self::CUERPO));
        self::assertSame(self::FIRMA, WebhookSignature::firmarStandardWebhooks('whsec_' . self::SECRETO, self::ID, self::MARCA, self::CUERPO));
        $this->expectException(\InvalidArgumentException::class);
        WebhookSignature::firmarStandardWebhooks('no-es-base64!', self::ID, self::MARCA, self::CUERPO);
    }

    public function testValidaConYSinPrefijoWhsec(): void
    {
        self::assertTrue(self::verificar(self::SECRETO, self::FIRMA));
        self::assertTrue(self::verificar('whsec_' . self::SECRETO, self::FIRMA));
    }

    public function testListaConVariasFirmasDondeUnaCoincide(): void
    {
        $otra = 'v1,' . base64_encode(str_repeat("\x01", 32));
        self::assertTrue(self::verificar(self::SECRETO, $otra . ' v2,abc ' . self::FIRMA));
        self::assertTrue(self::verificar(self::SECRETO, self::FIRMA . '  ' . $otra));
        self::assertFalse(self::verificar(self::SECRETO, $otra . ' v2,' . substr(self::FIRMA, 3)));
    }

    public function testRotacionConListaDeSecretos(): void
    {
        $anterior = base64_encode(str_repeat('k', 32));
        self::assertTrue(self::verificar([$anterior, self::SECRETO], self::FIRMA));
        self::assertTrue(self::verificar(['no-base64!', 'whsec_' . self::SECRETO], self::FIRMA));
        self::assertFalse(self::verificar([$anterior], self::FIRMA));
        self::assertFalse(self::verificar([], self::FIRMA));
    }

    public function testRechazaCuerpoAlterado(): void
    {
        self::assertFalse(self::verificar(self::SECRETO, self::FIRMA, self::CUERPO . ' '));
        self::assertFalse(self::verificar(self::SECRETO, self::FIRMA, str_replace('authorized', 'rejected', self::CUERPO)));
    }

    public function testRechazaSecretoIncorrecto(): void
    {
        self::assertFalse(self::verificar(base64_encode(str_repeat('x', 32)), self::FIRMA));
        // Usar el texto del secreto sin decodificar da la firma v1,PVfUF7A2... de la guia.
        $sinDecodificar = 'v1,' . base64_encode(hash_hmac('sha256', self::ID . '.' . self::MARCA . '.' . self::CUERPO, self::SECRETO, true));
        self::assertStringStartsWith('v1,PVfUF7A2', $sinDecodificar);
        self::assertFalse(self::verificar(self::SECRETO, $sinDecodificar));
    }

    public function testRechazaFueraDeTolerancia(): void
    {
        self::assertTrue(self::verificar(self::SECRETO, self::FIRMA, self::CUERPO, self::MARCA + 300));
        self::assertFalse(self::verificar(self::SECRETO, self::FIRMA, self::CUERPO, self::MARCA + 301));
        self::assertFalse(self::verificar(self::SECRETO, self::FIRMA, self::CUERPO, self::MARCA - 301));
        self::assertTrue(WebhookSignature::verificarStandardWebhooks(self::SECRETO, self::ID, (string) self::MARCA, self::FIRMA, self::CUERPO, self::MARCA + 900, 1000));
    }

    public function testSecretoNoBase64DevuelveFalse(): void
    {
        self::assertFalse(self::verificar('no-es-base64!', self::FIRMA));
        self::assertFalse(self::verificar('whsec_', self::FIRMA));
        self::assertFalse(self::verificar('whsec_%%%', self::FIRMA));
    }

    public function testRechazaCabecerasMalformadas(): void
    {
        self::assertFalse(WebhookSignature::verificarStandardWebhooks(self::SECRETO, '', (string) self::MARCA, self::FIRMA, self::CUERPO, self::MARCA));
        self::assertFalse(WebhookSignature::verificarStandardWebhooks(self::SECRETO, self::ID, 'abc', self::FIRMA, self::CUERPO, self::MARCA));
        self::assertFalse(WebhookSignature::verificarStandardWebhooks(self::SECRETO, self::ID, '-1', self::FIRMA, self::CUERPO, self::MARCA));
        self::assertFalse(self::verificar(self::SECRETO, ''));
        self::assertFalse(self::verificar(self::SECRETO, 'v1,'));
        self::assertFalse(self::verificar(self::SECRETO, substr(self::FIRMA, 3)));
    }

    public function testToleranciaPorDefectoUsaElReloj(): void
    {
        $marca = time() - 200;
        $firma = WebhookSignature::firmarStandardWebhooks(self::SECRETO, self::ID, $marca, self::CUERPO);
        self::assertTrue(WebhookSignature::verificarStandardWebhooks(self::SECRETO, self::ID, (string) $marca, $firma, self::CUERPO));
        $marca = time() - 400;
        $firma = WebhookSignature::firmarStandardWebhooks(self::SECRETO, self::ID, $marca, self::CUERPO);
        self::assertFalse(WebhookSignature::verificarStandardWebhooks(self::SECRETO, self::ID, (string) $marca, $firma, self::CUERPO));
    }

    public function testCualquieraDeLasDosSoloEcuafactSignature(): void
    {
        $cabeceras = ['Ecuafact-Signature' => self::CABECERA_ECUAFACT];
        self::assertTrue(WebhookSignature::verificarCabeceras(self::SECRETO, $cabeceras, self::CUERPO, self::MARCA));
        self::assertFalse(WebhookSignature::verificarCabeceras(self::SECRETO, $cabeceras, self::CUERPO . ' ', self::MARCA));
    }

    public function testCualquieraDeLasDosSoloStandardWebhooks(): void
    {
        $cabeceras = [
            'webhook-id' => self::ID,
            'Webhook-Timestamp' => (string) self::MARCA,
            'WEBHOOK-SIGNATURE' => self::FIRMA,
        ];
        self::assertTrue(WebhookSignature::verificarCabeceras(self::SECRETO, $cabeceras, self::CUERPO, self::MARCA));
        self::assertTrue(WebhookSignature::verificarCabeceras('whsec_' . self::SECRETO, $cabeceras, self::CUERPO, self::MARCA));
        self::assertFalse(WebhookSignature::verificarCabeceras(self::SECRETO, $cabeceras, self::CUERPO . ' ', self::MARCA));
        self::assertFalse(WebhookSignature::verificarCabeceras(self::SECRETO, $cabeceras, self::CUERPO, self::MARCA + 301));
        unset($cabeceras['WEBHOOK-SIGNATURE']);
        self::assertFalse(WebhookSignature::verificarCabeceras(self::SECRETO, $cabeceras, self::CUERPO, self::MARCA));
    }

    public function testCualquieraDeLasDosCaeAlEstandarSiEcuafactNoEsValida(): void
    {
        $cabeceras = [
            'Ecuafact-Signature' => 't=1791385446,v1=' . str_repeat('0', 64),
            'webhook-id' => [self::ID],
            'webhook-timestamp' => [(string) self::MARCA],
            'webhook-signature' => ['v1,' . base64_encode(str_repeat("\x02", 32)), self::FIRMA],
        ];
        self::assertTrue(WebhookSignature::verificarCabeceras(self::SECRETO, $cabeceras, self::CUERPO, self::MARCA));
    }

    public function testCualquieraDeLasDosConClavesDeServer(): void
    {
        $server = [
            'HTTP_WEBHOOK_ID' => self::ID,
            'HTTP_WEBHOOK_TIMESTAMP' => (string) self::MARCA,
            'HTTP_WEBHOOK_SIGNATURE' => self::FIRMA,
            'REQUEST_METHOD' => 'POST',
        ];
        self::assertTrue(WebhookSignature::verificarCabeceras(self::SECRETO, $server, self::CUERPO, self::MARCA));
        self::assertFalse(WebhookSignature::verificarCabeceras(self::SECRETO, [], self::CUERPO, self::MARCA));
    }

    public function testConstruyeEventoSoloConStandardWebhooks(): void
    {
        $cabeceras = ['webhook-id' => self::ID, 'webhook-timestamp' => (string) self::MARCA, 'webhook-signature' => self::FIRMA];
        $evento = WebhookSignature::construirEventoDesdeCabeceras(self::SECRETO, $cabeceras, self::CUERPO, now: self::MARCA);
        self::assertSame(TipoEventoWebhook::DocumentoAutorizado, $evento->eventType);
        self::assertSame('0710202601012345678900110010010000001231234567811', $evento->claveAcceso);

        $evento = WebhookEvent::construirDesdeCabeceras(self::SECRETO, ['Ecuafact-Signature' => self::CABECERA_ECUAFACT], self::CUERPO, 300, self::MARCA);
        self::assertSame('authorized', $evento->estado);

        $this->expectException(EcuafactWebhookException::class);
        WebhookEvent::construirDesdeCabeceras(self::SECRETO, $cabeceras, self::CUERPO, 300, self::MARCA + 301);
    }
}
