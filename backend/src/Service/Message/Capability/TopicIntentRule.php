<?php

declare(strict_types=1);

namespace App\Service\Message\Capability;

use App\Service\Multitask\Plan\Capability;

/**
 * Which topic and intent a message gets when the user picked a model: a
 * model override in MessageClassifier and an Again both ask. Their answers
 * still differ, so each keeps its own method until they agree.
 */
final readonly class TopicIntentRule
{
    /** The model override's tag table; any other tag keeps the message's topic. */
    private const MODEL_OVERRIDE_TOPICS = [
        'text2pic' => 'mediamaker',
        'text2vid' => 'mediamaker',
        'text2sound' => 'mediamaker',
        'pic2text' => 'general', // Vision is handled by ChatHandler
        'analyze' => 'general',
        'chat' => 'general',
        'vectorize' => 'general',
    ];

    /** Spellings that route like a capability without being one (tool commands, model-tag topics). */
    private const INTENT_ALIASES = [
        'text2pic' => Capability::ImageGeneration->value,
        'text2vid' => Capability::ImageGeneration->value,
        'text2sound' => Capability::ImageGeneration->value,
        'tools:pic' => Capability::ImageGeneration->value, // /pic command
        'tools:vid' => Capability::ImageGeneration->value, // /vid command
        'tools:tts' => Capability::ImageGeneration->value, // /tts command (audio via MediaGenerationHandler)
        'pic2text' => Capability::FileAnalysis->value,
        'analyze' => Capability::FileAnalysis->value,
        'analyzefile' => Capability::FileAnalysis->value,
        'chat' => Capability::Chat->value,
    ];

    public function __construct(
        private SystemCapabilityRegistry $capabilities,
    ) {
    }

    /**
     * A model override in the classifier: media tags make media, vision and
     * chat tags a chat answer; any other tag keeps the message's topic, else
     * `general`.
     */
    public function forModelOverride(string $modelTag, ?string $messageTopic): TopicIntent
    {
        $topic = self::MODEL_OVERRIDE_TOPICS[$modelTag] ?? ($messageTopic ?: 'general');

        return new TopicIntent($topic, $this->intentFor($topic));
    }

    /**
     * Again: the stored topic routes the retry (`chat` when it has none), and
     * the picked model's tag re-aims it when the tag names a kind of answer.
     */
    public function forAgain(?string $modelTag, string $messageTopic): TopicIntent
    {
        $topic = strtolower($messageTopic);
        if ('' === $topic || 'unknown' === $topic) {
            $topic = 'chat';
        }
        if (null !== $modelTag && '' !== $modelTag) {
            $topic = match (strtolower($modelTag)) {
                'text2pic', 'text2vid', 'text2sound' => 'mediamaker',
                'pic2text', 'analyze', 'vision' => 'general',
                'document', 'officemaker', 'text2doc' => 'officemaker',
                default => $topic,
            };
        }

        $intent = match ($topic) {
            'mediamaker', 'text2pic', 'text2vid', 'text2sound' => Capability::ImageGeneration->value,
            'pic2text', 'analyze', 'analyzefile' => Capability::FileAnalysis->value,
            'officemaker' => Capability::DocumentGeneration->value,
            default => Capability::Chat->value,
        };

        return new TopicIntent($topic, $intent);
    }

    /**
     * The intent a topic routes to: the system topics from the capability
     * registry, then the routing aliases. Any other topic is a user's task
     * prompt and answers as chat; an intent that is not a message-router
     * capability fails later in InferenceRouter instead.
     */
    public function intentFor(string $topic): string
    {
        return $this->capabilities->topicToIntentMap()[$topic]
            ?? self::INTENT_ALIASES[$topic]
            ?? Capability::Chat->value;
    }
}
