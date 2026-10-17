<?php

namespace App\Service\WhatsApp\Turn;

use App\AI\Exception\NoSpeakableTextException;
use App\AI\Service\AiFacade;
use App\Service\File\UserUploadPathBuilder;
use App\Service\Media\OutboundChannelMedia;
use App\Service\Notification\DiscordNotificationService;
use App\Service\RateLimitService;
use App\Service\WhatsApp\WhatsAppService;
use Psr\Log\LoggerInterface;

/**
 * Sends a WhatsApp answer by the first way that works: the generated media,
 * then a spoken reply to a voice note or a video, then text. Extra files of a
 * multi-task plan follow as their own messages. Every reply that reaches the
 * phone is stored with the model's metadata.
 */
final readonly class WhatsAppReplyDelivery
{
    public function __construct(
        private WhatsAppService $whatsApp,
        private WhatsAppAnswerStore $answers,
        private RateLimitService $rateLimitService,
        private DiscordNotificationService $discord,
        private AiFacade $aiFacade,
        private UserUploadPathBuilder $userUploadPathBuilder,
        private LoggerInterface $logger,
        private string $appUrl,
        private string $uploadsDir,
    ) {
    }

    /**
     * @return bool whether a reply reached the phone
     */
    public function deliver(InboundWhatsApp $turn, StoredWhatsAppMessage $stored, WhatsAppAnswer $answer): bool
    {
        $responseSent = false;
        if ($answer->file()) {
            $responseSent = $this->sendGeneratedMedia($turn, $stored, $answer);
            $this->sendExtraFiles($turn, $answer);
        }
        if (!$responseSent && $turn->answersBySpeech && !empty($answer->channelText) && !empty($this->appUrl)) {
            $responseSent = $this->sendSpokenReply($turn, $stored, $answer);
        }
        if (!$responseSent && !empty($answer->textWithSources)) {
            $responseSent = $this->sendText($turn, $stored, $answer);
        }

        return $responseSent;
    }

    /**
     * WhatsApp captions exist only on images and videos and stop at 1024
     * characters, and Meta fetches a document's link only after accepting the
     * message, where a failed fetch drops the caption too. So short text rides
     * as the caption of an image or a video (without the sources, #652), and
     * any other text goes out first as its own message.
     */
    private function sendGeneratedMedia(InboundWhatsApp $turn, StoredWhatsAppMessage $stored, WhatsAppAnswer $answer): bool
    {
        $dto = $turn->dto;
        $file = $answer->file();
        $generatedMediaType = $file['type'] ?? null;
        $mediaPath = $file['path'] ?? null;
        if (!$mediaPath || empty($this->appUrl) || !in_array($generatedMediaType, ['audio', 'video', 'image', 'document'], true)) {
            return false;
        }

        [$mediaUrl, $displayFilename] = $this->mediaLinkForMeta($mediaPath, $generatedMediaType, $turn->effectiveUserId);
        $this->logger->info('WhatsApp: Sending AI-generated media response', [
            'to' => $dto->from,
            'media_type' => $generatedMediaType,
            'media_url' => $mediaUrl,
        ]);

        $caption = null;
        $textSentSeparately = false;
        $textMessageId = '';
        if (in_array($generatedMediaType, ['image', 'video'], true) && !empty($answer->channelText) && mb_strlen($answer->channelText) <= 1024) {
            $caption = $answer->channelText;
        } elseif ('' !== trim($answer->textWithSources)) {
            $textSend = $this->whatsApp->sendMessage($dto->from, $answer->textWithSources, $dto->phoneNumberId);
            $textSentSeparately = !empty($textSend['success']);
            $textMessageId = (string) ($textSend['message_id'] ?? '');
            if (!$textSentSeparately) {
                $this->logger->warning('WhatsApp: Failed to send response text before media', [
                    'to' => $dto->from,
                    'error' => $textSend['error'] ?? 'Unknown',
                ]);
            }
        }

        $sendResult = $this->whatsApp->sendMedia($dto->from, $generatedMediaType, $mediaUrl, $dto->phoneNumberId, $caption, $displayFilename);
        if ($sendResult['success']) {
            $this->storeGeneratedMedia($turn, $stored, $answer, $generatedMediaType, $mediaPath, $sendResult['message_id']);

            return true;
        }

        $this->logger->warning('WhatsApp: Failed to send AI media, falling back', [
            'media_type' => $generatedMediaType,
            'error' => $sendResult['error'] ?? 'Unknown',
        ]);
        $this->discord->notifyWhatsAppError(
            'send_failed',
            $dto->from,
            $stored->message->getText(),
            $sendResult['error'] ?? 'Unknown error',
            ['media_type' => $generatedMediaType],
            $turn->user->getId(),
        );
        if (!$textSentSeparately) {
            return false;
        }

        // The text already reached the phone as its own message: it is stored,
        // and the text fallback does not send it twice.
        $this->answers->store($turn->user, $dto, $answer->text, $textMessageId, $stored->chat, $answer->searchResults, null, $answer->metadata, $answer->classification);

        return true;
    }

    /**
     * A document has no media quota of its own: the turn's message usage
     * covers it. The stored reply keeps the media path, so the web chat shows
     * the player (#626).
     */
    private function storeGeneratedMedia(InboundWhatsApp $turn, StoredWhatsAppMessage $stored, WhatsAppAnswer $answer, string $generatedMediaType, string $mediaPath, string $externalId): void
    {
        $dto = $turn->dto;
        $user = $turn->user;
        $metadata = $answer->metadata;
        if (in_array($generatedMediaType, ['image', 'video', 'audio'], true)) {
            $mediaAction = match ($generatedMediaType) {
                'image' => 'IMAGES',
                'video' => 'VIDEOS',
                default => 'AUDIOS',
            };
            $this->rateLimitService->recordUsage($user, $mediaAction, [
                'provider' => $metadata['provider'] ?? 'unknown',
                'model' => $metadata['model'] ?? 'unknown',
                'model_id' => $metadata['model_id'] ?? null,
                'source' => 'WHATSAPP',
                'media_usage' => $metadata['media_usage'] ?? [],
            ]);
        }

        $text = $answer->text ?: match ($generatedMediaType) {
            'image' => '[Image response]',
            'video' => '[Video response]',
            'document' => '[Document response]',
            default => '[Audio response]',
        };
        $this->answers->store($user, $dto, $text, $externalId, $stored->chat, $answer->searchResults, ['path' => $mediaPath, 'type' => $generatedMediaType], $metadata, $answer->classification);
        $this->discord->notifyWhatsAppSuccess(
            $generatedMediaType,
            $dto->from,
            $stored->message->getText(),
            $text,
            [
                'provider' => $metadata['provider'] ?? null,
                'model' => $metadata['model'] ?? null,
                'media_type' => $generatedMediaType,
            ],
            $user->getId(),
        );
    }

    /**
     * A multi-task plan can produce more than one file: the first went out as
     * the generated media, the others follow as their own messages. Best
     * effort: one failed file does not stop the rest.
     */
    private function sendExtraFiles(InboundWhatsApp $turn, WhatsAppAnswer $answer): void
    {
        $dto = $turn->dto;
        $extraFiles = $answer->metadata['files'] ?? null;
        if (!is_array($extraFiles) || count($extraFiles) <= 1 || empty($this->appUrl)) {
            return;
        }

        foreach (array_values($extraFiles) as $idx => $taskFile) {
            if (0 === $idx || !is_array($taskFile)) {
                continue;
            }
            $type = $taskFile['type'] ?? null;
            $path = $taskFile['path'] ?? null;
            if (!is_string($path) || '' === $path || !in_array($type, ['audio', 'video', 'image', 'document'], true)) {
                continue;
            }
            [$url, $extraFilename] = $this->mediaLinkForMeta($path, $type, $turn->effectiveUserId);
            $this->logger->info('WhatsApp: Sending additional multi-task media', [
                'to' => $dto->from,
                'media_type' => $type,
                'index' => $idx,
            ]);

            try {
                $extraResult = $this->whatsApp->sendMedia($dto->from, $type, $url, $dto->phoneNumberId, null, $extraFilename);
                if (empty($extraResult['success'])) {
                    $this->logger->warning('WhatsApp: Failed to send additional multi-task media', [
                        'to' => $dto->from,
                        'media_type' => $type,
                        'index' => $idx,
                        'error' => $extraResult['error'] ?? 'Unknown',
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('WhatsApp: Failed to send additional multi-task media', [
                    'to' => $dto->from,
                    'media_type' => $type,
                    'index' => $idx,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * The stored reply keeps the audio, so the web chat shows the player (#626).
     */
    private function sendSpokenReply(InboundWhatsApp $turn, StoredWhatsAppMessage $stored, WhatsAppAnswer $answer): bool
    {
        $dto = $turn->dto;
        $message = $stored->message;
        $ttsResult = $this->generateTtsResponse($answer->channelText, $turn->effectiveUserId, $message->getLanguage() ?: 'en');
        if (!$ttsResult) {
            $this->logger->warning('WhatsApp: TTS generation failed, falling back to text');
            $this->discord->notifyWhatsAppError(
                'tts',
                $dto->from,
                $message->getText(),
                'TTS generation failed',
                ['message_type' => $dto->type],
                $turn->user->getId(),
            );

            return false;
        }

        $audioUrl = rtrim($this->appUrl, '/').'/api/v1/files/uploads/'.$ttsResult['relativePath'];
        $this->logger->info('WhatsApp: Sending TTS response for audio/video message', [
            'to' => $dto->from,
            'audio_url' => $audioUrl,
            'response_length' => strlen($answer->text),
        ]);
        $sendResult = $this->whatsApp->sendMedia($dto->from, 'audio', $audioUrl, $dto->phoneNumberId);
        if (!$sendResult['success']) {
            $this->logger->warning('WhatsApp: TTS send failed, falling back to text', [
                'error' => $sendResult['error'] ?? 'Unknown',
            ]);
            $this->discord->notifyWhatsAppError(
                'send_failed',
                $dto->from,
                $message->getText(),
                $sendResult['error'] ?? 'Unknown error',
                ['media_type' => 'audio'],
                $turn->user->getId(),
            );

            return false;
        }

        $ttsServePath = '/api/v1/files/uploads/'.$ttsResult['relativePath'];
        $this->answers->store($turn->user, $dto, $answer->text, $sendResult['message_id'], $stored->chat, $answer->searchResults, ['path' => $ttsServePath, 'type' => 'audio'], $answer->metadata, $answer->classification);
        $this->discord->notifyWhatsAppSuccess(
            'tts',
            $dto->from,
            $message->getText(),
            $answer->text,
            [
                'provider' => $ttsResult['provider'] ?? null,
                'model' => $ttsResult['model'] ?? null,
                'media_type' => 'audio',
            ],
            $turn->user->getId(),
        );

        return true;
    }

    /**
     * The stored reply leaves out the sources block: the chat view renders the
     * sources from the metadata.
     */
    private function sendText(InboundWhatsApp $turn, StoredWhatsAppMessage $stored, WhatsAppAnswer $answer): bool
    {
        $dto = $turn->dto;
        $message = $stored->message;
        $sendResult = $this->whatsApp->sendMessage($dto->from, $answer->textWithSources, $dto->phoneNumberId);
        if (!$sendResult['success']) {
            $this->logger->error('WhatsApp: Failed to send text response', [
                'error' => $sendResult['error'] ?? 'Unknown',
            ]);
            $this->discord->notifyWhatsAppError(
                'send_failed',
                $dto->from,
                $message->getText(),
                $sendResult['error'] ?? 'Unknown error',
                ['message_type' => 'text'],
                $turn->user->getId(),
            );

            return false;
        }

        $this->answers->store($turn->user, $dto, $answer->text, $sendResult['message_id'], $stored->chat, $answer->searchResults, null, $answer->metadata, $answer->classification);
        $this->discord->notifyWhatsAppSuccess(
            'text',
            $dto->from,
            $message->getText(),
            $answer->text,
            [
                'provider' => $answer->metadata['provider'] ?? null,
                'model' => $answer->metadata['model'] ?? null,
            ],
            $turn->user->getId(),
        );

        return true;
    }

    /**
     * Generate TTS (text-to-speech) audio response.
     *
     * @param string $text     The text to synthesize
     * @param int    $userId   User ID for provider selection
     * @param string $language Language code (en, de, es, etc.) for voice selection
     *
     * @return array|null Result with relativePath, or null on failure
     */
    private function generateTtsResponse(string $text, int $userId, string $language = 'en'): ?array
    {
        try {
            $this->logger->info('WhatsApp: Generating TTS response', [
                'user_id' => $userId,
                'text_length' => mb_strlen($text),
                'language' => $language,
            ]);

            // Facade sanitizes + truncates; language is required (#2283).
            $result = $this->aiFacade->synthesize($text, $language, $userId, [
                'format' => 'mp3',
            ]);

            $this->logger->info('WhatsApp: TTS generation successful', [
                'provider' => $result['provider'] ?? 'unknown',
                'path' => $result['relativePath'] ?? 'unknown',
            ]);

            return $result;
        } catch (NoSpeakableTextException) {
            $this->logger->info('WhatsApp: TTS skipped, no speakable text', [
                'user_id' => $userId,
            ]);

            return null;
        } catch (\Throwable $e) {
            $this->logger->error('WhatsApp: TTS generation failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Build the URL Meta should fetch, rewriting generated documents (and any
     * other file whose basename is not already anonymously fetchable) to a
     * copy whose name matches {@see OutboundChannelMedia} so
     * StaticUploadController will serve it without auth.
     *
     * @return array{0: string, 1: string} [absolute URL, original display filename]
     */
    private function mediaLinkForMeta(string $mediaPath, string $mediaType, int $userId): array
    {
        $displayFilename = basename(OutboundChannelMedia::relativeUploadPath($mediaPath));
        if ('' === $displayFilename) {
            $displayFilename = $mediaType;
        }

        $originalUrl = rtrim($this->appUrl, '/').'/'.ltrim($mediaPath, '/');

        if (OutboundChannelMedia::isAnonymouslyFetchable($displayFilename)) {
            return [$originalUrl, $displayFilename];
        }

        $relative = OutboundChannelMedia::relativeUploadPath($mediaPath);
        if ('' === $relative || str_contains($relative, '..')) {
            return [$originalUrl, $displayFilename];
        }

        $absoluteSource = rtrim($this->uploadsDir, '/').'/'.$relative;
        $published = OutboundChannelMedia::publishCopy(
            $absoluteSource,
            $this->uploadsDir,
            $userId,
            $mediaType,
            $this->userUploadPathBuilder,
        );

        if (null === $published) {
            $this->logger->warning('WhatsApp: Could not publish media for anonymous Meta fetch', [
                'media_path' => $mediaPath,
                'media_type' => $mediaType,
            ]);

            return [$originalUrl, $displayFilename];
        }

        return [
            rtrim($this->appUrl, '/').'/api/v1/files/uploads/'.$published,
            $displayFilename,
        ];
    }
}
