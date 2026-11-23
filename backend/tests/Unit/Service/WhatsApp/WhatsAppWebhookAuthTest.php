<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\WhatsApp;

use App\Service\WhatsApp\WhatsAppWebhookAuth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WhatsAppWebhookAuthTest extends TestCase
{
    private const SECRET = 'meta-app-secret';
    private const BODY = '{"entry":[{"id":"1","changes":[]}]}';

    public function testAcceptsTheSignatureMetaSends(): void
    {
        $auth = new WhatsAppWebhookAuth(self::SECRET, 'verify');
        $signature = 'sha256='.hash_hmac('sha256', self::BODY, self::SECRET);

        self::assertTrue($auth->acceptsSignature(self::BODY, $signature));
        self::assertTrue($auth->acceptsSignature(self::BODY, 'sha256='.strtoupper(hash_hmac('sha256', self::BODY, self::SECRET))));
    }

    /** @return array<string, array{0: ?string}> */
    public static function refusedSignatures(): array
    {
        return [
            'no header' => [null],
            'empty header' => [''],
            'no prefix' => [hash_hmac('sha256', self::BODY, self::SECRET)],
            'sha1 prefix' => ['sha1='.hash_hmac('sha1', self::BODY, self::SECRET)],
            'another key' => ['sha256='.hash_hmac('sha256', self::BODY, 'guessed')],
            'the verify token as key' => ['sha256='.hash_hmac('sha256', self::BODY, 'verify')],
            'an empty key' => ['sha256='.hash_hmac('sha256', self::BODY, '')],
            'another body' => ['sha256='.hash_hmac('sha256', self::BODY.' ', self::SECRET)],
            'prefix only' => ['sha256='],
        ];
    }

    #[DataProvider('refusedSignatures')]
    public function testRefusesEverythingElse(?string $signature): void
    {
        self::assertFalse((new WhatsAppWebhookAuth(self::SECRET, 'verify'))->acceptsSignature(self::BODY, $signature));
    }

    public function testWithoutAnAppSecretNothingIsAccepted(): void
    {
        $auth = new WhatsAppWebhookAuth('', 'verify');

        self::assertFalse($auth->acceptsSignature(self::BODY, 'sha256='.hash_hmac('sha256', self::BODY, '')));
        self::assertFalse($auth->acceptsSignature(self::BODY, null));
    }

    public function testTheHandshakeNeedsTheConfiguredVerifyToken(): void
    {
        $auth = new WhatsAppWebhookAuth(self::SECRET, 'verify');

        self::assertTrue($auth->acceptsVerifyToken('verify'));
        self::assertFalse($auth->acceptsVerifyToken('Verify'));
        self::assertFalse($auth->acceptsVerifyToken(''));
        self::assertFalse($auth->acceptsVerifyToken(null));
    }

    public function testAnEmptyConfiguredVerifyTokenMatchesNothing(): void
    {
        $auth = new WhatsAppWebhookAuth(self::SECRET, '');

        self::assertFalse($auth->acceptsVerifyToken(''));
        self::assertFalse($auth->acceptsVerifyToken(null));
    }
}
