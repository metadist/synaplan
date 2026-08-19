<?php

declare(strict_types=1);

namespace App\AI\Health\Probe;

/**
 * Finds the probe responsible for a BMODELS service name.
 *
 * Providers with no probe at all (Higgsfield, TheHive, the `test` fixtures)
 * are not a gap: they have no free catalog endpoint, and inventing one out of
 * paid inference calls is exactly what this design refuses to do. They are
 * covered by the passive traffic counters instead.
 */
final readonly class ModelListProbeRegistry
{
    /**
     * @param iterable<ModelListProbeInterface> $probes injected via the `app.model_list_probe` tag
     */
    public function __construct(private iterable $probes)
    {
    }

    public function find(string $service): ?ModelListProbeInterface
    {
        foreach ($this->probes as $probe) {
            if ($probe->supports($service)) {
                return $probe;
            }
        }

        return null;
    }

    public function has(string $service): bool
    {
        return null !== $this->find($service);
    }
}
