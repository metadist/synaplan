<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Security;

use App\Service\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class EncryptionServiceTest extends TestCase
{
    private const SECRET = 'unit-test-application-secret';

    public function testANewValueIsWrittenInTheAuthenticatedFormatAndReadBack(): void
    {
        $service = $this->service();

        $stored = $service->encrypt('imap-password');

        self::assertStringStartsWith('v2:', $stored);
        self::assertFalse($service->isLegacy($stored));
        self::assertSame('imap-password', $service->decrypt($stored));
        self::assertNotSame($stored, $service->encrypt('imap-password'), 'every value gets its own nonce');
    }

    public function testAValueFromAnEarlierReleaseStaysReadable(): void
    {
        $service = $this->service();
        $legacy = $this->legacyCiphertext('sk-old-provider-key');

        self::assertTrue($service->isLegacy($legacy));
        self::assertSame('sk-old-provider-key', $service->decrypt($legacy));
    }

    public function testAnAlteredValueDoesNotOpen(): void
    {
        $service = $this->service();
        $raw = base64_decode(substr($service->encrypt('secret'), 3), true);
        self::assertIsString($raw);
        $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] ^ "\x01";

        $this->expectException(\RuntimeException::class);

        $service->decrypt('v2:'.base64_encode($raw));
    }

    public function testAValueBoundToOnePlaceDoesNotOpenInAnother(): void
    {
        $service = $this->service();
        $stored = $service->encrypt('secret', 'provider_keys|openai|0');

        self::assertSame('secret', $service->decrypt($stored, 'provider_keys|openai|0'));

        $this->expectException(\RuntimeException::class);

        $service->decrypt($stored, 'provider_keys|openai|7');
    }

    public function testAnotherSecretDoesNotOpenTheValue(): void
    {
        $stored = $this->service()->encrypt('secret');

        $this->expectException(\RuntimeException::class);

        (new EncryptionService('another-secret', new NullLogger()))->decrypt($stored);
    }

    public function testEmptyStaysEmpty(): void
    {
        $service = $this->service();

        self::assertSame('', $service->encrypt(''));
        self::assertSame('', $service->decrypt(''));
        self::assertFalse($service->isLegacy(''));
    }

    private function service(): EncryptionService
    {
        return new EncryptionService(self::SECRET, new NullLogger());
    }

    /** The format releases before v2 wrote: base64(IV . AES-256-CBC). */
    private function legacyCiphertext(string $plaintext): string
    {
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($plaintext, 'AES-256-CBC', hash('sha256', self::SECRET, true), \OPENSSL_RAW_DATA, $iv);
        self::assertIsString($encrypted);

        return base64_encode($iv.$encrypted);
    }
}
