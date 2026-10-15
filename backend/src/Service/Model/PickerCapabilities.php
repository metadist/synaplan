<?php

declare(strict_types=1);

namespace App\Service\Model;

/**
 * The capabilities a default model can be bound to on the AI Models page, and
 * the pickers a model row is offered in.
 *
 * A model's tag decides its pickers. Chat models also serve sorting and
 * analysis; a model with an unknown tag is offered in every picker. The
 * desktop client uses a narrower mapping, see {@see CapabilityCatalog::groupsForTag()}.
 */
final class PickerCapabilities
{
    /** @var list<string> */
    public const ALL = ['SORT', 'CHAT', 'MEM', 'VECTORIZE', 'PIC2TEXT', 'TEXT2PIC', 'PIC2PIC', 'TEXT2VID', 'IMG2VID', 'SOUND2TEXT', 'TEXT2SOUND', 'ANALYZE', 'VOICE_LIVE'];

    private function __construct()
    {
    }

    /**
     * Rows grouped by picker, each picker in row order. Every capability key
     * is present, empty or not.
     *
     * @param list<array<string, mixed>> $rows each with an upper-case `tag` and a `features` list
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function group(array $rows): array
    {
        $grouped = array_fill_keys(self::ALL, []);
        foreach ($rows as $row) {
            $features = is_array($row['features'] ?? null) ? $row['features'] : [];
            foreach (self::forTag((string) $row['tag'], $features) as $capability) {
                $grouped[$capability][] = $row;
            }
        }

        return $grouped;
    }

    /**
     * @param array<mixed> $features
     *
     * @return list<string>
     */
    public static function forTag(string $tag, array $features = []): array
    {
        return match ($tag) {
            // Not MEM: memory extraction keeps a small curated set (tag `mem`)
            // so it stays fast and predictable. An operator who wants a chat
            // model there clones it with that tag.
            'CHAT' => ['CHAT', 'SORT', 'ANALYZE'],
            'MEM' => ['MEM'],
            'VECTORIZE', 'EMBEDDING' => ['VECTORIZE'],
            'VISION', 'PIC2TEXT' => ['PIC2TEXT'],
            'IMAGE', 'TEXT2PIC' => in_array('pic2pic', $features, true) ? ['TEXT2PIC', 'PIC2PIC'] : ['TEXT2PIC'],
            // Image-to-video models share the text2vid tag but need a reference
            // image. Offered for text-to-video, every text prompt would fail at
            // the provider with "'image_url' is a required property".
            'VIDEO', 'TEXT2VID' => in_array('image2video', $features, true) ? ['IMG2VID'] : ['TEXT2VID'],
            'AUDIO', 'SOUND2TEXT', 'TRANSCRIPTION' => ['SOUND2TEXT'],
            'TTS', 'TEXT2SOUND' => ['TEXT2SOUND'],
            'VOICE2VOICE' => ['VOICE_LIVE'],
            default => self::ALL,
        };
    }
}
