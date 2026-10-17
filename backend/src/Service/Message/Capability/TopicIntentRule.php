<?php

declare(strict_types=1);

namespace App\Service\Message\Capability;

use App\Service\Multitask\Plan\Capability;

/**
 * Which topic and intent a message gets when the user picked a model: a
 * model override in MessageClassifier and an Again both ask. An Again
 * follows forModel(); the model override keeps its own tag table until it
 * agrees.
 */
final readonly class TopicIntentRule
{
    /** The kind of answer a model tag names; any other tag keeps the message's topic. */
    private const TOPIC_FOR_TAG = [
        'text2pic' => 'mediamaker',
        'text2vid' => 'mediamaker',
        'text2sound' => 'mediamaker',
        'pic2text' => 'general', // Vision is handled by ChatHandler
        'analyze' => 'general',
        'vision' => 'general',
        'document' => 'officemaker',
        'officemaker' => 'officemaker',
        'text2doc' => 'officemaker',
    ];

    /** The model override's tag table; any other tag keeps the message's topic. */
    private const MODEL_OVERRIDE_TOPICS = [
        'text2pic' => 'mediamaker',
        'text2vid' => 'mediamaker',
        'text2sound' => 'mediamaker',
        'pic2text' => 'general', // Vision is handled by ChatHandler
        'analyze' => 'general',
        'vision' => 'general',
        'document' => 'officemaker',
        'officemaker' => 'officemaker',
        'text2doc' => 'officemaker',
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
     * A model override in the classifier: media tags make media, analysis
     * tags a chat answer, document tags a document; any other tag (a chat
     * model among them) keeps the message's topic, else `general`.
     */
    public function forModelOverride(string $modelTag, ?string $messageTopic): TopicIntent
    {
        $topic = self::MODEL_OVERRIDE_TOPICS[$modelTag] ?? self::topicOrGeneral($messageTopic);

        return new TopicIntent($topic, $this->intentFor($topic));
    }

    /**
     * The tag names the kind of answer when it can: media, a chat answer for
     * vision, a document. Any other tag (a chat model, no tag) keeps the
     * topic the message has, so a custom prompt survives; without one the
     * message goes to `general`.
     */
    public function forModel(?string $modelTag, ?string $messageTopic): TopicIntent
    {
        $topic = self::TOPIC_FOR_TAG[strtolower((string) $modelTag)] ?? self::topicOrGeneral($messageTopic);

        return new TopicIntent($topic, $this->intentFor($topic));
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

    /** A new message carries `UNKNOWN`; it, `unknown` and an empty topic count as none. */
    private static function topicOrGeneral(?string $topic): string
    {
        return null === $topic || '' === $topic || 'unknown' === strtolower($topic) ? 'general' : $topic;
    }
}
