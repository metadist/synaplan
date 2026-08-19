<?php

declare(strict_types=1);

namespace App\AI\Health\Probe;

use App\AI\Health\FailureKind;
use App\AI\Service\ModelProbeResult;
use App\AI\Service\ProviderRegistry;

/**
 * Reachability check for self-hosted providers that have no catalog endpoint
 * (Triton over gRPC, Piper TTS).
 *
 * Their own `isAvailable()` is the free check they already ship, so this probe
 * can say "the service is up" without ever running inference. It cannot say
 * anything about individual models, hence {@see ProbeResult::reachable()} — a
 * reachable service must never be used as grounds for retiring a model.
 */
final readonly class LocalProviderAvailabilityProbe implements ModelListProbeInterface
{
    private const SERVICES = ['triton', 'piper'];

    public function __construct(private ProviderRegistry $registry)
    {
    }

    public function supports(string $service): bool
    {
        return in_array(mb_strtolower($service), self::SERVICES, true);
    }

    public function probe(string $service): ProbeResult
    {
        $name = mb_strtolower($service);

        foreach ($this->registry->getUniqueProviders() as $provider) {
            if (mb_strtolower($provider->getName()) !== $name) {
                continue;
            }

            try {
                return $provider->isAvailable()
                    ? ProbeResult::reachable(sprintf('%s is reachable.', $provider->getDisplayName()))
                    : ProbeResult::failed(FailureKind::Transient, sprintf('%s is not reachable.', $provider->getDisplayName()));
            } catch (\Throwable $e) {
                return ProbeResult::failed(FailureKind::Transient, sprintf('%s check failed: %s', $service, $e->getMessage()));
            }
        }

        return ProbeResult::skipped(sprintf('"%s" is not registered in this installation.', $service));
    }

    /**
     * A reachability check never produces a listing, so no model is ever
     * missing from one and nothing here can be confirmed retired.
     */
    public function confirm(string $service, string $providerModelId): ModelProbeResult
    {
        return ModelProbeResult::Inconclusive;
    }
}
