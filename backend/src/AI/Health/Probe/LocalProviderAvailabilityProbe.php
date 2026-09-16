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
 * Their own `isAvailable()` is the free check they already ship. For these
 * two providers that method means "a server URL was set", not "the server
 * answers" — Triton constructs a gRPC stub without connecting, Piper only
 * looks at `SYNAPLAN_TTS_URL`. An empty URL is therefore a skip, the same
 * rule every cloud probe follows, so an install that never set
 * `TRITON_SERVER_URL` does not page operators about unused catalog rows
 * ("3 NVIDIA Triton model(s) failing").
 *
 * When a URL is set the probe reports {@see ProbeResult::reachable()}. That
 * must never be used as grounds for retiring a model: it is not a listing.
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
                if (!$provider->isAvailable()) {
                    return ProbeResult::skipped(sprintf(
                        '%s is not configured.',
                        $provider->getDisplayName()
                    ));
                }

                return ProbeResult::reachable(sprintf('%s is reachable.', $provider->getDisplayName()));
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
