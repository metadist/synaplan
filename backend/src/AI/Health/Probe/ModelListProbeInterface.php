<?php

declare(strict_types=1);

namespace App\AI\Health\Probe;

use App\AI\Service\ModelProbeResult;

/**
 * Asks a provider which models it currently offers.
 *
 * Every implementation MUST use a free metadata endpoint. Inference calls are
 * out of bounds here — pinging text-to-video models on a schedule would cost
 * more per month than the rest of the platform put together, and the whole
 * point of this layer is that outage detection is free.
 *
 * Implementations are collected by {@see ModelListProbeRegistry} via the
 * `app.model_list_probe` tag.
 */
interface ModelListProbeInterface
{
    /** Service name as stored in BMODELS.BSERVICE, compared case-insensitively. */
    public function supports(string $service): bool;

    public function probe(string $service): ProbeResult;

    /**
     * Ask the provider about one specific model id.
     *
     * Called only for models missing from a listing that is not authoritative,
     * and it is what turns "absent from a list" into "the provider says this is
     * gone". Implementations MUST return {@see ModelProbeResult::Inconclusive}
     * for anything they cannot answer conclusively; a wrong Gone retires a
     * working model for every user of the install.
     */
    public function confirm(string $service, string $providerModelId): ModelProbeResult;
}
