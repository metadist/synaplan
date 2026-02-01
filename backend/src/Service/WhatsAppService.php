<?php

namespace App\Service;

use App\AI\Service\AiFacade;
use App\DTO\WhatsApp\IncomingMessageDto;
use App\Entity\Message;
use App\Entity\User;
use App\Service\File\FileProcessor;
use App\Service\File\UserUploadPathBuilder;
use App\Service\Message\MessageProcessor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * WhatsApp Business API Service (Meta/Facebook).
 *
 * Handles sending messages via WhatsApp Business API and processing incoming messages.
 *
 * Message Type Handling:
 * - TEXT: Processed as AI prompt, text response
 * - AUDIO (voice): Transcribed via Whisper, TTS audio response
 * - IMAGE: Vision AI analysis, brief text comment
 * - VIDEO: Audio extracted via FFmpeg, transcribed, text response
 */
class WhatsAppService
{
    private const MAX_FILE_SIZE = 128 * 1024 * 1024; // 128 MB (same as FileStorageService)

    // Allowed file extensions (same as FileStorageService for consistency)
    private const ALLOWED_EXTENSIONS = [
        'pdf', 'docx', 'doc', 'xlsx', 'xls', 'pptx', 'ppt', 'txt', 'md', 'csv',
        'jpg', 'jpeg', 'png', 'gif', 'webp',
        'mp3', 'mp4', 'wav', 'ogg', 'm4a', 'webm',
        'amr', 'opus', '3gp', // WhatsApp-specific audio/video formats
    ];

    private string $accessToken;
    private bool $enabled;
    private string $apiVersion = 'v21.0';

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private EntityManagerInterface $em,
        private RateLimitService $rateLimitService,
        private MessageProcessor $messageProcessor,
        private FileProcessor $fileProcessor,
        private UserUploadPathBuilder $userUploadPathBuilder,
        private AiFacade $aiFacade,
        string $whatsappAccessToken,
        bool $whatsappEnabled,
        private string $uploadsDir,
        private int $whatsappUserId,
        private string $appUrl = '',
    ) {
        $this->accessToken = $whatsappAccessToken;
        $this->enabled = $whatsappEnabled;
    }

    /**
     * Check if WhatsApp is available.
     */
    public function isAvailable(): bool
    {
        return $this->enabled && !empty($this->accessToken);
    }

    /**
     * Send text message.
     *
     * @param string $phoneNumberId The WhatsApp Phone Number ID to send from (extracted from webhook metadata)
     */
    public function sendMessage(string $to, string $message, string $phoneNumberId): array
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('WhatsApp service is not available');
        }

        if (empty($phoneNumberId)) {
            throw new \InvalidArgumentException('Phone Number ID is required and must be provided dynamically from webhook metadata');
        }

        $url = sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            $this->apiVersion,
            $phoneNumberId
        );

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $this->formatPhoneNumber($to),
                    'type' => 'text',
                    'text' => [
                        'preview_url' => true,
                        'body' => $message,
                    ],
                ],
            ]);

            $data = $response->toArray();

            $this->logger->info('WhatsApp message sent', [
                'to' => $to,
                'message_id' => $data['messages'][0]['id'] ?? null,
            ]);

            return [
                'success' => true,
                'message_id' => $data['messages'][0]['id'] ?? null,
                'data' => $data,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to send WhatsApp message', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send media (image, audio, video, document).
     *
     * @param string $phoneNumberId The WhatsApp Phone Number ID to send from (extracted from webhook metadata)
     */
    public function sendMedia(
        string $to,
        string $mediaType,
        string $mediaUrl,
        string $phoneNumberId,
        ?string $caption = null,
    ): array {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('WhatsApp service is not available');
        }

        if (empty($phoneNumberId)) {
            throw new \InvalidArgumentException('Phone Number ID is required and must be provided dynamically from webhook metadata');
        }

        if (!in_array($mediaType, ['image', 'audio', 'video', 'document'])) {
            throw new \InvalidArgumentException('Invalid media type: '.$mediaType);
        }

        $url = sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            $this->apiVersion,
            $phoneNumberId
        );

        $mediaPayload = [
            'link' => $mediaUrl,
        ];

        if ($caption && in_array($mediaType, ['image', 'video', 'document'])) {
            $mediaPayload['caption'] = $caption;
        }

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $this->formatPhoneNumber($to),
                    'type' => $mediaType,
                    $mediaType => $mediaPayload,
                ],
            ]);

            $data = $response->toArray();

            $this->logger->info('WhatsApp media sent', [
                'to' => $to,
                'type' => $mediaType,
                'message_id' => $data['messages'][0]['id'] ?? null,
            ]);

            return [
                'success' => true,
                'message_id' => $data['messages'][0]['id'] ?? null,
                'data' => $data,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to send WhatsApp media', [
                'to' => $to,
                'type' => $mediaType,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send template message.
     *
     * @param string $phoneNumberId The WhatsApp Phone Number ID to send from (extracted from webhook metadata)
     */
    public function sendTemplate(
        string $to,
        string $templateName,
        string $phoneNumberId,
        string $languageCode = 'en_US',
        array $components = [],
    ): array {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('WhatsApp service is not available');
        }

        if (empty($phoneNumberId)) {
            throw new \InvalidArgumentException('Phone Number ID is required and must be provided dynamically from webhook metadata');
        }

        $url = sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            $this->apiVersion,
            $phoneNumberId
        );

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'messaging_product' => 'whatsapp',
                    'to' => $this->formatPhoneNumber($to),
                    'type' => 'template',
                    'template' => [
                        'name' => $templateName,
                        'language' => [
                            'code' => $languageCode,
                        ],
                        'components' => $components,
                    ],
                ],
            ]);

            $data = $response->toArray();

            $this->logger->info('WhatsApp template sent', [
                'to' => $to,
                'template' => $templateName,
                'message_id' => $data['messages'][0]['id'] ?? null,
            ]);

            return [
                'success' => true,
                'message_id' => $data['messages'][0]['id'] ?? null,
                'data' => $data,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to send WhatsApp template', [
                'to' => $to,
                'template' => $templateName,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Mark message as read.
     *
     * @param string $phoneNumberId The WhatsApp Phone Number ID (extracted from webhook metadata)
     */
    public function markAsRead(string $messageId, string $phoneNumberId): array
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('WhatsApp service is not available');
        }

        if (empty($phoneNumberId)) {
            throw new \InvalidArgumentException('Phone Number ID is required and must be provided dynamically from webhook metadata');
        }

        $url = sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            $this->apiVersion,
            $phoneNumberId
        );

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'messaging_product' => 'whatsapp',
                    'status' => 'read',
                    'message_id' => $messageId,
                ],
            ]);

            return [
                'success' => true,
                'data' => $response->toArray(),
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to mark WhatsApp message as read', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process an incoming WhatsApp message.
     * Handles verification codes, rate limiting, media downloads, and AI processing.
     *
     * Response modes:
     * - TEXT input → TEXT response
     * - AUDIO input (voice-only) → TTS AUDIO response
     * - IMAGE input → TEXT description
     * - VIDEO input → TEXT response (from audio track)
     */
    public function handleIncomingMessage(IncomingMessageDto $dto, User $user, bool $isAnonymous): array
    {
        $effectiveUserId = $isAnonymous ? $this->whatsappUserId : $user->getId();

        // Determine input type for response mode selection
        $shouldSendAudioResponse = $this->shouldSendAudioResponse($dto);
        $isImageMessage = 'image' === $dto->type;
        $isVideoMessage = 'video' === $dto->type;

        $this->logger->info('WhatsApp message received', [
            'original_user_id' => $user->getId(),
            'whatsapp_default_user_id' => $this->whatsappUserId,
            'effective_user_id' => $effectiveUserId,
            'from' => $dto->from,
            'to_phone_number_id' => $dto->phoneNumberId,
            'to_display_phone' => $dto->displayPhoneNumber,
            'type' => $dto->type,
            'message_id' => $dto->messageId,
            'should_send_audio' => $shouldSendAudioResponse,
            'is_image' => $isImageMessage,
            'is_video' => $isVideoMessage,
        ]);

        // 1. PRIORITY: Check for verification codes
        if ('text' === $dto->type) {
            $messageText = $dto->incomingMsg['text']['body'];
            $trimmedText = trim(strtoupper($messageText));

            if (preg_match('/^[A-Z0-9]{5}$/', $trimmedText)) {
                $verificationResult = $this->handleVerificationCode($trimmedText, $dto->from, $dto->phoneNumberId, $dto->messageId);
                if (null !== $verificationResult) {
                    return $verificationResult;
                }
            }
        }

        // 2. Rate Limit Check
        $rateLimitCheck = $this->rateLimitService->checkLimit($user, 'MESSAGES');
        if (!$rateLimitCheck['allowed']) {
            return $this->handleRateLimitExceeded($user, $dto, $rateLimitCheck);
        }

        // 3. Extract content and media
        $messageText = $this->extractMessageText($dto);
        $mediaId = $this->extractMediaId($dto);
        $mediaUrl = $dto->incomingMsg[$dto->type]['link'] ?? null;

        // 4. Create database record
        $message = new Message();
        $message->setUserId($effectiveUserId);
        $message->setTrackingId($dto->timestamp);
        $message->setProviderIndex('WHATSAPP');
        $message->setUnixTimestamp($dto->timestamp);
        $message->setDateTime(date('YmdHis', $dto->timestamp));
        $message->setMessageType('WTSP');
        $message->setFile(0);
        $message->setTopic('CHAT');
        $message->setLanguage('en');
        $message->setText($messageText);
        $message->setDirection('IN');
        $message->setStatus('processing');

        // Store input type metadata for response mode selection
        $message->setMeta('whatsapp_input_type', $dto->type);
        $message->setMeta('whatsapp_should_send_audio', $shouldSendAudioResponse ? '1' : '0');

        $this->em->persist($message);
        $this->em->flush();

        // 5. Metadata and Media Handling
        $this->storeMessageMetadata($message, $dto, $user);

        $mediaDownloadError = null;
        if ($mediaId) {
            $mediaDownloadError = $this->handleMediaDownload($message, $dto, $mediaId, $mediaUrl, $effectiveUserId);
        }

        $this->em->flush();

        // Check for media download errors and send user-friendly error message
        if ($mediaDownloadError) {
            $this->sendErrorMessage($dto, $mediaDownloadError);

            return [
                'success' => false,
                'message_id' => $dto->messageId,
                'error' => $mediaDownloadError,
            ];
        }

        // 6. Usage recording and mark as read
        $this->rateLimitService->recordUsage($user, 'MESSAGES');
        $this->markAsRead($dto->messageId, $dto->phoneNumberId);

        // 7. AI Pipeline Processing (use streaming mode to support TTS/media generation)
        $collectedResponse = '';
        $streamCallback = function (string|array $chunk, array $metadata = []) use (&$collectedResponse): void {
            // Handle both string chunks (old providers) and array chunks (new providers with type/content)
            if (is_array($chunk)) {
                // Extract content from array format: ['type' => 'content', 'content' => '...']
                if (isset($chunk['type']) && 'content' === $chunk['type'] && isset($chunk['content'])) {
                    $collectedResponse .= $chunk['content'];
                }
            } else {
                // Old format: simple string
                $collectedResponse .= $chunk;
            }
        };

        // For image messages, add context to help AI generate a brief description
        $processingOptions = [];
        if ($isImageMessage) {
            // Set a special topic to route to brief image description
            $processingOptions['force_image_description'] = true;
        }

        $result = $this->messageProcessor->processStream($message, $streamCallback, null, $processingOptions);

        if (!$result['success']) {
            $errorMessage = $result['error'] ?? 'Processing failed';
            $this->sendErrorMessage($dto, $errorMessage);

            return [
                'success' => false,
                'message_id' => $dto->messageId,
                'error' => $errorMessage,
            ];
        }

        $responseText = $result['response']['content'] ?? $collectedResponse;
        $metadata = $result['response']['metadata'] ?? [];
        $fileData = $metadata['file'] ?? null;

        // 8. Send Response based on input type
        $responseSent = false;

        // PRIORITY 1: Check if AI already generated audio (TTS response from MediaGenerationHandler)
        if ($fileData && 'audio' === ($fileData['type'] ?? null)) {
            $audioPath = $fileData['path'] ?? null;
            if ($audioPath && !empty($this->appUrl)) {
                $audioUrl = rtrim($this->appUrl, '/').'/'.ltrim($audioPath, '/');

                $this->logger->info('WhatsApp: Sending AI-generated audio response', [
                    'to' => $dto->from,
                    'audio_url' => $audioUrl,
                ]);

                $sendResult = $this->sendMedia($dto->from, 'audio', $audioUrl, $dto->phoneNumberId);
                if ($sendResult['success']) {
                    $this->storeOutgoingMessage($user, $dto, $responseText ?: '[Audio response]', $sendResult['message_id']);
                    $responseSent = true;
                } else {
                    $this->logger->warning('WhatsApp: Failed to send AI audio, falling back to TTS', [
                        'error' => $sendResult['error'] ?? 'Unknown',
                    ]);
                }
            }
        }

        // PRIORITY 2: Audio/Video input → Generate TTS response
        if (!$responseSent && $shouldSendAudioResponse && !empty($responseText)) {
            $ttsResult = $this->generateTtsResponse($responseText, $effectiveUserId);

            if ($ttsResult) {
                $audioUrl = rtrim($this->appUrl, '/').'/api/v1/files/uploads/'.$ttsResult['relativePath'];

                $this->logger->info('WhatsApp: Sending TTS response for audio/video message', [
                    'to' => $dto->from,
                    'audio_url' => $audioUrl,
                    'response_length' => strlen($responseText),
                ]);

                $sendResult = $this->sendMedia($dto->from, 'audio', $audioUrl, $dto->phoneNumberId);
                if ($sendResult['success']) {
                    $this->storeOutgoingMessage($user, $dto, $responseText, $sendResult['message_id']);
                    $responseSent = true;
                } else {
                    $this->logger->warning('WhatsApp: TTS send failed, falling back to text', [
                        'error' => $sendResult['error'] ?? 'Unknown',
                    ]);
                }
            } else {
                $this->logger->warning('WhatsApp: TTS generation failed, falling back to text');
            }
        }

        // PRIORITY 3: Send text response (fallback or for text/image/video input)
        if (!$responseSent && !empty($responseText)) {
            $sendResult = $this->sendMessage($dto->from, $responseText, $dto->phoneNumberId);
            if ($sendResult['success']) {
                $this->storeOutgoingMessage($user, $dto, $responseText, $sendResult['message_id']);
                $responseSent = true;
            } else {
                $this->logger->error('WhatsApp: Failed to send text response', [
                    'error' => $sendResult['error'] ?? 'Unknown',
                ]);
            }
        }

        // If no response was sent, log an error
        if (!$responseSent) {
            $this->logger->error('WhatsApp: No response sent to user', [
                'message_id' => $dto->messageId,
                'from' => $dto->from,
                'response_text_length' => strlen($responseText),
            ]);
        }

        return [
            'success' => true,
            'message_id' => $dto->messageId,
            'response_sent' => $responseSent,
            'response_type' => $responseSent ? ($shouldSendAudioResponse && !$fileData ? 'tts' : ($fileData ? 'audio' : 'text')) : 'none',
        ];
    }

    /**
     * Determine if we should send an audio response (MP3) back to the user.
     * This is true for voice messages (audio without meaningful caption)
     * and video messages (as requested by user).
     */
    private function shouldSendAudioResponse(IncomingMessageDto $dto): bool
    {
        if ('audio' === $dto->type) {
            // Check for caption (some audio messages might have text)
            $caption = $dto->incomingMsg['audio']['caption'] ?? null;

            // Voice-only if no caption or caption is just whitespace
            return empty(trim((string) $caption));
        }

        if ('video' === $dto->type) {
            // User requested that video answers are sent as MP3
            return true;
        }

        return false;
    }

    /**
     * Generate TTS (text-to-speech) audio response.
     *
     * @param string $text   The text to synthesize
     * @param int    $userId User ID for provider selection
     *
     * @return array|null Result with relativePath, or null on failure
     */
    private function generateTtsResponse(string $text, int $userId): ?array
    {
        // Limit text length for TTS (max ~4000 chars for most providers)
        $maxLength = 4000;
        if (strlen($text) > $maxLength) {
            $text = substr($text, 0, $maxLength - 3).'...';
            $this->logger->info('WhatsApp: TTS text truncated', [
                'original_length' => strlen($text),
                'max_length' => $maxLength,
            ]);
        }

        try {
            $this->logger->info('WhatsApp: Generating TTS response', [
                'user_id' => $userId,
                'text_length' => strlen($text),
            ]);

            $result = $this->aiFacade->synthesize($text, $userId, [
                'format' => 'mp3',
            ]);

            $this->logger->info('WhatsApp: TTS generation successful', [
                'provider' => $result['provider'] ?? 'unknown',
                'path' => $result['relativePath'] ?? 'unknown',
            ]);

            return $result;
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
     * Send a user-friendly error message via WhatsApp.
     */
    private function sendErrorMessage(IncomingMessageDto $dto, string $error): void
    {
        $errorMap = [
            'transcription' => "⚠️ *Sprachnachricht konnte nicht verarbeitet werden*\n\nDie Sprachnachricht konnte nicht transkribiert werden. Bitte versuche es erneut oder sende eine Textnachricht.",
            'image' => "⚠️ *Bild konnte nicht analysiert werden*\n\nDas Bild konnte nicht verarbeitet werden. Bitte versuche es erneut.",
            'video' => "⚠️ *Video konnte nicht verarbeitet werden*\n\nDas Video konnte nicht analysiert werden. Bitte versuche es erneut.",
            'no_audio' => "⚠️ *Video ohne Audiospur*\n\nDas Video enthält keine Audiospur, die transkribiert werden kann.",
            'file_too_large' => "⚠️ *Datei zu groß*\n\nDie Datei ist zu groß (max. 128 MB). Bitte sende eine kleinere Datei.",
            'unsupported_format' => "⚠️ *Format nicht unterstützt*\n\nDieses Dateiformat wird nicht unterstützt.",
            'default' => "⚠️ *Fehler bei der Verarbeitung*\n\nDeine Nachricht konnte nicht verarbeitet werden. Bitte versuche es erneut.\n\nFehler: {error}",
        ];

        // Determine error type
        $errorLower = strtolower($error);
        $messageTemplate = $errorMap['default'];

        if (str_contains($errorLower, 'transcri') || str_contains($errorLower, 'whisper') || str_contains($errorLower, 'audio')) {
            $messageTemplate = $errorMap['transcription'];
        } elseif (str_contains($errorLower, 'image') || str_contains($errorLower, 'vision')) {
            $messageTemplate = $errorMap['image'];
        } elseif (str_contains($errorLower, 'video')) {
            $messageTemplate = $errorMap['video'];
        } elseif (str_contains($errorLower, 'no audio') || str_contains($errorLower, 'audio track')) {
            $messageTemplate = $errorMap['no_audio'];
        } elseif (str_contains($errorLower, 'too large') || str_contains($errorLower, 'size')) {
            $messageTemplate = $errorMap['file_too_large'];
        } elseif (str_contains($errorLower, 'format') || str_contains($errorLower, 'unsupported')) {
            $messageTemplate = $errorMap['unsupported_format'];
        }

        $errorMessage = str_replace('{error}', $error, $messageTemplate);

        $this->sendMessage($dto->from, $errorMessage, $dto->phoneNumberId);
    }

    private function handleRateLimitExceeded(User $user, IncomingMessageDto $dto, array $rateLimitCheck): array
    {
        $this->logger->warning('WhatsApp message rate limit exceeded', [
            'user_id' => $user->getId(),
            'level' => $user->getRateLimitLevel(),
            'limit' => $rateLimitCheck['limit'],
            'used' => $rateLimitCheck['used'],
            'message_id' => $dto->messageId,
        ]);

        $limitType = $rateLimitCheck['limit_type'] ?? 'lifetime';
        $limitMessage = "⚠️ *Nachrichtenlimit erreicht*\n\n";
        $limitMessage .= "Du hast dein Nachrichtenlimit von {$rateLimitCheck['limit']} Nachrichten ";

        if ('lifetime' === $limitType) {
            $limitMessage .= "erreicht.\n\n";
            $limitMessage .= 'Um weiterhin Synaplan zu nutzen, verifiziere deine Nummer oder upgrade zu einem kostenpflichtigen Plan.';
        } else {
            $resetTime = $rateLimitCheck['reset_at'] ?? null;
            if ($resetTime) {
                $resetDate = date('d.m.Y H:i', $resetTime);
                $limitMessage .= "für diesen Zeitraum erreicht.\n\n";
                $limitMessage .= "Dein Limit wird am $resetDate zurückgesetzt.";
            } else {
                $limitMessage .= 'erreicht.';
            }
        }

        $this->sendMessage($dto->from, $limitMessage, $dto->phoneNumberId);
        $this->markAsRead($dto->messageId, $dto->phoneNumberId);

        return [
            'success' => false,
            'message_id' => $dto->messageId,
            'error' => 'Rate limit exceeded',
        ];
    }

    /**
     * Extract initial message text from the WhatsApp message.
     * For media messages, this returns captions or placeholders that will be
     * replaced with transcribed/extracted content during media processing.
     */
    private function extractMessageText(IncomingMessageDto $dto): string
    {
        return match ($dto->type) {
            'text' => $dto->incomingMsg['text']['body'] ?? '',
            'image' => $dto->incomingMsg['image']['caption'] ?? '[Image]',
            'audio' => '[Audio message]', // Will be replaced with transcription
            'video' => $dto->incomingMsg['video']['caption'] ?? '[Video]', // Audio track will be transcribed
            'document' => $dto->incomingMsg['document']['caption'] ?? '[Document]',
            default => "[Unsupported message type: {$dto->type}]",
        };
    }

    private function extractMediaId(IncomingMessageDto $dto): ?string
    {
        return $dto->incomingMsg[$dto->type]['id'] ?? null;
    }

    private function storeMessageMetadata(Message $message, IncomingMessageDto $dto, User $user): void
    {
        $message->setMeta('channel', 'whatsapp');
        $message->setMeta('from_phone', $dto->from);
        $message->setMeta('original_user_id', (string) $user->getId());
        $message->setMeta('to_phone_number_id', $dto->phoneNumberId);
        if ($dto->displayPhoneNumber) {
            $message->setMeta('to_display_phone', $dto->displayPhoneNumber);
        }
        $message->setMeta('external_id', $dto->messageId);
        $message->setMeta('message_type', $dto->type);

        if (!empty($dto->value['contacts'][0]['profile']['name'])) {
            $message->setMeta('profile_name', $dto->value['contacts'][0]['profile']['name']);
        }
    }

    /**
     * Handle media download and text extraction.
     *
     * @return string|null Error message if failed, null on success
     */
    private function handleMediaDownload(Message $message, IncomingMessageDto $dto, string $mediaId, ?string $mediaUrl, int $effectiveUserId): ?string
    {
        $message->setMeta('media_id', $mediaId);

        try {
            if (!$mediaUrl) {
                $mediaUrl = $this->getMediaUrl($mediaId, $dto->phoneNumberId);
            }

            if (!$mediaUrl) {
                $this->logger->error('WhatsApp: Failed to get media URL', [
                    'media_id' => $mediaId,
                    'type' => $dto->type,
                ]);

                return 'Failed to retrieve media URL from WhatsApp';
            }

            $message->setMeta('media_url', $mediaUrl);
            $downloadResult = $this->downloadMedia($mediaId, $dto->phoneNumberId, $effectiveUserId);

            if (!$downloadResult || empty($downloadResult['file_path'])) {
                $this->logger->error('WhatsApp: Media download failed', [
                    'media_id' => $mediaId,
                    'type' => $dto->type,
                ]);

                return 'Failed to download media file';
            }

            $message->setFile(1);
            $message->setFilePath($downloadResult['file_path']);
            $message->setFileType($downloadResult['file_type'] ?? 'unknown');

            $this->logger->info('WhatsApp: Media downloaded successfully', [
                'media_id' => $mediaId,
                'type' => $dto->type,
                'file_path' => $downloadResult['file_path'],
                'file_type' => $downloadResult['file_type'],
                'size' => $downloadResult['size'] ?? 0,
            ]);

            // Extract text based on media type
            $extractedText = null;
            $extractionError = null;

            try {
                // For audio and video, extract text via Whisper
                if (in_array($dto->type, ['audio', 'video'], true)) {
                    $this->logger->info('WhatsApp: Extracting text from audio/video', [
                        'type' => $dto->type,
                        'file_type' => $downloadResult['file_type'],
                    ]);

                    [$extractedText, $extractionDetails] = $this->fileProcessor->extractText(
                        $downloadResult['file_path'],
                        $downloadResult['file_type'],
                        $effectiveUserId
                    );

                    if (!empty($extractedText)) {
                        $message->setFileText($extractedText);

                        // CRITICAL: For audio/video messages, replace placeholder text with transcription
                        // This ensures the AI receives the actual spoken content
                        $currentText = $message->getText();
                        $placeholders = ['[Audio message]', '[Audio]', '[Video]', '[Video message]'];

                        if (empty($currentText) || in_array($currentText, $placeholders, true)) {
                            $message->setText($extractedText);
                            $this->logger->info('WhatsApp: Replaced media placeholder with transcription', [
                                'type' => $dto->type,
                                'transcription_length' => strlen($extractedText),
                                'original_placeholder' => $currentText,
                            ]);
                        }
                    } else {
                        $this->logger->warning('WhatsApp: No text extracted from audio/video', [
                            'type' => $dto->type,
                            'details' => $extractionDetails ?? [],
                        ]);

                        // For video without audio track, this might be expected
                        if ('video' === $dto->type) {
                            // Check if video has no audio - set a more descriptive message
                            $message->setText('[Video without audio track]');
                        }
                    }
                } elseif ('image' === $dto->type) {
                    // For images, extract description via Vision AI
                    $this->logger->info('WhatsApp: Extracting description from image');

                    // Caption should be treated as user prompt input
                    $caption = $dto->incomingMsg['image']['caption'] ?? null;

                    [$extractedText, $extractionDetails] = $this->fileProcessor->extractText(
                        $downloadResult['file_path'],
                        $downloadResult['file_type'],
                        $effectiveUserId
                    );

                    if (!empty($extractedText)) {
                        $message->setFileText($extractedText);

                        $currentText = $message->getText();

                        if (!empty($caption)) {
                            // Caption becomes the user prompt; image text stays as file context
                            $message->setText($caption);
                            $this->logger->info('WhatsApp: Using image caption as prompt', [
                                'caption_length' => strlen($caption),
                                'description_length' => strlen($extractedText),
                            ]);
                        } elseif (empty($currentText) || '[Image]' === $currentText) {
                            // No caption: ask for a brief description using extracted context
                            $message->setText('Describe what you see in this image: '.$extractedText);
                            $this->logger->info('WhatsApp: Set image description as prompt', [
                                'description_length' => strlen($extractedText),
                            ]);
                        }
                    }
                } else {
                    // For documents and other types, use standard extraction
                    [$extractedText] = $this->fileProcessor->extractText(
                        $downloadResult['file_path'],
                        $downloadResult['file_type'],
                        $effectiveUserId
                    );

                    if (!empty($extractedText)) {
                        $message->setFileText($extractedText);
                    }
                }
            } catch (\Throwable $e) {
                $extractionError = $e->getMessage();
                $this->logger->error('WhatsApp: Text extraction failed', [
                    'type' => $dto->type,
                    'file_type' => $downloadResult['file_type'],
                    'error' => $extractionError,
                    'trace' => $e->getTraceAsString(),
                ]);

                // For audio messages, extraction failure is critical
                if ('audio' === $dto->type) {
                    return "Transcription failed: {$extractionError}";
                }
            }

            return null; // Success
        } catch (\Exception $e) {
            $this->logger->error('WhatsApp: Media handling failed', [
                'media_id' => $mediaId,
                'type' => $dto->type,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return "Media processing failed: {$e->getMessage()}";
        }
    }

    private function storeOutgoingMessage(User $user, IncomingMessageDto $dto, string $text, string $externalId): void
    {
        $outgoingMessage = new Message();
        $outgoingMessage->setUserId($user->getId());
        $outgoingMessage->setTrackingId(time());
        $outgoingMessage->setProviderIndex('WHATSAPP');
        $outgoingMessage->setUnixTimestamp(time());
        $outgoingMessage->setDateTime(date('YmdHis'));
        $outgoingMessage->setMessageType('WTSP');
        $outgoingMessage->setFile(0);
        $outgoingMessage->setTopic('CHAT');
        $outgoingMessage->setLanguage('en');
        $outgoingMessage->setText($text);
        $outgoingMessage->setDirection('OUT');
        $outgoingMessage->setStatus('sent');

        $this->em->persist($outgoingMessage);
        $this->em->flush();

        $outgoingMessage->setMeta('channel', 'whatsapp');
        $outgoingMessage->setMeta('to_phone', $dto->from);
        $outgoingMessage->setMeta('from_phone_number_id', $dto->phoneNumberId);
        if ($dto->displayPhoneNumber) {
            $outgoingMessage->setMeta('from_display_phone', $dto->displayPhoneNumber);
        }
        $outgoingMessage->setMeta('external_id', $externalId);
        $this->em->flush();
    }

    /**
     * Handle verification code sent via WhatsApp.
     * Returns array if code is found and processed, null if not a verification code.
     */
    private function handleVerificationCode(string $code, string $fromPhone, string $phoneNumberId, string $messageId): ?array
    {
        // Format phone number consistently
        $fromPhoneFormatted = preg_replace('/[^0-9]/', '', $fromPhone);

        // Find user with pending verification for this code
        $qb = $this->em->createQueryBuilder();
        $qb->select('u')
            ->from(User::class, 'u')
            ->where($qb->expr()->like('u.userDetails', ':codePattern'))
            ->setParameter('codePattern', '%"code":"'.$code.'"%');

        $users = $qb->getQuery()->getResult();

        foreach ($users as $user) {
            $userDetails = $user->getUserDetails();
            $verification = $userDetails['phone_verification'] ?? null;

            if (!$verification || $verification['code'] !== $code) {
                continue;
            }

            $expectedPhone = preg_replace('/[^0-9]/', '', $verification['phone_number']);

            if ($expectedPhone !== $fromPhoneFormatted) {
                $errorMessage = "❌ *Verification Failed*\n\nThis code was requested for a different phone number.\n\nPlease use the phone number you entered on the website.";
                $this->sendMessage($fromPhone, $errorMessage, $phoneNumberId);

                return [
                    'success' => false,
                    'message_id' => $messageId,
                    'error' => 'Phone number mismatch',
                ];
            }

            // Check expiry
            if (time() > ($verification['expires_at'] ?? 0)) {
                $expiredMessage = "❌ *Verification Code Expired*\n\nYour verification code has expired. Please request a new code on the website.\n\nCodes are valid for 5 minutes only.";
                $this->sendMessage($fromPhone, $expiredMessage, $phoneNumberId);

                unset($userDetails['phone_verification']);
                $user->setUserDetails($userDetails);
                $this->em->flush();

                return [
                    'success' => false,
                    'message_id' => $messageId,
                    'error' => 'Code expired',
                ];
            }

            // Success
            $userDetails['phone_number'] = $fromPhoneFormatted;
            $userDetails['phone_verified_at'] = time();
            unset($userDetails['phone_verification']);

            if ('ANONYMOUS' === $user->getUserLevel()) {
                $user->setUserLevel('NEW');
            }

            $user->setUserDetails($userDetails);
            $this->em->flush();

            $this->sendMessage($fromPhone, '✅ Erfolgreich verifiziert!', $phoneNumberId);

            return [
                'success' => true,
                'message_id' => $messageId,
                'verified' => true,
                'user_id' => $user->getId(),
            ];
        }

        return null;
    }

    /**
     * Verify webhook signature.
     */
    public function verifyWebhookSignature(string $payload, string $signature, string $verifyToken): bool
    {
        $expectedSignature = hash_hmac('sha256', $payload, $verifyToken);

        // Remove 'sha256=' prefix if present
        $signature = str_replace('sha256=', '', $signature);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Format phone number for WhatsApp (remove +, spaces, dashes).
     */
    private function formatPhoneNumber(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone);
    }

    /**
     * Get media URL from media ID.
     */
    public function getMediaUrl(string $mediaId, string $phoneNumberId): ?string
    {
        if (!$this->isAvailable()) {
            return null;
        }

        if (empty($phoneNumberId)) {
            throw new \InvalidArgumentException('Phone Number ID is required for getting media URL.');
        }

        $url = sprintf(
            'https://graph.facebook.com/%s/%s',
            $this->apiVersion,
            $mediaId
        );

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->accessToken,
                ],
            ]);

            $data = $response->toArray();

            return $data['url'] ?? null;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get WhatsApp media URL', [
                'media_id' => $mediaId,
                'phone_number_id' => $phoneNumberId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Download media from WhatsApp and save locally.
     * Returns array with file_path and file_type.
     *
     * @param int $userId User ID to store file under (uses user's directory structure)
     */
    public function downloadMedia(string $mediaId, string $phoneNumberId, int $userId): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        if (empty($phoneNumberId)) {
            throw new \InvalidArgumentException('Phone Number ID is required for downloading media.');
        }

        try {
            // First get the media URL
            $mediaUrl = $this->getMediaUrl($mediaId, $phoneNumberId);
            if (!$mediaUrl) {
                return null;
            }

            // Download the media
            $response = $this->httpClient->request('GET', $mediaUrl, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->accessToken,
                ],
            ]);

            // Check Content-Length header before downloading (if available)
            $headers = $response->getHeaders();
            if (isset($headers['content-length'][0])) {
                $contentLength = (int) $headers['content-length'][0];
                if ($contentLength > self::MAX_FILE_SIZE) {
                    $sizeMB = round($contentLength / 1024 / 1024, 2);
                    $maxMB = self::MAX_FILE_SIZE / 1024 / 1024;
                    $this->logger->error('WhatsApp media file too large (Content-Length)', [
                        'media_id' => $mediaId,
                        'size_mb' => $sizeMB,
                        'max_mb' => $maxMB,
                    ]);

                    return null;
                }
            }

            $content = $response->getContent();
            $contentType = $headers['content-type'][0] ?? 'application/octet-stream';

            // Validate actual downloaded size
            $actualSize = strlen($content);
            if ($actualSize > self::MAX_FILE_SIZE) {
                $sizeMB = round($actualSize / 1024 / 1024, 2);
                $maxMB = self::MAX_FILE_SIZE / 1024 / 1024;
                $this->logger->error('WhatsApp media file too large (actual size)', [
                    'media_id' => $mediaId,
                    'size_mb' => $sizeMB,
                    'max_mb' => $maxMB,
                ]);

                return null;
            }

            // Determine file extension from content type
            $extension = $this->getExtensionFromMimeType($contentType);

            // Validate file extension
            if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
                $this->logger->error('WhatsApp media has disallowed file type', [
                    'media_id' => $mediaId,
                    'extension' => $extension,
                    'mime_type' => $contentType,
                    'allowed_extensions' => implode(', ', self::ALLOWED_EXTENSIONS),
                ]);

                return null;
            }

            // Generate unique filename with WhatsApp prefix
            $filename = 'whatsapp_'.time().'_'.bin2hex(random_bytes(8)).'.'.$extension;

            // Use user-based directory structure (same as regular uploads)
            $year = date('Y');
            $month = date('m');
            $userBase = $this->userUploadPathBuilder->buildUserBaseRelativePath($userId);
            $relativePath = $userBase.'/'.$year.'/'.$month.'/'.$filename;
            $fullPath = $this->uploadsDir.'/'.$relativePath;

            // Create directory if it doesn't exist
            $dir = dirname($fullPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            // Save file
            file_put_contents($fullPath, $content);

            $sizeMB = round($actualSize / 1024 / 1024, 2);
            $this->logger->info('WhatsApp media downloaded and saved', [
                'media_id' => $mediaId,
                'file_path' => $relativePath,
                'size_bytes' => $actualSize,
                'size_mb' => $sizeMB,
                'mime_type' => $contentType,
                'validated' => true,
            ]);

            return [
                'file_path' => $relativePath,
                'file_type' => $extension,
                'mime_type' => $contentType,
                'size' => strlen($content),
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to download WhatsApp media', [
                'media_id' => $mediaId,
                'phone_number_id' => $phoneNumberId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get file extension from MIME type.
     * Returns the extension or 'unknown' for unmapped MIME types.
     * Note: Unknown types will be rejected by the ALLOWED_EXTENSIONS check.
     */
    private function getExtensionFromMimeType(string $mimeType): string
    {
        $mimeMap = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'audio/ogg' => 'ogg',
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'm4a',
            'audio/amr' => 'amr',
            'audio/opus' => 'opus',
            'audio/wav' => 'wav',
            'audio/webm' => 'webm',
            'video/mp4' => 'mp4',
            'video/3gpp' => '3gp',
            'video/webm' => 'webm',
            'application/pdf' => 'pdf',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/msword' => 'doc',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'text/plain' => 'txt',
            'text/csv' => 'csv',
            'text/markdown' => 'md',
        ];

        // Return 'unknown' for unmapped types - will be caught by ALLOWED_EXTENSIONS check
        return $mimeMap[$mimeType] ?? 'unknown';
    }
}
