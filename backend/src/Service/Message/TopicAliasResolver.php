<?php

declare(strict_types=1);

namespace App\Service\Message;

/**
 * Maps fine-grained alias topic names to the canonical legacy topics that
 * downstream handlers (MessageClassifier, file group keys, intent mapping)
 * understand.
 *
 * Some older AI-sorter outputs / legacy data may still carry granular topic
 * names (`coding`, `general-chat`, `image-generation`, `video-generation`,
 * `audio-generation`); downstream handlers key off the canonical topics
 * (`general`, `mediamaker`). This resolver runs at the end of classification:
 *   - a granular topic e.g. `coding` ─► resolves to `general`
 *   - `mediamaker` / `general` ─► pass through
 *   - resolution also returns the implied media context (BMEDIA) for
 *     `image|video|audio-generation` so callers don't reapply heuristics.
 *
 * Kept as the single source of truth for the alias contract.
 */
final class TopicAliasResolver
{
    /**
     * Granular topic ─► canonical legacy topic.
     *
     * Topics not listed here are passed through untouched.
     */
    private const TOPIC_ALIASES = [
        'general-chat' => 'general',
        'coding' => 'general',
        'image-generation' => 'mediamaker',
        'video-generation' => 'mediamaker',
        'audio-generation' => 'mediamaker',
    ];

    /**
     * Granular media-generation topics ─► implied BMEDIA value.
     */
    private const MEDIA_FROM_TOPIC = [
        'image-generation' => 'image',
        'video-generation' => 'video',
        'audio-generation' => 'audio',
    ];

    /**
     * Resolve a topic produced by AI-sorter classification to the canonical
     * topic + implied media type.
     *
     * @return array{topic: string, media: ?string, alias_source: ?string}
     *                                                                     `alias_source` carries the original granular topic when
     *                                                                     an alias was applied; null otherwise
     */
    public function resolve(string $topic): array
    {
        $canonical = self::TOPIC_ALIASES[$topic] ?? $topic;
        $media = self::MEDIA_FROM_TOPIC[$topic] ?? null;

        return [
            'topic' => $canonical,
            'media' => $media,
            'alias_source' => $canonical === $topic ? null : $topic,
        ];
    }

    /**
     * Return the list of granular topic names that resolve to a given canonical topic.
     *
     * @return list<string>
     */
    public function aliasesFor(string $canonicalTopic): array
    {
        $aliases = [];
        foreach (self::TOPIC_ALIASES as $alias => $target) {
            if ($target === $canonicalTopic) {
                $aliases[] = $alias;
            }
        }

        return $aliases;
    }

    /**
     * True when the topic is a known granular alias (not a canonical legacy topic).
     */
    public function isAlias(string $topic): bool
    {
        return isset(self::TOPIC_ALIASES[$topic]);
    }

    /**
     * Return the full alias map for diagnostics/admin endpoints.
     *
     * @return array<string, string>
     */
    public function getAliasMap(): array
    {
        return self::TOPIC_ALIASES;
    }
}
