<?php

declare(strict_types=1);

namespace App\AI\Health;

use App\Repository\ModelHealthRepository;
use App\Service\Email\InternalEmailService;
use App\Service\Notification\DiscordNotificationService;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Decides whether an operator hears about a health event, and on which channel.
 *
 * Two different facts:
 *
 *   - Which models were already named in an incident. That lives on the health
 *     row (BLASTALERTED), not in Redis. A cache miss used to fail open and
 *     send the same incident again on every check, and an all-clear used to
 *     wipe the silence window so the next flicker paged immediately. Recovery
 *     does not clear it: the same model stays quiet for the configured period.
 *   - Whether an incident is open, so the all-clear is only sent for something
 *     the operator was told about. That key is per provider and kind. It is
 *     not the quiet period — a different model of the same provider still
 *     pages the same day.
 */
final readonly class ModelHealthAlerter
{
    private const OPEN_PREFIX = 'model_health.open.';

    /** An incident stays open far longer than one quiet period. */
    private const OPEN_TTL_SECONDS = 604800;

    public function __construct(
        private CacheItemPoolInterface $cache,
        private ModelHealthRepository $health,
        private ModelHealthConfig $config,
        private InternalEmailService $email,
        private DiscordNotificationService $discord,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Ids that are allowed to be named in an incident right now.
     *
     * @param list<int> $modelIds
     *
     * @return list<int>
     */
    public function dueModelIds(array $modelIds, ?int $now = null): array
    {
        $modelIds = array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $modelIds)));
        if ([] === $modelIds) {
            return [];
        }

        $now ??= time();
        $quietFor = $this->config->alertThrottleSeconds();
        $rows = $this->health->findIndexedByModelId($modelIds);
        $due = [];
        foreach ($modelIds as $id) {
            $last = isset($rows[$id]) ? $rows[$id]->getLastAlerted() : 0;
            if ($last <= 0 || ($now - $last) >= $quietFor) {
                $due[] = $id;
            }
        }

        return $due;
    }

    /**
     * Raise an alert for the models that are not already inside their quiet period.
     *
     * Models named here that were reported recently are dropped from the
     * message rather than holding the new ones back.
     *
     * @return bool true when something was actually sent
     */
    public function raise(ModelHealthAlert $alert): bool
    {
        $now = time();
        $deliver = $this->onlyDue($alert, $now);
        if (null === $deliver) {
            $this->logger->debug('Model health alert still inside the quiet period', [
                'provider' => $alert->provider,
                'kind' => $alert->kind,
                'models' => $alert->modelIds,
            ]);

            return false;
        }

        $this->email->sendModelHealthAlert($deliver);
        $this->discord->notifyModelHealth($deliver);
        $this->markAlerted($deliver->modelIds, $now);
        $this->remember(self::OPEN_PREFIX.$this->key($alert), self::OPEN_TTL_SECONDS);

        $this->logger->warning('Model health alert raised', [
            'provider' => $deliver->provider,
            'kind' => $deliver->kind,
            'models' => $deliver->modelCount(),
            'reason' => $deliver->reason,
        ]);

        return true;
    }

    /**
     * Send the all-clear — but only for an incident the operator was told about.
     *
     * Does not end the per-model quiet period. A model that fails again the
     * same day stays silent until that period has passed.
     *
     * @return bool true when an all-clear was actually sent
     */
    public function resolve(ModelHealthAlert $alert): bool
    {
        $key = $this->key($alert);

        if (!$this->has(self::OPEN_PREFIX.$key)) {
            return false;
        }

        $this->email->sendModelHealthAlert($alert, resolved: true);
        $this->discord->notifyModelHealth($alert, resolved: true);

        $this->forget(self::OPEN_PREFIX.$key);

        $this->logger->info('Model health alert resolved', [
            'provider' => $alert->provider,
            'kind' => $alert->kind,
            'models' => $alert->modelCount(),
        ]);

        return true;
    }

    /** Is an incident of this kind currently open for this provider? */
    public function isOpen(string $provider, string $kind): bool
    {
        return $this->has(self::OPEN_PREFIX.self::normalize($provider).'.'.$kind);
    }

    private function key(ModelHealthAlert $alert): string
    {
        return self::normalize($alert->provider).'.'.$alert->kind;
    }

    /**
     * Cache keys may not contain the PSR-6 reserved characters, and provider
     * names reach us straight from BSERVICE.
     */
    private static function normalize(string $provider): string
    {
        return preg_replace('/[^a-z0-9_]/', '_', mb_strtolower($provider)) ?? 'unknown';
    }

    /**
     * @return ModelHealthAlert|null the alert to send, or null when every model is still quiet
     */
    private function onlyDue(ModelHealthAlert $alert, int $now): ?ModelHealthAlert
    {
        if ([] === $alert->modelIds) {
            $this->logger->warning('Model health alert has no model ids and was not sent', [
                'provider' => $alert->provider,
                'kind' => $alert->kind,
            ]);

            return null;
        }

        $due = array_flip($this->dueModelIds($alert->modelIds, $now));
        $ids = [];
        $names = [];
        foreach ($alert->modelIds as $index => $id) {
            if (!isset($due[$id])) {
                continue;
            }
            $ids[] = $id;
            $names[] = $alert->modelNames[$index] ?? ('model '.$id);
        }

        if ([] === $ids) {
            return null;
        }

        if ($ids === $alert->modelIds) {
            return $alert;
        }

        return new ModelHealthAlert(
            $alert->kind,
            $alert->provider,
            $names,
            $alert->reason,
            $alert->displayName,
            $ids,
        );
    }

    /**
     * @param list<int> $modelIds
     */
    private function markAlerted(array $modelIds, int $now): void
    {
        foreach ($modelIds as $modelId) {
            $this->health->findOrCreate($modelId)->setLastAlerted($now);
        }
    }

    private function has(string $key): bool
    {
        try {
            return $this->cache->getItem($key)->isHit();
        } catch (\Throwable $e) {
            // A cache outage must not invent an all-clear. Missing the
            // open-incident flag skips a recovery message, which beats
            // announcing a recovery nobody was told about.
            $this->logger->debug('Model health alert state read failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function remember(string $key, int $ttl): void
    {
        try {
            $item = $this->cache->getItem($key);
            $item->set(time());
            $item->expiresAfter($ttl);
            $this->cache->save($item);
        } catch (\Throwable $e) {
            $this->logger->debug('Model health alert state write failed', ['error' => $e->getMessage()]);
        }
    }

    private function forget(string $key): void
    {
        try {
            $this->cache->deleteItem($key);
        } catch (\Throwable $e) {
            $this->logger->debug('Model health alert state delete failed', ['error' => $e->getMessage()]);
        }
    }
}
