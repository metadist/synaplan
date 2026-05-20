<?php

declare(strict_types=1);

namespace App\Prompt;

/**
 * Which prompt topics participate in Synapse / AI-sort routing vs. handler-only keys.
 *
 * Granular topics (`general-chat`, `image-generation`, …) drive routing.
 * Canonical legacy keys (`general`, `mediamaker`) stay for widgets, BTOPIC
 * history and downstream handlers but are excluded from the routing pool.
 */
final class RoutingTopicPolicy
{
    /** @var list<string> */
    public const ROUTING_EXCLUDED_CANONICAL = ['general', 'mediamaker'];

    /** @var array<string, string> canonical handler key → primary editable prompt row */
    private const PRIMARY_PROMPT_BY_CANONICAL = [
        'general' => 'general-chat',
    ];

    public static function isRoutingExcluded(string $topic): bool
    {
        return in_array(strtolower(trim($topic)), self::ROUTING_EXCLUDED_CANONICAL, true);
    }

    /**
     * Topics to try when loading prompt content for a routed message.
     *
     * @return list<string>
     */
    public static function promptLookupTopics(
        string $canonicalTopic,
        ?string $granularTopic = null,
        ?string $mediaType = null,
    ): array {
        $candidates = [];

        if (null !== $granularTopic && '' !== trim($granularTopic) && !self::isRoutingExcluded($granularTopic)) {
            $candidates[] = strtolower(trim($granularTopic));
        }

        if ('mediamaker' === strtolower(trim($canonicalTopic))) {
            $candidates[] = match (strtolower(trim((string) $mediaType))) {
                'video' => 'video-generation',
                'audio' => 'audio-generation',
                default => 'image-generation',
            };
        }

        $primary = self::PRIMARY_PROMPT_BY_CANONICAL[strtolower(trim($canonicalTopic))] ?? null;
        if (null !== $primary) {
            $candidates[] = $primary;
        }

        $candidates[] = strtolower(trim($canonicalTopic));

        return array_values(array_unique($candidates));
    }
}
