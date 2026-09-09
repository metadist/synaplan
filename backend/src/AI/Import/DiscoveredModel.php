<?php

declare(strict_types=1);

namespace App\AI\Import;

/**
 * One model an endpoint (OpenAI-compatible gateway or Ollama) reports as
 * available, with a name-based tag guess and whether the catalog already has
 * it. Immutable — discovery describes what the endpoint offers; the applier
 * decides what to write.
 */
final readonly class DiscoveredModel
{
    /**
     * @param list<string>               $guessedTags one or more BMODELS.BTAG values
     * @param array<string, string>|null $probe       per-capability probe result, or null when not probed
     */
    public function __construct(
        public string $providerId,
        public string $name,
        public array $guessedTags,
        public bool $exists,
        public ?int $sizeBytes = null,
        public ?string $family = null,
        public ?array $probe = null,
    ) {
    }

    public function withProbe(?array $probe): self
    {
        return new self(
            $this->providerId,
            $this->name,
            $this->guessedTags,
            $this->exists,
            $this->sizeBytes,
            $this->family,
            $probe,
        );
    }

    /**
     * @return array{providerId: string, name: string, guessedTags: list<string>, exists: bool, sizeBytes: int|null, family: string|null, probe: array<string, string>|null}
     */
    public function toArray(): array
    {
        return [
            'providerId' => $this->providerId,
            'name' => $this->name,
            'guessedTags' => $this->guessedTags,
            'exists' => $this->exists,
            'sizeBytes' => $this->sizeBytes,
            'family' => $this->family,
            'probe' => $this->probe,
        ];
    }
}
