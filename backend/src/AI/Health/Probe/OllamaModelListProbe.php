<?php

declare(strict_types=1);

namespace App\AI\Health\Probe;

use App\AI\Health\FailureKind;
use App\AI\Provider\OllamaProvider;
use App\AI\Service\ModelProbeResult;
use App\AI\Service\ProviderRegistry;

/**
 * Which models are actually pulled on the local Ollama server.
 *
 * The most useful probe of the lot for a self-hosted install: a reachable
 * Ollama with nothing pulled is the single most common reason chat "does not
 * work", and it is invisible to every other check because the server itself
 * answers perfectly well.
 */
final readonly class OllamaModelListProbe implements ModelListProbeInterface
{
    public function __construct(
        private ProviderRegistry $registry,
        private string $baseUrl = '',
    ) {
    }

    public function supports(string $service): bool
    {
        return 'ollama' === mb_strtolower($service);
    }

    public function probe(string $service): ProbeResult
    {
        $provider = $this->ollamaProvider();
        if (null === $provider || '' === trim($this->baseUrl)) {
            return ProbeResult::skipped('Ollama is not configured.');
        }

        try {
            // getAvailableModels() returns [] both for "server down" and for
            // "nothing pulled", which are very different verdicts — so the
            // reachability question is asked separately.
            if (!$provider->isAvailable()) {
                return ProbeResult::failed(FailureKind::Transient, 'Ollama server is not reachable.');
            }

            $models = $provider->getAvailableModels();
        } catch (\Throwable $e) {
            return ProbeResult::failed(FailureKind::Transient, 'Ollama server not reachable: '.$e->getMessage());
        }

        $ids = [];
        foreach ($models as $model) {
            if (is_string($model) && '' !== trim($model)) {
                $ids[] = mb_strtolower(trim($model));
            }
        }

        // Authoritative: Ollama keeps every pulled model in one namespace, so
        // "not in this list" means "not on the server" for chat, embedding and
        // everything else alike. An empty list is therefore a real verdict — a
        // reachable Ollama with nothing pulled is exactly what the user hits.
        return ProbeResult::ok($ids, listingAuthoritative: true);
    }

    /**
     * Never reached: the listing above already settles every model, so the
     * evaluator has no missing model left to confirm.
     */
    public function confirm(string $service, string $providerModelId): ModelProbeResult
    {
        return ModelProbeResult::Inconclusive;
    }

    private function ollamaProvider(): ?OllamaProvider
    {
        foreach ($this->registry->getUniqueProviders() as $provider) {
            if ($provider instanceof OllamaProvider) {
                return $provider;
            }
        }

        return null;
    }
}
