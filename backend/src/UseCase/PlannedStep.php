<?php

declare(strict_types=1);

namespace App\UseCase;

/**
 * A single step within a multi-step execution plan.
 *
 * Each step maps to exactly one capability (Chat, ImageGeneration, etc.)
 * and may carry additional flags (web_search, tts, specific model overrides).
 */
final readonly class PlannedStep
{
    /**
     * @param string      $id         Unique step identifier (e.g. "step_1")
     * @param string      $capability Canonical capability key (CHAT, IMAGE_GENERATION, etc.)
     * @param bool        $webSearch  Whether this step needs web search enrichment
     * @param string|null $mediaType  Media type hint (image, video, audio) for generation steps
     * @param array       $metadata   Additional step-specific config (model overrides, params)
     */
    public function __construct(
        public string $id,
        public string $capability,
        public bool $webSearch = false,
        public ?string $mediaType = null,
        public array $metadata = [],
    ) {
    }

    /**
     * Map a capability key to the canonical topic used by the handler system.
     */
    public function toTopic(): string
    {
        return match ($this->capability) {
            'CHAT' => 'general',
            'IMAGE_GENERATION' => 'mediamaker',
            'VIDEO_GENERATION' => 'mediamaker',
            'AUDIO_GENERATION' => 'mediamaker',
            'FILE_GENERATION' => 'officemaker',
            'FILE_ANALYSIS' => 'analyzefile',
            'EMAIL_SEND' => 'general',
            'CALENDAR_CREATE' => 'general',
            'WEB_SEARCH' => 'general',
            'SUMMARIZE' => 'general',
            default => 'general',
        };
    }

    /**
     * Create from an external router response step array.
     */
    public static function fromRouterResponse(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? 'step_'.random_int(1000, 9999)),
            capability: strtoupper((string) ($data['capability'] ?? 'CHAT')),
            webSearch: (bool) ($data['web_search'] ?? false),
            mediaType: isset($data['media_type']) ? (string) $data['media_type'] : null,
            metadata: (array) ($data['metadata'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'capability' => $this->capability,
            'web_search' => $this->webSearch,
            'media_type' => $this->mediaType,
            'metadata' => $this->metadata,
        ];
    }
}
