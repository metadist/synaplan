<?php

namespace App\Service\Message\Handler\Media;

use App\Entity\Message;
use App\Service\Message\Handler\ChatHandler;
use App\Service\Message\Handler\MessageHandlerInterface;
use App\Service\Message\ProgressCallback;
use Psr\Log\LoggerInterface;

/**
 * Answers a misclassified audio turn as ordinary chat: the sorter took a
 * content request ("teach me the Persian numbers with a children's song")
 * for speech. Without a chat handler the user is asked to rephrase.
 */
final readonly class MediaChatHandOff
{
    /** Marker stored on a turn that TtsScriptGuard handed to the chat answer. */
    public const REROUTED_FROM_AUDIO = 'mediamaker:audio';

    public function __construct(
        private LoggerInterface $logger,
        private ?ChatHandler $chatHandler = null,
    ) {
    }

    /**
     * Route a misclassified "audio" turn to the general chat answer.
     *
     * The mediamaker classification (topic, BMEDIA, model override, the
     * pre-resolved mediamaker prompt bundle) is replaced by a plain `general`
     * chat classification so the reply is written by the chat model with the
     * user's default prompt — the answer the sorter should have produced.
     *
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function answerAsChatInstead(
        Message $message,
        array $thread,
        array $classification,
        callable $streamCallback,
        ?callable $progressCallback,
        array $options,
        string $rejectedScript,
    ): array {
        $this->logger->warning('MediaGenerationHandler: audio script only repeats the user request, answering as chat instead', [
            'message_id' => $message->getId(),
            'topic' => $classification['topic'] ?? null,
            'script_preview' => mb_substr($rejectedScript, 0, 120),
        ]);

        if (null === $this->chatHandler) {
            $lang = $classification['language'] ?? 'en';
            $streamCallback('de' === $lang
                ? 'Ich habe in deiner Nachricht keinen Text gefunden, den ich vorlesen könnte. Formuliere die Anfrage bitte als normale Frage, oder gib den Text an, den ich sprechen soll (z. B. „Lies vor: …“).'
                : 'I could not find any text in your message to read aloud. Please ask the question as a normal chat message, or tell me the exact text to speak (e.g. "Read aloud: …").');

            // No `media_type` here on purpose: StreamController bills any
            // response carrying one as generated media, and nothing was made.
            return [
                'metadata' => [
                    'error' => 'tts_script_echoes_request',
                ],
            ];
        }

        $chatClassification = $classification;
        $chatClassification['topic'] = 'general';
        $chatClassification['intent'] = 'chat';
        $chatClassification['rerouted_from'] = self::REROUTED_FROM_AUDIO;
        unset(
            $chatClassification['media_type'],
            $chatClassification['input_mode'],
            $chatClassification['duration'],
            $chatClassification['resolution'],
            $chatClassification['model_id'],
            $chatClassification['prompt_metadata'],
        );

        $chatOptions = $options;
        unset($chatOptions['resolved_prompt_data']);

        ProgressCallback::notify($progressCallback, 'analyzing', 'Answering your request…');

        $result = $this->chatHandler->handleStream($message, $thread, $chatClassification, $streamCallback, $progressCallback, $chatOptions);
        $metadata = is_array($result['metadata'] ?? null) ? $result['metadata'] : [];
        $metadata['rerouted_from'] = self::REROUTED_FROM_AUDIO;
        // MessageProcessor folds this into the classification it hands back,
        // so the persisted topic / media meta and the voice-reply guard see
        // the chat answer that was actually produced, not the audio vote.
        $metadata[MessageHandlerInterface::EFFECTIVE_CLASSIFICATION_KEY] = [
            'topic' => 'general',
            'intent' => 'chat',
            'media_type' => null,
            'rerouted_from' => self::REROUTED_FROM_AUDIO,
        ];
        $result['metadata'] = $metadata;

        return $result;
    }
}
