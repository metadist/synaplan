<?php

declare(strict_types=1);

namespace App\Service\Microsoft;

use App\AI\Credential\SecretValueGuard;
use App\Repository\ConfigRepository;
use App\Service\EncryptionService;
use App\Service\OAuth\OAuthProviderConfig;
use App\Service\OAuth\OAuthProviderSource;

/**
 * Microsoft identity platform app registration (BCONFIG group M365, ownerId=0).
 *
 * Operator-owned, install-wide: Synaplan Cloud runs a multi-tenant registration,
 * self-hosters register their own app (connector plan 07 §S3). The values are
 * therefore global rows only — a per-user override would let a tenant point the
 * consent flow at an app registration Synaplan does not control.
 *
 * CLIENT_SECRET is stored encrypted ({@see EncryptionService}); every reader
 * goes through {@see clientSecret()} so the ciphertext never leaves this class.
 */
final readonly class MicrosoftOAuthConfig implements OAuthProviderSource
{
    public const CONFIG_GROUP = 'M365';
    public const KEY_ENABLED = 'ENABLED';
    public const KEY_CLIENT_ID = 'CLIENT_ID';
    public const KEY_CLIENT_SECRET = 'CLIENT_SECRET';
    public const KEY_TENANT = 'TENANT';
    public const KEY_REDIRECT_URI = 'REDIRECT_URI';

    /** Provider id used for the signed OAuth state and the connection config. */
    public const PROVIDER = 'm365';

    /**
     * Work/school and personal accounts. `organizations` restricts to work
     * accounts, a GUID restricts to a single tenant (the self-host default).
     */
    public const DEFAULT_TENANT = 'common';

    /** Path the Microsoft app registration must list as a redirect URI. */
    public const CALLBACK_PATH = '/api/v1/connections/m365/callback';

    /**
     * Delegated scopes requested at consent. `offline_access` is what makes an
     * unattended run possible at all — without it Microsoft issues no refresh
     * token and every schedule dies when the access token expires.
     *
     * `Calendars.ReadWrite` (Outlook calendar event creation with dedup reads)
     * and `Mail.Send` (mail results from the user's own mailbox) joined the
     * set in Phase M. Connections consented BEFORE that carry only the old
     * grant in {@see \App\Entity\Connection::getScopes()} — the calendar/mail
     * write paths check the granted scopes and ask for a reconnect instead of
     * surfacing a raw Graph 403.
     */
    public const SCOPES = ['offline_access', 'openid', 'email', 'profile', 'User.Read', 'Mail.Read', 'Calendars.ReadWrite', 'Mail.Send'];

    /** Granted-scope names the write paths probe for (see SCOPES docblock). */
    public const SCOPE_CALENDAR_WRITE = 'Calendars.ReadWrite';
    public const SCOPE_MAIL_SEND = 'Mail.Send';

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

    public function clientId(): string
    {
        return trim($this->value(self::KEY_CLIENT_ID) ?? '');
    }

    /**
     * Decrypted client secret, or '' when unset. An unreadable ciphertext (key
     * rotated, row hand-edited) is reported as unset rather than fatal so the
     * admin UI can show "not configured" instead of a 500.
     */
    public function clientSecret(): string
    {
        $stored = $this->value(self::KEY_CLIENT_SECRET);
        if (null === $stored || '' === trim($stored)) {
            return '';
        }

        try {
            return trim($this->encryption->decrypt($stored));
        } catch (\Throwable) {
            return '';
        }
    }

    public function tenant(): string
    {
        $tenant = trim($this->value(self::KEY_TENANT) ?? '');

        return '' === $tenant ? self::DEFAULT_TENANT : $tenant;
    }

    /**
     * Must match a redirect URI registered in Azure exactly, including scheme
     * and trailing path. Defaults to APP_URL + the callback path so a standard
     * install needs no extra row.
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
     * endpoint both gate on this instead of failing at Microsoft.
     */
    public function isConfigured(): bool
    {
        return $this->isEnabled()
            && SecretValueGuard::isUsable($this->clientId())
            && SecretValueGuard::isUsable($this->clientSecret());
    }

    public function toProviderConfig(): OAuthProviderConfig
    {
        $tenant = rawurlencode($this->tenant());

        return new OAuthProviderConfig(
            provider: self::PROVIDER,
            authorizeUrl: sprintf('https://login.microsoftonline.com/%s/oauth2/v2.0/authorize', $tenant),
            tokenUrl: sprintf('https://login.microsoftonline.com/%s/oauth2/v2.0/token', $tenant),
            clientId: $this->clientId(),
            clientSecret: $this->clientSecret(),
            redirectUri: $this->redirectUri(),
            scopes: self::SCOPES,
            // `prompt=consent` is deliberate on the first grant: without it
            // Microsoft may silently reuse an existing consent that lacks
            // `offline_access`, leaving us with no refresh token.
            extraAuthorizeParams: ['response_mode' => 'query', 'prompt' => 'consent'],
        );
    }

    private function value(string $setting): ?string
    {
        return $this->configRepository->getValue(0, self::CONFIG_GROUP, $setting);
    }
}
