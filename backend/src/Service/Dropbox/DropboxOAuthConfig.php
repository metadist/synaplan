<?php

declare(strict_types=1);

namespace App\Service\Dropbox;

use App\AI\Credential\SecretValueGuard;
use App\Repository\ConfigRepository;
use App\Service\EncryptionService;
use App\Service\OAuth\OAuthProviderConfig;
use App\Service\OAuth\OAuthProviderSource;

/**
 * Dropbox app registration (BCONFIG group DROPBOX, ownerId=0) — connector
 * plan 07 C13, the second consumer of the shared OAuth framework after M365.
 *
 * Operator-owned, install-wide: Synaplan Cloud runs one Dropbox app, a
 * self-hoster registers their own at https://www.dropbox.com/developers/apps.
 * Users never see these values — they click "Connect Dropbox" and consent.
 *
 * APP_SECRET is stored encrypted ({@see EncryptionService}); every reader goes
 * through {@see appSecret()} so the ciphertext never leaves this class.
 */
final readonly class DropboxOAuthConfig implements OAuthProviderSource
{
    public const CONFIG_GROUP = 'DROPBOX';
    public const KEY_ENABLED = 'ENABLED';
    public const KEY_APP_KEY = 'APP_KEY';
    public const KEY_APP_SECRET = 'APP_SECRET';
    public const KEY_REDIRECT_URI = 'REDIRECT_URI';

    /** Provider id used for the signed OAuth state and the connection config. */
    public const PROVIDER = 'dropbox';

    /** Path the Dropbox app must list as a redirect URI. */
    public const CALLBACK_PATH = '/api/v1/connections/dropbox/callback';

    /**
     * Scoped-app permissions requested at consent. `files.content.write` is
     * the whole point (save_to_folder); `account_info.read` names the
     * connection and powers the cheap connectivity test.
     */
    public const SCOPES = ['account_info.read', 'files.content.write'];

    public function __construct(
        private ConfigRepository $configRepository,
        private EncryptionService $encryption,
        private string $appUrl,
    ) {
    }

    public function provider(): string
    {
        return self::PROVIDER;
    }

    public function isEnabled(): bool
    {
        $raw = $this->value(self::KEY_ENABLED);

        return null !== $raw && in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
    }

    public function appKey(): string
    {
        return trim($this->value(self::KEY_APP_KEY) ?? '');
    }

    /**
     * Decrypted app secret, or '' when unset. An unreadable ciphertext (key
     * rotated, row hand-edited) is reported as unset rather than fatal so the
     * admin UI can show "not configured" instead of a 500.
     */
    public function appSecret(): string
    {
        $stored = $this->value(self::KEY_APP_SECRET);
        if (null === $stored || '' === trim($stored)) {
            return '';
        }

        try {
            return trim($this->encryption->decrypt($stored));
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Must match a redirect URI registered in the Dropbox App Console exactly,
     * including scheme and trailing path. Defaults to APP_URL + the callback
     * path so a standard install needs no extra row.
     */
    public function redirectUri(): string
    {
        $configured = trim($this->value(self::KEY_REDIRECT_URI) ?? '');
        if ('' !== $configured) {
            return rtrim($configured, '/');
        }

        return rtrim($this->appUrl, '/').self::CALLBACK_PATH;
    }

    /**
     * True when consent can actually be started. The admin UI and the connect
     * endpoint both gate on this instead of failing at Dropbox.
     */
    public function isConfigured(): bool
    {
        return $this->isEnabled()
            && SecretValueGuard::isUsable($this->appKey())
            && SecretValueGuard::isUsable($this->appSecret());
    }

    public function toProviderConfig(): OAuthProviderConfig
    {
        return new OAuthProviderConfig(
            provider: self::PROVIDER,
            authorizeUrl: 'https://www.dropbox.com/oauth2/authorize',
            tokenUrl: 'https://api.dropboxapi.com/oauth2/token',
            clientId: $this->appKey(),
            clientSecret: $this->appSecret(),
            redirectUri: $this->redirectUri(),
            scopes: self::SCOPES,
            // Without `token_access_type=offline` Dropbox issues a short-lived
            // access token and NO refresh token — every scheduled run would
            // die after four hours.
            extraAuthorizeParams: ['token_access_type' => 'offline'],
        );
    }

    private function value(string $setting): ?string
    {
        return $this->configRepository->getValue(0, self::CONFIG_GROUP, $setting);
    }
}
