<?php

declare(strict_types=1);

namespace App\Service\Telegram;

/**
 * The URL Telegram will call must be a public https address. Localhost and
 * private IPs fail with {@see TelegramChannelException::PUBLIC_URL_REQUIRED}
 * so the card can name APP_URL. Tests may set TELEGRAM_ALLOW_LOCAL_WEBHOOK
 * or TELEGRAM_WEBHOOK_BASE_URL to a public-looking host.
 */
final readonly class PublicWebhookUrlValidator
{
    public function __construct(
        private bool $allowLocal,
    ) {
    }

    public function isPublic(string $url): bool
    {
        try {
            $this->assertPublic($url);
        } catch (TelegramChannelException) {
            return false;
        }

        return true;
    }

    public function assertPublic(string $url): void
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts)) {
            throw new TelegramChannelException(TelegramChannelException::PUBLIC_URL_REQUIRED);
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ('' === $host || !in_array($scheme, ['http', 'https'], true)) {
            throw new TelegramChannelException(TelegramChannelException::PUBLIC_URL_REQUIRED);
        }

        if ($this->allowLocal) {
            return;
        }

        if ('https' !== $scheme || $this->isLocalOrPrivate($host)) {
            throw new TelegramChannelException(TelegramChannelException::PUBLIC_URL_REQUIRED);
        }
    }

    private function isLocalOrPrivate(string $host): bool
    {
        if (in_array($host, ['localhost', '0.0.0.0', '::1', '[::1]'], true)) {
            return true;
        }
        if (str_ends_with($host, '.local') || str_ends_with($host, '.internal') || str_ends_with($host, '.localhost')) {
            return true;
        }

        $ip = $host;
        if (str_starts_with($ip, '[') && str_ends_with($ip, ']')) {
            $ip = substr($ip, 1, -1);
        }
        if (false === filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        return false === filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );
    }
}
