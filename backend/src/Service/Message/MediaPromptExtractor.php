<?php

namespace App\Service\Message;

use App\Entity\Message;
use App\Service\Message\Handler\ChatHandler;
use Psr\Log\LoggerInterface;

/**
 * Extracts structured media prompts (image/video/audio) via AI.
 *
 * Delegates all prompt understanding to the existing ChatHandler with the
 * configured "mediamaker" prompt so we never try to derive the text ourselves.
 */
class MediaPromptExtractor
{
    private const AUDIO_EXTRACTION_TOPIC = 'tools:mediamaker_audio_extract';

    public function __construct(
        private ChatHandler $chatHandler,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param Message $message        Current user message
     * @param array   $thread         Conversation history
     * @param array   $classification Classification result (topic, language, etc.)
     *
     * @return array{
     *     prompt: string,
     *     media_type: ?string,
     *     raw: string
     * }
     */
    public function extract(Message $message, array $thread, array $classification): array
    {
        $overridePrompt = $message->getMeta('media_prompt_override');
        if ($overridePrompt) {
            $overrideType = $this->normalizeMediaType($message->getMeta('media_type'));

            return [
                'prompt' => $overridePrompt,
                'media_type' => $overrideType,
                'raw' => $overridePrompt,
            ];
        }

        // First, check if media_type was already determined by the sorter
        // This is the preferred source as it avoids relying on JSON parsing from AI
        $mediaTypeFromSorter = $classification['media_type'] ?? null;

        $rawContent = '';

        try {
            $rawContent = $this->runPrompt($message, $thread, $classification, 'mediamaker');
        } catch (\Throwable $e) {
            $this->logger->warning('MediaPromptExtractor: ChatHandler extraction failed, using fallback', [
                'error' => $e->getMessage(),
                'message_id' => $message->getId(),
            ]);

            $fallbackMediaType = $mediaTypeFromSorter ?? $this->inferMediaTypeFromMessageText($message->getText());

            return [
                'prompt' => $message->getText(),
                'media_type' => $fallbackMediaType,
                'raw' => '',
            ];
        }

        $normalized = $this->normalizeContent($rawContent);
        $decoded = $this->decodeJson($normalized);

        $prompt = '';
        $mediaType = null;
        $mediaTypeFromJson = null;

        if (is_array($decoded)) {
            // AI returned JSON - extract prompt and optionally media type
            $prompt = trim((string) ($decoded['BTEXT'] ?? $decoded['prompt'] ?? $decoded['text'] ?? ''));
            $mediaTypeFromJson = $this->normalizeMediaType($decoded['BMEDIA'] ?? $decoded['media'] ?? null);
            $mediaType = $mediaTypeFromJson;
        }

        // Use media type from sorter as primary source (more reliable)
        // Only fall back to JSON-parsed media type if sorter didn't provide one
        if (null !== $mediaTypeFromSorter) {
            $mediaType = $mediaTypeFromSorter;
            $this->logger->info('MediaPromptExtractor: Using media_type from sorter', [
                'media_type' => $mediaType,
            ]);
        } elseif (null === $mediaType) {
            $inferredMediaType = $this->inferMediaTypeFromMessageText($message->getText());
            if (null !== $inferredMediaType) {
                $mediaType = $inferredMediaType;
                $this->logger->info('MediaPromptExtractor: Inferred media_type from message text', [
                    'media_type' => $mediaType,
                ]);
            }
        }

        $usingJson = is_array($decoded);

        if ('' === $prompt) {
            // AI returned plain text (not JSON) - use as prompt directly
            $prompt = '' !== $normalized ? $normalized : $message->getText();
        }

        // Only force audio extraction if:
        // 1. We didn't get JSON (so mediamaker prompt didn't follow format)
        // 2. Media type is explicitly audio OR message clearly indicates audio
        // 3. Media type is not already set to something else (image/video)
        if ($this->shouldForceAudioExtraction($mediaType, $message) && (!$usingJson || null === $mediaTypeFromJson)) {
            $this->logger->info('MediaPromptExtractor: Triggering audio-only extraction fallback');
            try {
                $audioContent = $this->runPrompt($message, $thread, $classification, self::AUDIO_EXTRACTION_TOPIC);
                $audioContent = trim($this->normalizeContent($audioContent));
                if ('' !== $audioContent) {
                    $prompt = $audioContent;
                    $mediaType = 'audio';
                }
            } catch (\Throwable $e) {
                $this->logger->warning('MediaPromptExtractor: Audio-only extraction fallback failed', [
                    'error' => $e->getMessage(),
                    'message_id' => $message->getId(),
                ]);
            }
        }

        $this->logger->info('MediaPromptExtractor: Prompt extracted', [
            'prompt_preview' => mb_substr($prompt, 0, 120),
            'media_type' => $mediaType,
            'raw_length' => strlen($normalized),
        ]);

        return [
            'prompt' => $prompt,
            'media_type' => $mediaType,
            'raw' => $normalized,
        ];
    }

    private function normalizeContent(string $content): string
    {
        $content = trim($content);

        if ('' === $content) {
            return '';
        }

        if (str_starts_with($content, '```')) {
            $content = (string) preg_replace('/^```(?:json)?\s*/i', '', $content);
            $content = (string) preg_replace('/\s*```$/', '', $content);
            $content = trim($content);
        }

        return $content;
    }

    private function decodeJson(string $content): ?array
    {
        if ('' === $content || (!str_starts_with($content, '{') && !str_starts_with($content, '['))) {
            return null;
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (\JsonException) {
            return null;
        }
    }

    private function normalizeMediaType(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        return match ($value) {
            'audio', 'sound', 'voice', 'tts', 'text2sound', 'mp3', 'wav', 'ogg' => 'audio',
            'video', 'vid', 'text2vid' => 'video',
            'image', 'img', 'picture', 'pic', 'text2pic' => 'image',
            default => null,
        };
    }

    private function runPrompt(Message $message, array $thread, array $classification, string $topic): string
    {
        $promptClassification = $classification;
        $promptClassification['topic'] = $topic;
        $promptClassification['intent'] = 'image_generation';

        $language = $promptClassification['language'] ?? $message->getLanguage();
        $promptClassification['language'] = $language && 'NN' !== $language ? $language : 'en';

        unset(
            $promptClassification['model_id'],
            $promptClassification['override_model_id'],
            $promptClassification['provider'],
            $promptClassification['model_name']
        );

        $response = $this->chatHandler->handle($message, $thread, $promptClassification);

        return (string) ($response['content'] ?? '');
    }

    private function shouldForceAudioExtraction(?string $mediaType, Message $message): bool
    {
        if ('audio' === $mediaType) {
            return true;
        }

        $text = $message->getText();

        return (bool) preg_match(
            '/(audiodatei|audiofile|audio|tonspur|sprachnachricht|sprachdatei|sound|sprach[a-z]*|voice(?:\s?note)?|tts|sprich|sage|lies|vorlesen|vortragen|mp3|wav|ogg|musik|music)/i',
            $text
        );
    }

    private function inferMediaTypeFromMessageText(string $text): ?string
    {
        if ($this->isAudioIntentText($text)) {
            return 'audio';
        }

        return null;
    }

    private function isAudioIntentText(string $text): bool
    {
        return (bool) preg_match(
            '/(audiodatei|audiofile|audio|tonspur|sprachnachricht|sprachdatei|sound|sprach[a-z]*|voice(?:\s?note)?|tts|sprich|sage|lies|vorlesen|vortragen|mp3|wav|ogg|musik|music)/i',
            $text
        );
    }
}
