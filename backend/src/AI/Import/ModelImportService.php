<?php

declare(strict_types=1);

namespace App\AI\Import;

use App\AI\Credential\OpenAiCompatibleEndpointRegistry;

/**
 * Orchestrates the model-import flow behind the admin API: discover an
 * endpoint's models, optionally probe capabilities, and apply the admin's
 * final selection. Discovery and applying stay in their own single-purpose
 * services; this only wires them and shapes the API payload.
 */
final readonly class ModelImportService
{
    /** Cap on how many rows a single probe request will hit (cost guard). */
    private const MAX_PROBE_ROWS = 50;

    public function __construct(
        private ModelDiscoveryService $discovery,
        private CapabilityProbe $probe,
        private ModelImportApplier $applier,
        private OpenAiCompatibleEndpointRegistry $endpoints,
    ) {
    }

    /**
     * @return array{source: string, rows: list<array<string, mixed>>, endpointOk: bool, error: string|null, probeCostNote: string|null}
     */
    public function preview(string $source, bool $probe): array
    {
        $result = $this->discovery->discover($source);
        if (!$result->ok) {
            return ['source' => $source, 'rows' => [], 'endpointOk' => false, 'error' => $result->error, 'probeCostNote' => null];
        }

        $models = $result->models;
        $probeCostNote = null;
        $endpoint = $this->probeEndpoint($source);

        if ($probe && null !== $endpoint) {
            $models = $this->applyProbe($models, $endpoint);
            $probeCostNote = 'Each probed model received two tiny requests (one chat, one embeddings).';
        }

        return [
            'source' => $source,
            'rows' => array_map(static fn (DiscoveredModel $m): array => $m->toArray(), $models),
            'endpointOk' => true,
            'error' => null,
            'probeCostNote' => $probeCostNote,
        ];
    }

    /**
     * @param list<array{providerId: string, name?: string, tags: list<string>}> $rows
     *
     * @return array{created: int, skipped: int, rows: list<array{providerId: string, tag: string, status: string}>}
     */
    public function apply(string $source, array $rows): array
    {
        return $this->applier->apply($source, $rows);
    }

    /**
     * @param list<DiscoveredModel>                                                    $models
     * @param array{base_url: string, api_key: string, headers: array<string, string>} $endpoint
     *
     * @return list<DiscoveredModel>
     */
    private function applyProbe(array $models, array $endpoint): array
    {
        $out = [];
        $probed = 0;
        foreach ($models as $model) {
            if ($probed >= self::MAX_PROBE_ROWS) {
                $out[] = $model->withProbe(ProbeResult::skipped()->toArray());
                continue;
            }
            $result = $this->probe->probe($endpoint, $model->providerId);
            ++$probed;
            $out[] = new DiscoveredModel(
                providerId: $model->providerId,
                name: $model->name,
                guessedTags: $result->overrideTags($model->guessedTags),
                exists: $model->exists,
                sizeBytes: $model->sizeBytes,
                family: $model->family,
                probe: $result->toArray(),
            );
        }

        return $out;
    }

    /**
     * The decrypted endpoint config for a probe, or null when the source is not
     * an OpenAI-compatible endpoint (native Ollama is never probed this way).
     *
     * @return array{base_url: string, api_key: string, headers: array<string, string>}|null
     */
    private function probeEndpoint(string $source): ?array
    {
        if (!str_starts_with($source, ModelDiscoveryService::OPENAI_COMPATIBLE_PREFIX)) {
            return null;
        }
        $name = substr($source, strlen(ModelDiscoveryService::OPENAI_COMPATIBLE_PREFIX));
        $endpoint = $this->endpoints->getEndpoint($name);
        if (null === $endpoint) {
            return null;
        }

        return ['base_url' => $endpoint['base_url'], 'api_key' => $endpoint['api_key'], 'headers' => $endpoint['headers']];
    }
}
