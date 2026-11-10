<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Tests;

use Ecuafact\Sdk\Contracts\EstadoOperacion;
use Ecuafact\Sdk\Contracts\TipoEventoWebhook;
use Ecuafact\Sdk\EcuafactSdkException;
use Ecuafact\Sdk\EcuafactWebhookException;
use Ecuafact\Sdk\WebhookEvent;
use Ecuafact\Sdk\WebhookSignature;
use PHPUnit\Framework\TestCase;

final class WebhookEventTest extends TestCase
{
    private const SECRET = 'secreto-actual';
    private const OLD_SECRET = 'secreto-anterior';
    private const STAMP = 1757341445;

    private static function cuerpo(string $tipo, array $extra = []): string
    {
        return (string) json_encode(array_merge([
            'idOperacion' => '3f2b8c1e-7a4d-4e2b-9c11-5d6f7a8b9c0d',
            'eventType' => $tipo,
            'resourceId' => '3f2b8c1e-7a4d-4e2b-9c11-5d6f7a8b9c0d',
            'occurredAtUtc' => '2026-10-07T15:04:05.1234567Z',
        ], $extra));
    }

    public function testVerificaCuerpoEnBytes(): void
    {
        $body = "{\"eventType\":\"document.authorized\",\"x\":\"\xC3\xB1\xFF\x00\"}";
        $header = WebhookSignature::sign(self::SECRET, self::STAMP, $body);
        self::assertTrue(WebhookSignature::verify(self::SECRET, $header, $body, self::STAMP));
        self::assertFalse(WebhookSignature::verify(self::SECRET, $header, substr($body, 0, -1), self::STAMP));
    }

    public function testVerificaConVariosSecretos(): void
    {
        $body = self::cuerpo('document.authorized');
        $header = WebhookSignature::sign(self::OLD_SECRET, self::STAMP, $body);
        self::assertTrue(WebhookSignature::verify([self::SECRET, self::OLD_SECRET], $header, $body, self::STAMP));
        self::assertFalse(WebhookSignature::verify([self::SECRET, 'otro'], $header, $body, self::STAMP));
        self::assertFalse(WebhookSignature::verify([], $header, $body, self::STAMP));
        self::assertFalse(WebhookSignature::verify(['', 5], $header, $body, self::STAMP));
    }

    public function testToleranciaPorDefectoEs300(): void
    {
        $body = self::cuerpo('document.failed');
        $header = WebhookSignature::sign(self::SECRET, time() - 200, $body);
        self::assertTrue(WebhookSignature::verify(self::SECRET, $header, $body));
        $header = WebhookSignature::sign(self::SECRET, time() - 400, $body);
        self::assertFalse(WebhookSignature::verify(self::SECRET, $header, $body));
    }

    public function testEvaluaTodasLasFirmasV1(): void
    {
        $body = self::cuerpo('document.rejected');
        $valida = substr(WebhookSignature::sign(self::SECRET, self::STAMP, $body), strlen('t=' . self::STAMP . ',v1='));
        $header = 't=' . self::STAMP . ',v1=' . str_repeat('0', 64) . ',v1=' . strtoupper($valida);
        self::assertTrue(WebhookSignature::verify(self::SECRET, $header, $body, self::STAMP));
    }

    public function testCabeceraMalformadaDevuelveFalseSinExcepcion(): void
    {
        $body = self::cuerpo('document.authorized');
        $firma = substr(WebhookSignature::sign(self::SECRET, self::STAMP, $body), strlen('t=' . self::STAMP . ',v1='));
        $cabeceras = [
            '',
            ',,,',
            '=',
            't=',
            'v1=' . $firma,
            't=' . self::STAMP,
            't=abc,v1=' . $firma,
            't=-1,v1=' . $firma,
            't=+1757341445,v1=' . $firma,
            't=0,v1=' . $firma,
            't=99999999999999999999999,v1=' . $firma,
            't=１７５７,v1=' . $firma,
            't=' . self::STAMP . ',v1=zz',
            't=' . self::STAMP . ',v1=' . substr($firma, 1),
            't=' . self::STAMP . ',t=' . self::STAMP . ',v1=' . $firma,
            "t=17573\x0041445,v1=" . $firma,
            str_repeat('t=1,', 5000),
        ];
        foreach ($cabeceras as $cabecera) {
            self::assertFalse(
                WebhookSignature::verify(self::SECRET, $cabecera, $body, self::STAMP),
                'Cabecera: ' . substr($cabecera, 0, 60)
            );
        }
        self::assertFalse(WebhookSignature::verify(self::SECRET, 't=' . self::STAMP . ',v1=' . $firma, $body, self::STAMP, -1));
    }

    public function testConstruirEventoTipado(): void
    {
        $casos = [
            ['document.authorized', TipoEventoWebhook::DocumentoAutorizado, ['estado' => 'autorizado', 'codEstado' => 2001, 'errores' => [], 'claveAcceso' => 'CLAVE', 'tipoDocumento' => '01', 'fhAutorizacion' => '2026-11-09T10:15:03-05:00']],
            ['document.rejected', TipoEventoWebhook::DocumentoRechazado, ['estado' => 'rechazado', 'claveAcceso' => 'CLAVE', 'tipoDocumento' => '04', 'codEstado' => 3001, 'msgError' => 'El SRI rechazo el comprobante.', 'errores' => ['CLAVE ACCESO REGISTRADA']]],
            ['document.failed', TipoEventoWebhook::DocumentoFallido, ['estado' => 'rechazo_configuracion', 'codEstado' => 4002, 'msgError' => 'La firma electronica no es valida.', 'errores' => []]],
            ['document.failed', TipoEventoWebhook::DocumentoFallido, ['estado' => 'error', 'codEstado' => 5007, 'msgError' => 'No se pudo confirmar el resultado.']],
            ['operation.requires_attention', TipoEventoWebhook::OperacionRequiereAtencion, ['probe' => 'true']],
            ['document.archived', TipoEventoWebhook::Desconocido, []],
        ];
        foreach ($casos as [$tipo, $esperado, $extra]) {
            $body = self::cuerpo($tipo, $extra);
            $header = WebhookSignature::sign(self::SECRET, self::STAMP, $body);
            $evento = WebhookSignature::construirEvento([self::OLD_SECRET, self::SECRET], $header, $body, 300, self::STAMP);
            self::assertSame($esperado, $evento->eventType);
            self::assertSame($tipo, $evento->eventTypeRaw);
            self::assertSame('3f2b8c1e-7a4d-4e2b-9c11-5d6f7a8b9c0d', $evento->resourceId);
            self::assertSame('3f2b8c1e-7a4d-4e2b-9c11-5d6f7a8b9c0d', $evento->idOperacion);
            self::assertSame('2026-10-07T15:04:05.123456+00:00', $evento->occurredAtUtc?->format('Y-m-d\TH:i:s.uP'));
            self::assertSame($extra['estado'] ?? null, $evento->estado);
            self::assertSame($extra['claveAcceso'] ?? null, $evento->claveAcceso);
            self::assertSame(EstadoOperacion::desdeValor($extra['estado'] ?? null), $evento->estadoOperacion());
            self::assertSame($extra['codEstado'] ?? null, $evento->codEstado);
            self::assertSame($extra['errores'] ?? [], $evento->errores);
            self::assertSame($extra['fhAutorizacion'] ?? null, $evento->fhAutorizacion);
            self::assertSame($extra['msgError'] ?? null, $evento->msgError);
            self::assertSame($extra['tipoDocumento'] ?? null, $evento->tipoDocumento);
            self::assertSame(isset($extra['probe']), $evento->probe);
        }
    }

    public function testWebhookEventConstruirEquivalente(): void
    {
        $body = self::cuerpo('document.authorized');
        $header = WebhookSignature::sign(self::SECRET, self::STAMP, $body);
        $evento = WebhookEvent::construir(self::SECRET, $header, $body, 300, self::STAMP);
        self::assertSame(TipoEventoWebhook::DocumentoAutorizado, $evento->eventType);
        self::assertSame('document.authorized', $evento->cuerpo['eventType']);
    }

    public function testFirmaInvalidaLanzaWebhookException(): void
    {
        $body = self::cuerpo('document.authorized');
        $header = WebhookSignature::sign('otro', self::STAMP, $body);
        try {
            WebhookSignature::construirEvento(self::SECRET, $header, $body, 300, self::STAMP);
            self::fail('Se esperaba EcuafactWebhookException.');
        } catch (EcuafactWebhookException $error) {
            self::assertInstanceOf(EcuafactSdkException::class, $error);
            self::assertStringNotContainsString(self::SECRET, $error->getMessage());
            self::assertStringNotContainsString(self::SECRET, $error->getTraceAsString());
        }
    }

    public function testCuerpoNoJsonConFirmaValidaLanzaWebhookException(): void
    {
        foreach (['no-json', '[1,2]', '"texto"'] as $body) {
            $header = WebhookSignature::sign(self::SECRET, self::STAMP, $body);
            try {
                WebhookEvent::construir(self::SECRET, $header, $body, 300, self::STAMP);
                self::fail('Se esperaba EcuafactWebhookException.');
            } catch (EcuafactWebhookException) {
                self::assertTrue(true);
            }
        }
    }
}
