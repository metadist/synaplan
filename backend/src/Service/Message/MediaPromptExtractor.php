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

        $rawContent = '';

        try {
            $rawContent = $this->runPrompt($message, $thread, $classification, 'mediamaker');
        } catch (\Throwable $e) {
            $this->logger->warning('MediaPromptExtractor: ChatHandler extraction failed, using fallback', [
                'error' => $e->getMessage(),
                'message_id' => $message->getId(),
            ]);

            return [
                'prompt' => $message->getText(),
                'media_type' => null,
                'raw' => '',
            ];
        }

        $normalized = $this->normalizeContent($rawContent);
        $decoded = $this->decodeJson($normalized);

        $prompt = '';
        $mediaType = null;

        if (is_array($decoded)) {
            $prompt = trim((string) ($decoded['BTEXT'] ?? $decoded['prompt'] ?? $decoded['text'] ?? ''));
            $mediaType = $this->normalizeMediaType($decoded['BMEDIA'] ?? $decoded['media'] ?? null);
        }

        $usingJson = is_array($decoded);

        if ('' === $prompt) {
            $prompt = '' !== $normalized ? $normalized : $message->getText();
        }

        // Only force audio extraction if:
        // 1. We didn't get JSON (so mediamaker prompt didn't follow format)
        // 2. Media type is explicitly audio OR message clearly indicates audio
        // 3. Media type is not already set to something else (image/video)
        if (!$usingJson && $this->shouldForceAudioExtraction($mediaType, $message)) {
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
            'audio', 'sound', 'voice', 'tts', 'text2sound' => 'audio',
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

        $language = $promptClassification['language'] ?? $message->getLanguage() ?? 'en';
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

        $text = $message->getText() ?? '';

        return (bool) preg_match(
            '/\b(audio|tonspur|sound|sprach[a-z]*|voice|tts|sprich|sage|lies|vorlesen|vor|vortragen)\b/i',
            $text
        );
    }
}
