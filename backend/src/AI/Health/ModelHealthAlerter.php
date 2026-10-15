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
 *   - Which models were already named in an incident, and for which kind. That
 *     lives on the health row (BLASTALERTED, BLASTALERTEDKIND), not in Redis.
 *     A cache miss used to fail open and send the same incident again on
 *     every check, and an all-clear used to wipe the silence window so the
 *     next flicker paged immediately. Recovery does not clear it: the same
 *     model stays quiet for the configured period unless its problem gets
 *     worse — a rejected key after a flaky hour is still reported.
 *   - Whether an incident is open, and which models it named, so the
 *     all-clear is only sent for something the operator was told about and
 *     can say what recovered. That key is per provider and kind. It is not
 *     the quiet period — a different model of the same provider still pages
 *     the same day.
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
     * Ids that are allowed to be named in an incident of this kind right now.
     *
     * @param list<int> $modelIds
     *
     * @return list<int>
     */
    public function dueModelIds(array $modelIds, string $kind, ?int $now = null): array
    {
        $modelIds = array_values(array_unique($modelIds));
        if ([] === $modelIds) {
            return [];
        }

        $now ??= time();
        $quietFor = $this->config->alertThrottleSeconds();
        $severity = ModelHealthAlert::severity($kind);
        $rows = $this->health->findIndexedByModelId($modelIds);
        $due = [];
        foreach ($modelIds as $id) {
            $last = isset($rows[$id]) ? $rows[$id]->getLastAlerted() : 0;
            $lastKind = isset($rows[$id]) ? $rows[$id]->getLastAlertedKind() : null;
            if ($last <= 0
                || ($now - $last) >= $quietFor
                || $severity > ModelHealthAlert::severity($lastKind)) {
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
        $this->health->stampAlerted($deliver->modelIds, $deliver->kind, $now);
        $this->rememberOpen($this->key($alert), $deliver);

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
     * Names the models that incident reported, so the message says what
     * recovered. Does not end the per-model quiet period: a model that fails
     * again inside it stays silent, and the all-clear says so.
     *
     * @return bool true when an all-clear was actually sent
     */
    public function resolve(ModelHealthAlert $alert): bool
    {
        $key = $this->key($alert);

        $reported = $this->openModels($key);
        if (null === $reported) {
            return false;
        }

        $deliver = [] === $reported
            ? $alert->with($alert->modelNames, $alert->modelIds, $this->config->alertThrottleSeconds())
            : $alert->with(array_values($reported), array_keys($reported), $this->config->alertThrottleSeconds());

        $this->email->sendModelHealthAlert($deliver, resolved: true);
        $this->discord->notifyModelHealth($deliver, resolved: true);

        $this->forget(self::OPEN_PREFIX.$key);

        $this->logger->info('Model health alert resolved', [
            'provider' => $deliver->provider,
            'kind' => $deliver->kind,
            'models' => $deliver->modelCount(),
        ]);

        return true;
    }

    /** Is an incident of this kind currently open for this provider? */
    public function isOpen(string $provider, string $kind): bool
    {
        return null !== $this->openIncident($provider, $kind);
    }

    /**
     * The models an open incident reported, by catalog id, or null when none
     * is open. Empty for an incident opened before the ids were remembered.
     *
     * @return array<int, string>|null
     */
    public function openIncident(string $provider, string $kind): ?array
    {
        return $this->openModels(self::normalize($provider).'.'.$kind);
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

        $due = array_flip($this->dueModelIds($alert->modelIds, $alert->kind, $now));
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

        return $alert->with($names, $ids, $this->config->alertThrottleSeconds());
    }

    /**
     * @return array<int, string>|null model id => name, or null when no incident is open
     */
    private function openModels(string $key): ?array
    {
        try {
            $item = $this->cache->getItem(self::OPEN_PREFIX.$key);
            if (!$item->isHit()) {
                return null;
            }

            return self::modelMap($item->get());
        } catch (\Throwable $e) {
            // A cache outage must not invent an all-clear. Missing the
            // open-incident flag skips a recovery message, which beats
            // announcing a recovery nobody was told about.
            $this->logger->debug('Model health alert state read failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Mark the incident open and add these models to what it reported. A
     * second model of the same provider later that day joins the same
     * incident, so the all-clear names both.
     */
    private function rememberOpen(string $key, ModelHealthAlert $sent): void
    {
        try {
            $item = $this->cache->getItem(self::OPEN_PREFIX.$key);
            $models = $item->isHit() ? self::modelMap($item->get()) : [];
            foreach ($sent->modelIds as $index => $id) {
                $models[$id] = $sent->modelNames[$index] ?? ('model '.$id);
            }
            $item->set($models);
            $item->expiresAfter(self::OPEN_TTL_SECONDS);
            $this->cache->save($item);
        } catch (\Throwable $e) {
            $this->logger->debug('Model health alert state write failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Incidents opened before the ids were remembered hold a bare timestamp.
     *
     * @return array<int, string>
     */
    private static function modelMap(mixed $stored): array
    {
        if (!is_array($stored)) {
            return [];
        }

        $models = [];
        foreach ($stored as $id => $name) {
            if (is_int($id) && is_string($name)) {
                $models[$id] = $name;
            }
        }

        return $models;
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
