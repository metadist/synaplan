<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Dropbox;

use App\Repository\ConfigRepository;
use App\Service\Dropbox\DropboxOAuthConfig;
use App\Service\EncryptionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DropboxOAuthConfigTest extends TestCase
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
        self::assertSame('', $config->appKey());
        self::assertSame('', $config->appSecret());
    }

    public function testEnabledWithoutCredentialsIsStillNotConfigured(): void
    {
        $config = $this->config([DropboxOAuthConfig::KEY_ENABLED => '1']);

        self::assertTrue($config->isEnabled());
        self::assertFalse($config->isConfigured(), 'the flag alone must not expose a broken connect button');
    }

    public function testAppSecretIsDecryptedOnRead(): void
    {
        $config = $this->configured();

        self::assertSame('the-secret', $config->appSecret());
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
            DropboxOAuthConfig::KEY_ENABLED => '1',
            DropboxOAuthConfig::KEY_APP_KEY => 'app-key',
            DropboxOAuthConfig::KEY_APP_SECRET => 'not-a-valid-ciphertext',
        ]);

        self::assertSame('', $config->appSecret());
        self::assertFalse($config->isConfigured());
    }

    public function testRedirectUriDefaultsToTheAppUrl(): void
    {
        self::assertSame(
            'https://app.example'.DropboxOAuthConfig::CALLBACK_PATH,
            $this->config([])->redirectUri(),
        );
    }

    public function testRedirectUriCanBeOverriddenForAProxiedInstall(): void
    {
        $config = $this->config([DropboxOAuthConfig::KEY_REDIRECT_URI => 'https://proxy.example/api/v1/connections/dropbox/callback/']);

        self::assertSame('https://proxy.example/api/v1/connections/dropbox/callback', $config->redirectUri());
    }

    public function testProviderConfigRequestsAnOfflineToken(): void
    {
        $provider = $this->configured()->toProviderConfig();

        self::assertSame('https://www.dropbox.com/oauth2/authorize', $provider->authorizeUrl);
        self::assertSame('https://api.dropboxapi.com/oauth2/token', $provider->tokenUrl);
        self::assertStringContainsString('files.content.write', $provider->scopeString());
        self::assertSame('offline', $provider->extraAuthorizeParams['token_access_type'] ?? null, 'without it Dropbox issues no refresh token');
    }

    private function configured(): DropboxOAuthConfig
    {
        return $this->config([
            DropboxOAuthConfig::KEY_ENABLED => 'true',
            DropboxOAuthConfig::KEY_APP_KEY => 'app-key',
            DropboxOAuthConfig::KEY_APP_SECRET => $this->encryption->encrypt('the-secret'),
        ]);
    }

    /**
     * @param array<string, string> $rows
     */
    private function config(array $rows): DropboxOAuthConfig
    {
        $repository = $this->createStub(ConfigRepository::class);
        $repository->method('getValue')->willReturnCallback(
            static fn (int $ownerId, string $group, string $setting): ?string => 0 === $ownerId && DropboxOAuthConfig::CONFIG_GROUP === $group
                ? ($rows[$setting] ?? null)
                : null,
        );

        return new DropboxOAuthConfig($repository, $this->encryption, 'https://app.example');
    }
}
