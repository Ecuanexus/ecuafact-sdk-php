<?php

declare(strict_types=1);

namespace Ecuafact\Sdk\Tests;

use Ecuafact\Sdk\WebhookSignature;
use PHPUnit\Framework\TestCase;

final class WebhookSignatureTest extends TestCase
{
    private const SECRET = 'secreto-de-prueba';
    private const BODY = '{"eventType":"document.authorized","resourceId":"abc"}';
    private const STAMP = 1757341445;

    public function testSignFormato(): void
    {
        $header = WebhookSignature::sign(self::SECRET, self::STAMP, self::BODY);
        self::assertStringStartsWith('t=' . self::STAMP . ',v1=', $header);
        self::assertSame(64, strlen(substr($header, strpos($header, 'v1=') + 3)));
    }

    public function testVerifyValidaYVigente(): void
    {
        $header = WebhookSignature::sign(self::SECRET, self::STAMP, self::BODY);
        self::assertTrue(WebhookSignature::verify(self::SECRET, $header, self::BODY, self::STAMP, 300));
    }

    public function testVerifyRechazaCuerpoAlterado(): void
    {
        $header = WebhookSignature::sign(self::SECRET, self::STAMP, self::BODY);
        self::assertFalse(WebhookSignature::verify(self::SECRET, $header, self::BODY . ' ', self::STAMP, 300));
    }

    public function testVerifyRechazaExpirada(): void
    {
        $header = WebhookSignature::sign(self::SECRET, self::STAMP, self::BODY);
        self::assertFalse(WebhookSignature::verify(self::SECRET, $header, self::BODY, self::STAMP + 600, 300));
    }

    public function testVerifyRechazaSecretoYCabecera(): void
    {
        $header = WebhookSignature::sign(self::SECRET, self::STAMP, self::BODY);
        self::assertFalse(WebhookSignature::verify('otro', $header, self::BODY, self::STAMP, 300));
        self::assertFalse(WebhookSignature::verify(self::SECRET, 'basura', self::BODY, self::STAMP, 300));
    }
}
