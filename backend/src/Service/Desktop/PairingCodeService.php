<?php

declare(strict_types=1);

namespace App\Service\Desktop;

use App\Service\Desktop\Exception\PairingLimitException;
use App\Service\Infrastructure\RedisService;

/**
 * Short-lived pairing codes exchanged for a scoped desktop API key.
 *
 * Codes live in Redis with a TTL (never a table — checklist row 14): a signed-in
 * user mints an 8-character code in the web UI, then types it into Synaplan
 * Desktop, which exchanges it once for its key ({@see PairingService}).
 *
 * Redis layout (all keys under RedisService's own prefix):
 *   desktop_pair:code:{CODE}      -> JSON {userId, expiresAt}, TTL 600s
 *   desktop_pair:outstanding:{uid}-> SET of a user's still-valid codes
 *                                    (self-healed on create; caps concurrency)
 *   desktop_pair:hour:{uid}       -> counter, TTL 3600s (creates-per-hour cap)
 *
 * The code alphabet excludes visually ambiguous characters (0/O, 1/I/L) so a
 * user can read one off a screen and type it without mistakes.
 */
final readonly class PairingCodeService
{
    public const CODE_TTL_SECONDS = 600;
    private const MAX_OUTSTANDING = 5;
    private const MAX_PER_HOUR = 20;
    private const CODE_LENGTH = 8;

    /** Crockford-ish alphabet without 0/O/1/I/L/U. */
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const CODE_PREFIX = 'desktop_pair:code:';
    private const OUTSTANDING_PREFIX = 'desktop_pair:outstanding:';
    private const HOUR_PREFIX = 'desktop_pair:hour:';

    public function __construct(
        private RedisService $redis,
    ) {
    }

    /**
     * Mint a one-time pairing code for a signed-in user.
     *
     * @return array{code: string, expiresAt: int}
     *
     * @throws PairingLimitException when an outstanding-code or per-hour limit is hit
     */
    public function create(int $userId): array
    {
        if ($this->countOutstanding($userId) >= self::MAX_OUTSTANDING) {
            throw new PairingLimitException('Too many outstanding pairing codes. Use or cancel an existing one and try again.');
        }

        $hourKey = self::HOUR_PREFIX.$userId;
        $created = $this->redis->increment($hourKey);
        if (1 === $created) {
            $this->redis->expire($hourKey, 3600);
        }
        if (null !== $created && $created > self::MAX_PER_HOUR) {
            throw new PairingLimitException('Too many pairing codes created in the last hour. Please wait before creating more.');
        }

        $code = $this->generateUniqueCode();
        $expiresAt = time() + self::CODE_TTL_SECONDS;

        $payload = json_encode(['userId' => $userId, 'expiresAt' => $expiresAt], \JSON_UNESCAPED_SLASHES);
        if (false === $payload) {
            throw new \RuntimeException('Failed to encode pairing code payload.');
        }

        $this->redis->set(self::CODE_PREFIX.$code, $payload, self::CODE_TTL_SECONDS);

        $outstandingKey = self::OUTSTANDING_PREFIX.$userId;
        $this->redis->sAdd($outstandingKey, $code);
        $this->redis->expire($outstandingKey, self::CODE_TTL_SECONDS);

        return ['code' => $code, 'expiresAt' => $expiresAt];
    }

    /**
     * Consume a pairing code, returning the owning user id — or null if the code
     * is unknown, expired, or already used. One-time: a successful consume
     * deletes the code so it cannot be replayed.
     */
    public function consume(string $code): ?int
    {
        $normalized = self::normalize($code);
        if ('' === $normalized) {
            return null;
        }

        $raw = $this->redis->get(self::CODE_PREFIX.$normalized);
        if (null === $raw) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['userId'])) {
            $this->redis->delete(self::CODE_PREFIX.$normalized);

            return null;
        }

        $userId = (int) $decoded['userId'];
        $expiresAt = (int) ($decoded['expiresAt'] ?? 0);

        // One-time: remove before returning so a race cannot pair twice.
        $this->redis->delete(self::CODE_PREFIX.$normalized);
        $this->redis->sRem(self::OUTSTANDING_PREFIX.$userId, $normalized);

        if ($expiresAt > 0 && $expiresAt < time()) {
            return null;
        }

        return $userId > 0 ? $userId : null;
    }

    /**
     * Count a user's still-valid codes, self-healing set entries whose code key
     * already expired (Redis sets do not expire members individually).
     */
    private function countOutstanding(int $userId): int
    {
        $outstandingKey = self::OUTSTANDING_PREFIX.$userId;
        $count = 0;
        foreach ($this->redis->sMembers($outstandingKey) as $code) {
            if ($this->redis->exists(self::CODE_PREFIX.$code)) {
                ++$count;
            } else {
                $this->redis->sRem($outstandingKey, $code);
            }
        }

        return $count;
    }

    private function generateUniqueCode(): string
    {
        for ($attempt = 0; $attempt < 10; ++$attempt) {
            $code = self::randomCode();
            if (!$this->redis->exists(self::CODE_PREFIX.$code)) {
                return $code;
            }
        }

        throw new \RuntimeException('Failed to generate a unique pairing code.');
    }

    private static function randomCode(): string
    {
        $max = \strlen(self::ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; ++$i) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    private static function normalize(string $code): string
    {
        // Uppercase and keep only alphabet characters, so spaces or dashes the
        // user might type between groups are ignored. Codes are generated
        // exclusively from the alphabet, so no look-alike folding is needed.
        $upper = strtoupper(trim($code));
        $out = preg_replace('/[^'.preg_quote(self::ALPHABET, '/').']/', '', $upper);

        return \is_string($out) ? $out : '';
    }
}
