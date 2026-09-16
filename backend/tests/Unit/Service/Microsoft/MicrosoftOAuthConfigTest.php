<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Microsoft;

use App\Repository\ConfigRepository;
use App\Service\EncryptionService;
use App\Service\Microsoft\MicrosoftOAuthConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MicrosoftOAuthConfigTest extends TestCase
{
    private EncryptionService $encryption;

    protected function setUp(): void
    {
        $this->encryption = new EncryptionService('test-secret', new NullLogger());
    }

    public function testAnUnconfiguredInstallOffersNothing(): void
    {
        $config = $this->config([]);

        self::assertFalse($config->isEnabled());
        self::assertFalse($config->isConfigured());
        self::assertSame('', $config->clientId());
        self::assertSame('', $config->clientSecret());
    }

    public function testEnabledWithoutCredentialsIsStillNotConfigured(): void
    {
        $config = $this->config([MicrosoftOAuthConfig::KEY_ENABLED => '1']);

        self::assertTrue($config->isEnabled());
        self::assertFalse($config->isConfigured(), 'the flag alone must not expose a broken connect button');
    }

    public function testClientSecretIsDecryptedOnRead(): void
    {
        $config = $this->configured();

        self::assertSame('the-secret', $config->clientSecret());
        self::assertTrue($config->isConfigured());
    }

    /**
     * A rotated APP_SECRET makes the stored ciphertext unreadable. Reporting
     * "not configured" lets the admin UI ask for the secret again instead of
     * turning every page load into a 500.
     */
    public function testUnreadableSecretIsTreatedAsUnset(): void
    {
        $config = $this->config([
            MicrosoftOAuthConfig::KEY_ENABLED => '1',
            MicrosoftOAuthConfig::KEY_CLIENT_ID => 'client-id',
            MicrosoftOAuthConfig::KEY_CLIENT_SECRET => 'not-a-valid-ciphertext',
        ]);

        self::assertSame('', $config->clientSecret());
        self::assertFalse($config->isConfigured());
    }

    public function testPlaceholderCredentialsDoNotCountAsConfigured(): void
    {
        $config = $this->config([
            MicrosoftOAuthConfig::KEY_ENABLED => '1',
            MicrosoftOAuthConfig::KEY_CLIENT_ID => 'changeme',
            MicrosoftOAuthConfig::KEY_CLIENT_SECRET => $this->encryption->encrypt('the-secret'),
        ]);

        self::assertFalse($config->isConfigured());
    }

    public function testRedirectUriDefaultsToTheAppUrl(): void
    {
        self::assertSame(
            'https://app.example'.MicrosoftOAuthConfig::CALLBACK_PATH,
            $this->config([])->redirectUri(),
        );
    }

    public function testRedirectUriCanBeOverriddenForAProxiedInstall(): void
    {
        $config = $this->config([MicrosoftOAuthConfig::KEY_REDIRECT_URI => 'https://proxy.example/api/v1/connections/m365/callback/']);

        self::assertSame('https://proxy.example/api/v1/connections/m365/callback', $config->redirectUri());
    }

    public function testTenantDefaultsToCommonAndReachesTheEndpoints(): void
    {
        $provider = $this->configured()->toProviderConfig();

        self::assertSame('https://login.microsoftonline.com/common/oauth2/v2.0/authorize', $provider->authorizeUrl);
        self::assertSame('https://login.microsoftonline.com/common/oauth2/v2.0/token', $provider->tokenUrl);
        self::assertStringContainsString('offline_access', $provider->scopeString());
        self::assertStringContainsString('Mail.Read', $provider->scopeString());
    }

    public function testSingleTenantIsHonoured(): void
    {
        $config = $this->config([
            MicrosoftOAuthConfig::KEY_ENABLED => '1',
            MicrosoftOAuthConfig::KEY_CLIENT_ID => 'client-id',
            MicrosoftOAuthConfig::KEY_CLIENT_SECRET => $this->encryption->encrypt('s'),
            MicrosoftOAuthConfig::KEY_TENANT => '00000000-1111-2222-3333-444444444444',
        ]);

        self::assertStringContainsString(
            '/00000000-1111-2222-3333-444444444444/',
            $config->toProviderConfig()->authorizeUrl,
        );
    }

    private function configured(): MicrosoftOAuthConfig
    {
        return $this->config([
            MicrosoftOAuthConfig::KEY_ENABLED => 'true',
            MicrosoftOAuthConfig::KEY_CLIENT_ID => 'client-id',
            MicrosoftOAuthConfig::KEY_CLIENT_SECRET => $this->encryption->encrypt('the-secret'),
        ]);
    }

    /**
     * @param array<string, string> $rows
     */
    private function config(array $rows): MicrosoftOAuthConfig
    {
        $repository = $this->createStub(ConfigRepository::class);
        $repository->method('getValue')->willReturnCallback(
            static fn (int $ownerId, string $group, string $setting): ?string => 0 === $ownerId && MicrosoftOAuthConfig::CONFIG_GROUP === $group
                ? ($rows[$setting] ?? null)
                : null,
        );

        return new MicrosoftOAuthConfig($repository, $this->encryption, 'https://app.example');
    }
}
