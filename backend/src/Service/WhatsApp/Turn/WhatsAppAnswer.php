<?php

namespace App\Service\WhatsApp\Turn;

/**
 * The AI's answer to one WhatsApp message, composed once for every way it is
 * sent: the stored text, the text the phone shows, the same text with its web
 * sources, and the model's metadata with the recorded usage.
 */
final readonly class WhatsAppAnswer
{
    /**
     * @param string                    $text            stored on the reply, [Doc:slug] tags kept for the web chat
     * @param string                    $channelText     what the phone shows: media tags rendered, doc tags as links
     * @param string                    $textWithSources the channel text with its web sources, for a text reply
     * @param array<string, mixed>      $metadata        the model's metadata with the recorded usage
     * @param array<string, mixed>|null $classification
     * @param array<string, mixed>|null $searchResults
     */
    public function __construct(
        public string $text,
        public string $channelText,
        public string $textWithSources,
        public array $metadata,
        public ?array $classification,
        public ?array $searchResults,
    ) {
    }

    /**
     * The generated file the answer carries, as the processor reported it.
     */
    public function file(): mixed
    {
        return $this->metadata['file'] ?? null;
    }
}
