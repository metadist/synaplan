<?php

declare(strict_types=1);

namespace App\Service\Research\Eval;

/**
 * Records a search and its page text so a later arm sees the same web.
 *
 * @phpstan-type FrozenPayload array{search: array<string, mixed>|null, knowledge: string}
 */
final readonly class FrozenStore
{
    public function __construct(
        private string $directory,
    ) {
    }

    /**
     * @return FrozenPayload|null
     */
    public function get(string $itemId): ?array
    {
        $path = $this->path($itemId);
        if (!is_file($path)) {
            return null;
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!\is_array($decoded)) {
            return null;
        }

        $search = $decoded['search'] ?? null;

        return [
            'search' => \is_array($search) ? $search : null,
            'knowledge' => \is_string($decoded['knowledge'] ?? null) ? $decoded['knowledge'] : '',
        ];
    }

    /**
     * @param FrozenPayload $payload
     */
    public function put(string $itemId, array $payload): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Could not create the frozen directory.');
        }
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (false === file_put_contents($this->path($itemId), $json."\n")) {
            throw new \RuntimeException('Could not write the frozen record for '.$itemId.'.');
        }
    }

    private function path(string $itemId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $itemId) ?? '';
        if ('' === $safe) {
            throw new \RuntimeException('A frozen item needs an id.');
        }

        return $this->directory.'/'.$safe.'.json';
    }
}
