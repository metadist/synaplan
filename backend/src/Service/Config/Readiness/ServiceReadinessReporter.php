<?php

declare(strict_types=1);

namespace App\Service\Config\Readiness;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The `services` list of `GET /api/v1/config/features`: one row per service
 * of the stack with its state, what is waiting or stopped, and — only where
 * the admin can act on this install — one recovery sentence.
 *
 * A service being down never blocks this list or chat; its row says what
 * stopped working instead.
 */
final readonly class ServiceReadinessReporter
{
    public const CACHE_KEY = 'service_readiness.list';
    private const CACHE_TTL_SECONDS = 5;

    /** Platforms installed with one of the compose files this repository ships. */
    private const COMPOSE_PLATFORMS = ['selfhost', 'quickstart', 'lite', 'minimal'];

    public function __construct(
        private ServiceReadinessProbes $probes,
        private ServiceStateTracker $tracker,
        private CacheInterface $cache,
        private string $environment,
        private string $platform,
        private string $kubernetesHost,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            return array_map(static fn (ServiceReadiness $row): array => $row->toArray(), $this->build());
        });
    }

    /**
     * @return list<ServiceReadiness>
     */
    public function build(): array
    {
        $rows = [];
        foreach ($this->probes->observeAll() as $id => $observation) {
            $tracked = $this->tracker->track($id, $observation);
            $state = $tracked['state'];

            $rows[] = new ServiceReadiness(
                id: $id,
                state: $state,
                recovery: $this->recovery($id, $state),
                since: $tracked['since'],
                download: ServiceReadiness::DOWNLOADING === $state
                    ? new DownloadProgress($observation->percent, $observation->model, $tracked['minutes_left'])
                    : null,
            );
        }

        return $rows;
    }

    private function recovery(string $id, string $state): ?string
    {
        $service = ServiceReadinessCatalog::SERVICES[$id];
        $dev = 'dev' === $this->environment;
        if (!$dev && ($service['dev_only'] || !$this->isComposeInstall())) {
            return null;
        }

        $compose = $service['compose'];

        return match ($state) {
            ServiceReadiness::OFF => $dev && null !== $service['profile']
                ? sprintf('Turn it on with the "%1$s" Compose profile: COMPOSE_PROFILES=%1$s make up', $service['profile'])
                : null,
            ServiceReadiness::UNAVAILABLE => sprintf('Run `docker compose logs %1$s` to see why; `docker compose restart %1$s` brings it back.', $compose),
            ServiceReadiness::FAILED => 'ollama' === $id
                ? 'Run `docker compose logs ollama backend` to see why; the model download resumes on the next `make up`.'
                : sprintf('Run `docker compose logs %1$s` to see why; `docker compose restart %1$s` starts it again.', $compose),
            default => null,
        };
    }

    private function isComposeInstall(): bool
    {
        return '' === trim($this->kubernetesHost)
            && \in_array(strtolower(trim($this->platform)), self::COMPOSE_PLATFORMS, true);
    }
}
