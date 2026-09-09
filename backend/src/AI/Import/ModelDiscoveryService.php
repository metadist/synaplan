<?php

declare(strict_types=1);

namespace App\AI\Import;

use App\AI\Credential\OpenAiCompatibleEndpointRegistry;
use App\AI\Service\OllamaModelInventory;
use App\Repository\ModelRepository;

/**
 * Lists the models an import source offers and guesses each one's capability.
 *
 * A source is either `openai_compatible:<endpointName>` (uses the endpoint's
 * `/models` listing) or `ollama` (uses `/api/tags`). The result carries an
 * `ok` flag so an unreachable endpoint is never confused with one that lists
 * nothing — the import applier and the scheduled re-check depend on that.
 */
final readonly class ModelDiscoveryService implements ModelDiscovererInterface
{
    public const SOURCE_OLLAMA = 'ollama';
    public const OPENAI_COMPATIBLE_PREFIX = 'openai_compatible:';

    public function __construct(
        private OpenAiCompatibleEndpointRegistry $endpoints,
        private OllamaModelInventory $ollama,
        private ModelRepository $models,
        private ModelTagGuesser $guesser,
    ) {
    }

    public function discover(string $source): DiscoveryResult
    {
        $source = trim($source);

        if (str_starts_with($source, self::OPENAI_COMPATIBLE_PREFIX)) {
            return $this->discoverOpenAiCompatible(substr($source, strlen(self::OPENAI_COMPATIBLE_PREFIX)));
        }

        if (self::SOURCE_OLLAMA === $source) {
            return $this->discoverOllama();
        }

        throw new UnknownImportSourceException('Unknown import source: '.$source);
    }

    private function discoverOpenAiCompatible(string $endpointName): DiscoveryResult
    {
        $endpointName = strtolower(trim($endpointName));
        if ('' === $endpointName || null === $this->endpoints->getEndpoint($endpointName)) {
            throw new UnknownImportSourceException('Unknown OpenAI-compatible endpoint: '.$endpointName);
        }

        $listing = $this->endpoints->listModelIds($endpointName);
        if (!$listing['ok']) {
            return DiscoveryResult::unreachable($listing['error'] ?? 'Endpoint unreachable');
        }

        $existing = $this->existingProviderIds(OpenAiCompatibleEndpointRegistry::SERVICE);
        $models = [];
        foreach ($listing['ids'] as $id) {
            $models[] = new DiscoveredModel(
                providerId: $id,
                name: $this->nameFromId($id),
                guessedTags: $this->guesser->guess($id),
                exists: isset($existing[$id]),
            );
        }

        return DiscoveryResult::listed($models);
    }

    private function discoverOllama(): DiscoveryResult
    {
        $listing = $this->ollama->listPulled();
        if (!$listing['ok']) {
            return DiscoveryResult::unreachable('Ollama server is unreachable');
        }

        $existing = $this->existingProviderIds('ollama');
        $models = [];
        foreach ($listing['models'] as $row) {
            $name = $row['name'];
            $models[] = new DiscoveredModel(
                providerId: $name,
                name: $this->nameFromId($name),
                guessedTags: $this->guesser->guess($name),
                exists: isset($existing[$name]),
                sizeBytes: $row['size'] > 0 ? $row['size'] : null,
                family: '' !== $row['family'] ? $row['family'] : null,
            );
        }

        return DiscoveryResult::listed($models);
    }

    /**
     * @return array<string, true> providerIds already in the catalog for this service
     */
    private function existingProviderIds(string $service): array
    {
        $out = [];
        foreach ($this->models->findByServiceIndexedByProviderId($service) as $providerId => $_model) {
            $out[(string) $providerId] = true;
        }

        return $out;
    }

    /**
     * A readable display name from a model id: strip any path prefix and a
     * trailing `:latest`, turn separators into spaces, cap at the BNAME length.
     */
    private function nameFromId(string $id): string
    {
        $base = str_contains($id, '/') ? substr((string) strrchr($id, '/'), 1) : $id;
        $base = (string) preg_replace('/:latest$/', '', $base);
        $base = str_replace(['-', '_'], ' ', $base);
        $base = trim((string) preg_replace('/\s+/', ' ', $base));

        return mb_substr('' === $base ? $id : $base, 0, 48);
    }
}
