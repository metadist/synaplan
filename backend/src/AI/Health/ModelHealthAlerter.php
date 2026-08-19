<?php

declare(strict_types=1);

namespace App\AI\Health;

use App\Service\DiscordNotificationService;
use App\Service\InternalEmailService;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Decides whether an operator hears about a health event, and on which channel.
 *
 * Two pieces of state per (provider, kind), both in the shared Redis pool so
 * three web nodes do not send three copies of the same alert:
 *
 *   - a THROTTLE key that suppresses repeats of an alert already sent,
 *   - an OPEN key that remembers an incident is running.
 *
 * The second one is what makes the all-clear trustworthy. Without it, a restart
 * or an expired throttle would produce "resolved" for an incident nobody was
 * ever told about — and an all-clear for an alert you never saw is worse than
 * no all-clear at all.
 */
final readonly class ModelHealthAlerter
{
    private const THROTTLE_PREFIX = 'model_health.alert.';
    private const OPEN_PREFIX = 'model_health.open.';

    /** An incident stays open far longer than the throttle window. */
    private const OPEN_TTL_SECONDS = 604800;

    public function __construct(
        private CacheItemPoolInterface $cache,
        private ModelHealthConfig $config,
        private InternalEmailService $email,
        private DiscordNotificationService $discord,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Raise an alert unless an identical one went out recently.
     *
     * @return bool true when something was actually sent
     */
    public function raise(ModelHealthAlert $alert): bool
    {
        $key = $this->key($alert);

        if ($this->has(self::THROTTLE_PREFIX.$key)) {
            $this->logger->debug('Model health alert throttled', [
                'provider' => $alert->provider,
                'kind' => $alert->kind,
            ]);

            return false;
        }

        $this->email->sendModelHealthAlert($alert);
        $this->discord->notifyModelHealth($alert);

        $this->remember(self::THROTTLE_PREFIX.$key, $this->config->alertThrottleSeconds());
        $this->remember(self::OPEN_PREFIX.$key, self::OPEN_TTL_SECONDS);

        $this->logger->warning('Model health alert raised', [
            'provider' => $alert->provider,
            'kind' => $alert->kind,
            'models' => $alert->modelCount(),
            'reason' => $alert->reason,
        ]);

        return true;
    }

    /**
     * Send the all-clear — but only for an incident the operator was told about.
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
        // Drop the throttle too: the next incident on this provider deserves an
        // immediate alert instead of inheriting the old one's silence window.
        $this->forget(self::THROTTLE_PREFIX.$key);

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

    private function has(string $key): bool
    {
        try {
            return $this->cache->getItem($key)->isHit();
        } catch (\Throwable $e) {
            // A cache outage must not silence alerting. Reporting "not sent
            // yet" errs towards a duplicate alert, which beats silence.
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
