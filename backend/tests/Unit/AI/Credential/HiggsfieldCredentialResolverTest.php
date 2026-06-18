<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Credential;

use App\AI\Credential\HiggsfieldCredentialResolver;
use App\Repository\ConfigRepository;
use App\Service\EncryptionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for {@see HiggsfieldCredentialResolver}.
 *
 * EncryptionService is final (cannot be mocked), so we use a real instance with
 * a fixed test secret — the AES round-trip is deterministic. ConfigRepository
 * is mocked to simulate stored ciphertext.
 */
class HiggsfieldCredentialResolverTest extends TestCase
{
    private EncryptionService $encryption;
    private ConfigRepository&MockObject $configRepository;

    protected function setUp(): void
    {
        $this->encryption = new EncryptionService('unit-test-secret', new NullLogger());
        $this->configRepository = $this->createMock(ConfigRepository::class);
    }

    private function resolver(string $platformKey = '', string $platformSecret = ''): HiggsfieldCredentialResolver
    {
        return new HiggsfieldCredentialResolver(
            $this->configRepository,
            $this->encryption,
            new NullLogger(),
            $platformKey,
            $platformSecret,
        );
    }

    public function testResolvePrefersUserCredentialsOverPlatform(): void
    {
        $keyCipher = $this->encryption->encrypt('user-key');
        $secretCipher = $this->encryption->encrypt('user-secret');

        $this->configRepository->method('getValue')->willReturnCallback(
            fn (int $ownerId, string $group, string $setting): ?string => match ($setting) {
                'api_key' => $keyCipher,
                'api_secret' => $secretCipher,
                default => null,
            }
        );

        $resolved = $this->resolver('plat-key', 'plat-secret')->resolve(5);

        self::assertNotNull($resolved);
        self::assertSame('user-key', $resolved['api_key']);
        self::assertSame('user-secret', $resolved['api_secret']);
        self::assertSame('user', $resolved['source']);
    }

    public function testResolveFallsBackToPlatformWhenNoUserCredentials(): void
    {
        $this->configRepository->method('getValue')->willReturn(null);

        $resolved = $this->resolver('plat-key', 'plat-secret')->resolve(5);

        self::assertNotNull($resolved);
        self::assertSame('plat-key', $resolved['api_key']);
        self::assertSame('plat-secret', $resolved['api_secret']);
        self::assertSame('platform', $resolved['source']);
    }

    public function testResolveReturnsNullWhenNothingConfigured(): void
    {
        $this->configRepository->method('getValue')->willReturn(null);

        self::assertNull($this->resolver()->resolve(5));
        self::assertNull($this->resolver()->resolve(null));
    }

    public function testHalfStoredPairFallsBackToPlatform(): void
    {
        // api_key present but api_secret missing → not a usable user pair.
        $keyCipher = $this->encryption->encrypt('user-key');
        $this->configRepository->method('getValue')->willReturnCallback(
            fn (int $ownerId, string $group, string $setting): ?string => 'api_key' === $setting ? $keyCipher : null
        );

        $resolved = $this->resolver('plat-key', 'plat-secret')->resolve(5);

        self::assertNotNull($resolved);
        self::assertSame('platform', $resolved['source']);
    }

    public function testGuestUserIdNeverReadsUserCredentials(): void
    {
        // userId 0 / null must not be treated as an owner.
        $this->configRepository->expects(self::never())->method('getValue');

        $resolved = $this->resolver('plat-key', 'plat-secret')->resolve(0);

        self::assertNotNull($resolved);
        self::assertSame('platform', $resolved['source']);
    }

    public function testDecryptFailureFallsBackToPlatform(): void
    {
        // Stored value is not valid ciphertext (e.g. APP_SECRET rotated).
        $this->configRepository->method('getValue')->willReturn('not-valid-ciphertext!!');

        $resolved = $this->resolver('plat-key', 'plat-secret')->resolve(5);

        self::assertNotNull($resolved);
        self::assertSame('platform', $resolved['source']);
    }

    public function testHasPlatformCredentials(): void
    {
        self::assertTrue($this->resolver('k', 's')->hasPlatformCredentials());
        self::assertFalse($this->resolver('', '')->hasPlatformCredentials());
        self::assertFalse($this->resolver('k', '')->hasPlatformCredentials());
    }

    public function testSaveUserCredentialsEncryptsBeforeStoring(): void
    {
        $stored = [];
        $this->configRepository->method('setValue')->willReturnCallback(
            function (int $ownerId, string $group, string $setting, string $value) use (&$stored) {
                $stored[$setting] = $value;

                return new \App\Entity\Config();
            }
        );

        $this->resolver()->saveUserCredentials(7, 'my-key', 'my-secret');

        self::assertArrayHasKey('api_key', $stored);
        self::assertArrayHasKey('api_secret', $stored);
        // Stored values must be ciphertext (not the plaintext), and must decrypt back.
        self::assertNotSame('my-key', $stored['api_key']);
        self::assertSame('my-key', $this->encryption->decrypt($stored['api_key']));
        self::assertSame('my-secret', $this->encryption->decrypt($stored['api_secret']));
    }

    public function testMaskedUserApiKeyHidesMostOfTheKey(): void
    {
        $keyCipher = $this->encryption->encrypt('abcd1234567890');
        $secretCipher = $this->encryption->encrypt('secretvalue');
        $this->configRepository->method('getValue')->willReturnCallback(
            fn (int $ownerId, string $group, string $setting): ?string => match ($setting) {
                'api_key' => $keyCipher,
                'api_secret' => $secretCipher,
                default => null,
            }
        );

        $masked = $this->resolver()->maskedUserApiKey(5);

        self::assertStringStartsWith('abcd', $masked);
        self::assertStringContainsString('*', $masked);
        self::assertStringNotContainsString('1234567890', $masked);
    }
}
