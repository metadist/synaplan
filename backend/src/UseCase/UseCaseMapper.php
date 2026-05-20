<?php

declare(strict_types=1);

namespace App\UseCase;

/**
 * Bridge between stable use case IDs and legacy BTOPIC / handler routing keys.
 *
 * Single source of truth on the backend — keep in sync with
 * `frontend/src/utils/routingDryRunPreview.ts`.
 */
final class UseCaseMapper
{
    private const DEFAULT_USE_CASE = 'text_chat';

    /** @var array<string, string> */
    private const TOPIC_TO_USE_CASE = [
        'general' => 'text_chat',
        'chat' => 'text_chat',
        'general-chat' => 'text_chat',
        'coding' => 'text_chat',
        'mediamaker' => 'media_generation',
        'text2pic' => 'media_generation',
        'text2vid' => 'media_generation',
        'text2sound' => 'media_generation',
        'image-generation' => 'media_generation',
        'video-generation' => 'media_generation',
        'audio-generation' => 'media_generation',
        'tools:pic' => 'media_generation',
        'tools:vid' => 'media_generation',
        'tools:tts' => 'media_generation',
        'officemaker' => 'file_generation',
        'text2doc' => 'file_generation',
        'analyzefile' => 'file_analytics',
        'analyze' => 'file_analytics',
        'pic2text' => 'file_analytics',
        'docsummary' => 'file_analytics',
        'tools:filesort' => 'file_analytics',
    ];

    /** @var array<string, string> */
    private const USE_CASE_TO_LEGACY_TOPIC = [
        'text_chat' => 'general',
        'media_generation' => 'mediamaker',
        'file_generation' => 'officemaker',
        'file_analytics' => 'analyzefile',
        'comm_send_email' => 'general',
        'comm_receive_email' => 'general',
    ];

    public function topicToUseCaseId(string $topic, ?string $granularTopic = null): string
    {
        $topic = strtolower(trim($topic));
        $granularTopic = null !== $granularTopic ? strtolower(trim($granularTopic)) : null;

        if (isset(self::TOPIC_TO_USE_CASE[$topic])) {
            return self::TOPIC_TO_USE_CASE[$topic];
        }

        if (null !== $granularTopic && isset(self::TOPIC_TO_USE_CASE[$granularTopic])) {
            return self::TOPIC_TO_USE_CASE[$granularTopic];
        }

        return self::DEFAULT_USE_CASE;
    }

    /**
     * Map a catalogue use case to the legacy handler topic.
     *
     * Media subtype hints are applied only for `media_generation`.
     */
    public function useCaseToLegacyTopic(string $useCaseId, ?string $mediaType = null): string
    {
        if ('media_generation' === $useCaseId && null !== $mediaType) {
            return 'mediamaker';
        }

        return self::USE_CASE_TO_LEGACY_TOPIC[$useCaseId] ?? self::USE_CASE_TO_LEGACY_TOPIC[self::DEFAULT_USE_CASE];
    }

    /**
     * Attach `primary_use_case_id` when the router result does not already carry it.
     *
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    public function attachPrimaryUseCaseId(array $result): array
    {
        if (!empty($result['primary_use_case_id']) && is_string($result['primary_use_case_id'])) {
            return $result;
        }

        $topic = (string) ($result['topic'] ?? 'general');
        $granular = isset($result['granular_topic']) ? (string) $result['granular_topic'] : null;

        $result['primary_use_case_id'] = $this->topicToUseCaseId($topic, $granular);

        return $result;
    }
}
