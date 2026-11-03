<?php

declare(strict_types=1);

namespace App\Service\Office\Wopi;

use Psr\Log\LoggerInterface;

/**
 * Short-lived HMAC token for Collabora's WOPI callbacks.
 *
 * The signing key is derived from APP_SECRET, the same way media tokens are,
 * so a leaked editor URL cannot be replayed as a session cookie or a media
 * URL. The payload names one file and one user. Collabora sends it back as
 * the `access_token` query parameter — never the user's API key.
 */
final readonly class WopiTokenService
{
    public const TTL_SECONDS = 600;

    private const PURPOSE = 'wopi_file';

    public function __construct(
        private LoggerInterface $logger,
        private string $appSecret,
    ) {
    }

    public function issue(int $fileId, int $userId, ?int $now = null): string
    {
        if ($fileId <= 0 || $userId <= 0) {
            throw new \InvalidArgumentException('A WOPI token needs a saved file and user');
        }
        $issuedAt = $now ?? time();
        $json = json_encode([
            'fid' => $fileId,
            'uid' => $userId,
            'purpose' => self::PURPOSE,
            'exp' => $issuedAt + self::TTL_SECONDS,
        ], JSON_THROW_ON_ERROR);

        return $this->encode($json).'.'.$this->sign($json);
    }

    public function verify(string $token, ?int $now = null): ?WopiAccess
    {
        $parts = explode('.', $token);
        if (2 !== count($parts)) {
            return null;
        }
        [$encoded, $signature] = $parts;
        $json = base64_decode(strtr($encoded, '-_', '+/'), true);
        if (false === $json || '' === $json) {
            return null;
        }
        if (!hash_equals($this->sign($json), $signature)) {
            $this->logger->warning('WOPI token signature verification failed');

            return null;
        }
        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($payload) || self::PURPOSE !== ($payload['purpose'] ?? null)) {
            return null;
        }
        $fileId = $payload['fid'] ?? null;
        $userId = $payload['uid'] ?? null;
        $exp = $payload['exp'] ?? null;
        if (!is_int($fileId) || !is_int($userId) || !is_int($exp) || $fileId <= 0 || $userId <= 0) {
            return null;
        }
        if ($exp <= ($now ?? time())) {
            return null;
        }

        return new WopiAccess($fileId, $userId, $exp);
    }

    private function encode(string $json): string
    {
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    private function sign(string $json): string
    {
        $key = hash('sha256', 'wopi-file-v1|'.$this->appSecret, true);

        return rtrim(strtr(base64_encode(hash_hmac('sha256', $json, $key, true)), '+/', '-_'), '=');
    }
}
