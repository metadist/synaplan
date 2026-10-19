<?php

declare(strict_types=1);

namespace App\Service\FreeApi;

use App\AI\Credential\OpenAiCompatibleEndpointRegistry;
use App\Repository\UserRepository;
use Psr\Cache\CacheItemPoolInterface;

/**
 * What the chat and the admin see about the Synaplan Demo on this install.
 *
 * Local state answers whether it runs and until when. Today's remaining
 * messages come from the Demo server and are cached for a minute, so opening
 * the chat in many tabs never multiplies the calls. The install key and the
 * pending revoke never leave this class; who started it and the install id
 * are for admins only.
 */
final readonly class FreeApiStatusReader
{
    private const REMOTE_TTL_SECONDS = 60;
    private const CACHE_PREFIX = 'freeapi_status_';
    private const SECONDS_PER_DAY = 86400;

    public function __construct(
        private FreeApiConfig $config,
        private FreeApiState $state,
        private FreeApiClient $client,
        private OpenAiCompatibleEndpointRegistry $endpoints,
        private UserRepository $users,
        private CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @return array{
     *     available: bool,
     *     active: bool,
     *     canManage: bool,
     *     server: string|null,
     *     expiresAt: int|null,
     *     daysLeft: int|null,
     *     activatedAt: int|null,
     *     activatedBy: string|null,
     *     installId: string|null,
     *     limits: array{messagesPerDay: int|null, imagesPerDay: int|null},
     *     today: array{messagesRemaining: int, messagesLimit: int, imagesRemaining: int, imagesLimit: int, resetsAt: int|null}|null,
     *     refusal: string|null
     * }
     */
    public function read(bool $forAdmin, int $now): array
    {
        $active = $this->state->isActive();
        $expiresAt = $active ? $this->state->expiresAt() : null;
        $limits = $active ? $this->state->limits() : [];
        $remote = $active ? $this->remote() : null;
        $activatedBy = $forAdmin && $active ? $this->state->activatedBy() : null;

        return [
            'available' => $this->config->isConfigured(),
            'active' => $active,
            'canManage' => $forAdmin,
            'server' => $this->config->serverHost(),
            'expiresAt' => $expiresAt,
            'daysLeft' => null !== $expiresAt ? max(0, (int) ceil(($expiresAt - $now) / self::SECONDS_PER_DAY)) : null,
            'activatedAt' => $active ? $this->state->activatedAt() : null,
            'activatedBy' => null !== $activatedBy ? $this->users->find($activatedBy)?->getMail() : null,
            'installId' => $forAdmin ? $this->state->existingInstallId() : null,
            'limits' => [
                'messagesPerDay' => self::intOrNull($limits['messages_per_day'] ?? null),
                'imagesPerDay' => self::intOrNull($limits['images_per_day'] ?? null),
            ],
            'today' => $remote['today'] ?? null,
            'refusal' => $remote['refusal'] ?? null,
        ];
    }

    /** Drops the cached server status, so the next read reflects a start or a leave at once. */
    public function forget(): void
    {
        $this->cache->deleteItem($this->cacheKey());
    }

    /**
     * @return array{today: array{messagesRemaining: int, messagesLimit: int, imagesRemaining: int, imagesLimit: int, resetsAt: int|null}|null, refusal: string|null}|null
     */
    private function remote(): ?array
    {
        $key = $this->endpoints->getEndpoint(FreeApiConfig::ENDPOINT_NAME)['api_key'] ?? '';
        if ('' === $key) {
            return null;
        }

        $item = $this->cache->getItem($this->cacheKey());
        $cached = $item->isHit() ? $item->get() : null;
        if (!\is_array($cached)) {
            try {
                $cached = ['body' => $this->client->status($key)];
            } catch (FreeApiException $e) {
                $cached = ['refusal' => $e->errorCode];
            }
            $item->set($cached);
            $item->expiresAfter(self::REMOTE_TTL_SECONDS);
            $this->cache->save($item);
        }

        $refusal = $cached['refusal'] ?? null;

        return [
            'today' => \is_array($cached['body'] ?? null) ? self::today($cached['body']) : null,
            'refusal' => \is_string($refusal) ? $refusal : null,
        ];
    }

    /**
     * @param array<mixed> $body
     *
     * @return array{messagesRemaining: int, messagesLimit: int, imagesRemaining: int, imagesLimit: int, resetsAt: int|null}|null
     */
    private static function today(array $body): ?array
    {
        $messagesRemaining = self::intOrNull($body['messages_remaining'] ?? null);
        $messagesLimit = self::intOrNull($body['messages_limit'] ?? null);
        if (null === $messagesRemaining || null === $messagesLimit) {
            return null;
        }

        return [
            'messagesRemaining' => max(0, $messagesRemaining),
            'messagesLimit' => max(0, $messagesLimit),
            'imagesRemaining' => max(0, self::intOrNull($body['images_remaining'] ?? null) ?? 0),
            'imagesLimit' => max(0, self::intOrNull($body['images_limit'] ?? null) ?? 0),
            'resetsAt' => self::intOrNull($body['resets_at'] ?? null),
        ];
    }

    private static function intOrNull(mixed $value): ?int
    {
        return \is_int($value) || (\is_float($value) && is_finite($value)) ? (int) $value : null;
    }

    private function cacheKey(): string
    {
        return self::CACHE_PREFIX.hash('xxh3', $this->state->existingInstallId() ?? 'none');
    }
}
