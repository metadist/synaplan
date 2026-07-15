<?php

namespace App\Service;

use App\AI\Service\AiFacade;
use App\AI\Stream\StreamChunk;
use App\DTO\WhatsApp\IncomingMessageDto;
use App\Entity\Chat;
use App\Entity\File;
use App\Entity\Message;
use App\Entity\User;
use App\Service\File\FileProcessor;
use App\Service\File\UserUploadPathBuilder;
use App\Service\Message\MessageProcessor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
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
final class WhatsAppService
{
    private const MAX_FILE_SIZE = 128 * 1024 * 1024; // 128 MB (same as FileStorageService)

    // Allowed file extensions (same as FileStorageService for consistency)
    private const ALLOWED_EXTENSIONS = [
        'pdf', 'docx', 'doc', 'xlsx', 'xls', 'pptx', 'ppt', 'txt', 'md', 'csv',
        'jpg', 'jpeg', 'png', 'gif', 'webp',
        'mp3', 'mp4', 'wav', 'ogg', 'm4a', 'webm',
        'amr', 'opus', '3gp', // WhatsApp-specific audio/video formats
    ];

    /**
     * Cache TTL for duplicate detection (5 minutes).
     * WhatsApp retries typically happen within 1-2 minutes.
     */
    private const DUPLICATE_CACHE_TTL = 300;

    /**
     * Allow-list of WhatsApp webhook message types the AI pipeline knows
     * how to handle. Anything outside this set is silently acknowledged
     * to Meta (HTTP 200) without persisting a Message or invoking the
     * AI — see {@see handleIncomingMessage()} and issue #633.
     *
     * Filtered-out types include `reaction`, `poll`, `poll_vote`,
     * `ephemeral`, `request_welcome`, `system`, `unsupported`,
     * `interactive`, `button` and `order`. These either carry no
     * user-actionable text payload (reactions, polls) or require
     * dedicated handlers the platform does not yet implement
     * (interactive buttons, catalog orders). Acknowledging them
     * silently keeps Meta from retrying the webhook and stops the
     * "one poll → seven AI replies" cascade described in #633.
     */
    private const SUPPORTED_MESSAGE_TYPES = [
        'text',
        'image',
        'sticker',
        'audio',
        'video',
        'document',
        'location',
        'contacts',
    ];

    private const DEFAULT_GRAPH_BASE = 'https://graph.facebook.com';

    private string $accessToken;
    private bool $enabled;
    private string $apiVersion = 'v21.0';
    private string $graphBaseUrl;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private EntityManagerInterface $em,
        private RateLimitService $rateLimitService,
        private MessageProcessor $messageProcessor,
        private FileProcessor $fileProcessor,
        private UserUploadPathBuilder $userUploadPathBuilder,
        private AiFacade $aiFacade,
        private DiscordNotificationService $discord,
        private CacheInterface $cache,
        private LockFactory $lockFactory,
        private EmailChatService $emailChatService,
        private UserMemoryService $memoryService,
        string $whatsappAccessToken,
        bool $whatsappEnabled,
        private string $uploadsDir,
        private int $whatsappUserId,
        private string $appUrl = '',
        ?string $whatsappGraphApiBaseUrl = null,
    ) {
        $this->accessToken = $whatsappAccessToken;
        $this->enabled = $whatsappEnabled;
        $this->graphBaseUrl = (null !== $whatsappGraphApiBaseUrl && '' !== $whatsappGraphApiBaseUrl)
            ? rtrim($whatsappGraphApiBaseUrl, '/')
            : self::DEFAULT_GRAPH_BASE;
    }

    /**
     * Check if WhatsApp is available.
     */
    public function isAvailable(): bool
    {
        return $this->enabled && !empty($this->accessToken);
    }

    /**
     * Check if message was already processed (duplicate detection).
     *
     * Uses lock + cache for atomic check-and-set to prevent race conditions
     * when multiple webhook requests arrive simultaneously.
     *
     * @return array|null Returns duplicate result array if duplicate, null if new message
     */
    private function checkAndMarkAsDuplicate(IncomingMessageDto $dto): ?array
    {
        $cacheKey = 'whatsapp_msg_'.hash('sha256', $dto->messageId);
        $lockKey = 'whatsapp_lock_'.hash('sha256', $dto->messageId);

        try {
            // Acquire lock to prevent race condition between concurrent requests
            $lock = $this->lockFactory->createLock($lockKey, ttl: 30.0, autoRelease: true);

            if (!$lock->acquire()) {
                // Another request is processing this message - treat as duplicate
                $this->logger->info('WhatsApp: Message locked by another process, treating as duplicate', [
                    'message_id' => $dto->messageId,
                    'from' => $dto->from,
                ]);

                return [
                    'success' => true,
                    'message_id' => $dto->messageId,
                    'duplicate' => true,
                    'response_sent' => false,
                ];
            }

            // Atomic duplicate detection using unique token pattern.
            // CacheInterface::get() calls the callback only on cache MISS.
            // On cache HIT, it returns the previously stored value.
            // By comparing the returned value to our unique token, we know
            // if WE created the entry (new message) or someone else did (duplicate).
            $myToken = bin2hex(random_bytes(8));
            $storedToken = $this->cache->get($cacheKey, function (ItemInterface $item) use ($myToken): string {
                $item->expiresAfter(self::DUPLICATE_CACHE_TTL);

                return $myToken;
            });

            if ($storedToken !== $myToken) {
                // Cache HIT - another request already stored a different token
                $this->logger->info('WhatsApp: Duplicate message detected (cache hit)', [
                    'message_id' => $dto->messageId,
                    'from' => $dto->from,
                ]);

                $lock->release();

                return [
                    'success' => true,
                    'message_id' => $dto->messageId,
                    'duplicate' => true,
                    'response_sent' => false,
                ];
            }

            // Cache MISS - our callback ran, this is a new message
            $lock->release();

            return null;
        } catch (\Throwable $e) {
            // Graceful degradation: if cache/lock fails, process the message anyway
            // Better to risk a duplicate than to drop messages entirely
            $this->logger->warning('WhatsApp: Duplicate detection failed, processing anyway', [
                'message_id' => $dto->messageId,
                'from' => $dto->from,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Drop non-actionable webhook message types before they enter the
     * AI pipeline. Returns a webhook-success payload when the type is
     * not in {@see SUPPORTED_MESSAGE_TYPES}, or `null` to continue
     * normal processing.
     *
     * We also fire-and-forget a read receipt so the user's WhatsApp
     * thread doesn't show an unread bubble for a poll/reaction the
     * platform legitimately ignored — failures here are logged but
     * never block the webhook acknowledgement (Meta would otherwise
     * retry, compounding the original bug from issue #633).
     */
    private function handleUnsupportedTypeIfNeeded(IncomingMessageDto $dto): ?array
    {
        if (in_array($dto->type, self::SUPPORTED_MESSAGE_TYPES, true)) {
            return null;
        }

        $this->logger->info('WhatsApp: Skipping unsupported message type', [
            'message_id' => $dto->messageId,
            'from' => $dto->from,
            'type' => $dto->type,
        ]);

        if ($this->isAvailable() && '' !== $dto->phoneNumberId) {
            try {
                $this->markAsRead($dto->messageId, $dto->phoneNumberId);
            } catch (\Throwable $e) {
                // Read receipts are best-effort. A failure here must
                // not bubble up: the webhook MUST still return 200
                // so Meta doesn't retry and re-trigger the cascade.
                $this->logger->warning('WhatsApp: Failed to mark unsupported message as read', [
                    'message_id' => $dto->messageId,
                    'type' => $dto->type,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'success' => true,
            'message_id' => $dto->messageId,
            'skipped' => true,
            'reason' => 'unsupported_type',
            'type' => $dto->type,
            'response_sent' => false,
        ];
    }

    /**
     * Send text message.
     *
     * Automatically splits messages exceeding WhatsApp's 4096-character limit
     * into multiple sequential messages, breaking at paragraph boundaries.
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

        // Convert standard Markdown to WhatsApp-compatible formatting
        $formattedMessage = $this->convertToWhatsAppMarkdown($message);

        // WhatsApp Business API limit: 4096 characters per text message
        $maxLength = 4096;

        if (mb_strlen($formattedMessage) <= $maxLength) {
            return $this->sendSingleMessage($to, $formattedMessage, $phoneNumberId);
        }

        // Split into chunks at paragraph boundaries
        $chunks = $this->splitMessageIntoChunks($formattedMessage, $maxLength);

        $this->logger->info('WhatsApp: Splitting long message into chunks', [
            'to' => $to,
            'total_length' => mb_strlen($formattedMessage),
            'chunks' => count($chunks),
        ]);

        $lastResult = ['success' => false, 'error' => 'No chunks to send'];
        foreach ($chunks as $i => $chunk) {
            $lastResult = $this->sendSingleMessage($to, $chunk, $phoneNumberId);
            if (!$lastResult['success']) {
                $this->logger->error('WhatsApp: Failed to send chunk', [
                    'chunk_index' => $i,
                    'chunk_length' => mb_strlen($chunk),
                    'error' => $lastResult['error'] ?? 'Unknown',
                ]);

                return $lastResult;
            }

            // Small delay between chunks to maintain order
            if ($i < count($chunks) - 1) {
                usleep(300000); // 300ms
            }
        }

        return $lastResult;
    }

    /**
     * Send a single text message (no length validation).
     */
    private function sendSingleMessage(string $to, string $formattedMessage, string $phoneNumberId): array
    {
        $url = sprintf(
            '%s/%s/%s/messages',
            $this->graphBaseUrl,
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
                        'body' => $formattedMessage,
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
     * Split a long message into chunks at paragraph/line boundaries.
     *
     * @return string[]
     */
    private function splitMessageIntoChunks(string $text, int $maxLength): array
    {
        $chunks = [];
        $remaining = $text;

        while (mb_strlen($remaining) > $maxLength) {
            $chunk = mb_substr($remaining, 0, $maxLength);

            // Try to break at a paragraph boundary (double newline)
            $breakPos = mb_strrpos($chunk, "\n\n");

            // Fall back to single newline
            if (false === $breakPos || $breakPos < $maxLength * 0.3) {
                $breakPos = mb_strrpos($chunk, "\n");
            }

            // Fall back to space
            if (false === $breakPos || $breakPos < $maxLength * 0.3) {
                $breakPos = mb_strrpos($chunk, ' ');
            }

            // Last resort: hard cut
            if (false === $breakPos || $breakPos < $maxLength * 0.3) {
                $breakPos = $maxLength;
            }

            $chunks[] = mb_substr($remaining, 0, $breakPos);
            $remaining = ltrim(mb_substr($remaining, $breakPos));
        }

        if ('' !== $remaining) {
            $chunks[] = $remaining;
        }

        return $chunks;
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
            '%s/%s/%s/messages',
            $this->graphBaseUrl,
            $this->apiVersion,
            $phoneNumberId
        );

        $mediaPayload = [
            'link' => $mediaUrl,
        ];

        // WhatsApp API requires 'filename' for document type
        if ('document' === $mediaType) {
            $mediaPayload['filename'] = basename(parse_url($mediaUrl, PHP_URL_PATH)) ?: 'document';
        }

        // Convert caption markdown to WhatsApp format if present
        if ($caption && in_array($mediaType, ['image', 'video', 'document'])) {
            $mediaPayload['caption'] = $this->convertToWhatsAppMarkdown($caption);
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
            '%s/%s/%s/messages',
            $this->graphBaseUrl,
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
     * Mark message as read, optionally showing a typing indicator.
     *
     * When $withTypingIndicator is true, the user sees blue checkmarks AND
     * a "typing…" animation. The animation auto-dismisses after 25 seconds
     * or when a response message is sent — whichever comes first.
     *
     * @param string $messageId           The WhatsApp message ID to mark as read
     * @param string $phoneNumberId       The WhatsApp Phone Number ID (extracted from webhook metadata)
     * @param bool   $withTypingIndicator Show typing animation while preparing a response
     */
    public function markAsRead(string $messageId, string $phoneNumberId, bool $withTypingIndicator = false): array
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('WhatsApp service is not available');
        }

        if (empty($phoneNumberId)) {
            throw new \InvalidArgumentException('Phone Number ID is required and must be provided dynamically from webhook metadata');
        }

        $url = sprintf(
            '%s/%s/%s/messages',
            $this->graphBaseUrl,
            $this->apiVersion,
            $phoneNumberId
        );

        $payload = [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $messageId,
        ];

        if ($withTypingIndicator) {
            $payload['typing_indicator'] = ['type' => 'text'];
        }

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            return [
                'success' => true,
                'data' => $response->toArray(),
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to mark WhatsApp message as read', [
                'message_id' => $messageId,
                'typing_indicator' => $withTypingIndicator,
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
        // DUPLICATE DETECTION: WhatsApp may send the same webhook multiple times on retries.
        // Uses lock + cache for atomic check-and-set to prevent race conditions.
        $duplicateResult = $this->checkAndMarkAsDuplicate($dto);
        if (null !== $duplicateResult) {
            return $duplicateResult;
        }

        // Issue #633: silently acknowledge unsupported webhook types
        // (poll, reaction, system, …) so the AI pipeline doesn't fire
        // for non-user-actionable events. Duplicate detection cannot
        // help here — each poll vote / reaction lands with a distinct
        // wamid, so without this filter a single poll cascades into
        // 7+ "[Unsupported message type: …]" messages and 7+ replies.
        $unsupportedResult = $this->handleUnsupportedTypeIfNeeded($dto);
        if (null !== $unsupportedResult) {
            return $unsupportedResult;
        }

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

        // 4. Resolve WhatsApp chat context (enables conversation history)
        $chat = $this->emailChatService->findOrCreateWhatsAppChat($user, $dto->from);

        // 5. Create database record
        // Always use the chat owner's userId so message.userId stays aligned
        // with chat.userId. This is required for ownership checks in
        // findChatHistory (which JOINs on chat.userId) and for consistent
        // authorization across all queries that filter by userId.
        // The effectiveUserId is only needed for service-level operations
        // (media download paths, TTS generation) below.
        $message = new Message();
        $message->setUserId($user->getId());
        $message->setChat($chat);
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

        $this->em->persist($message);
        $this->em->flush();

        // 5. Metadata and Media Handling (must be after flush so message has ID)
        // Store input type metadata for response mode selection
        $message->setMeta('whatsapp_input_type', $dto->type);
        $message->setMeta('whatsapp_should_send_audio', $shouldSendAudioResponse ? '1' : '0');
        $this->storeMessageMetadata($message, $dto, $user);

        $mediaDownloadError = null;
        if ($mediaId) {
            $mediaDownloadError = $this->handleMediaDownload($message, $dto, $mediaId, $mediaUrl, $effectiveUserId);
        }

        $this->em->flush();

        // Check for media download errors and send user-friendly error message
        if ($mediaDownloadError) {
            $this->sendErrorMessage($dto, $mediaDownloadError);

            // Discord notification: Media download failed
            $this->discord->notifyWhatsAppError(
                'media_download',
                $dto->from,
                $message->getText() ?: '[Media message]',
                $mediaDownloadError,
                [
                    'message_type' => $dto->type,
                    'file_type' => $dto->incomingMsg[$dto->type]['mime_type'] ?? 'unknown',
                ],
                $user->getId(),
            );

            return [
                'success' => false,
                'message_id' => $dto->messageId,
                'error' => $mediaDownloadError,
            ];
        }

        // 6. Mark as read + show typing indicator while AI processes the request
        $this->markAsRead($dto->messageId, $dto->phoneNumberId, withTypingIndicator: true);

        // 7. AI Pipeline Processing (use streaming mode to support TTS/media generation)
        $collectedResponse = '';
        $streamCallback = function (string|array $chunk, array $metadata = []) use (&$collectedResponse): void {
            // Centralized chunk filter: only visible answer text is collected,
            // reasoning/finish chunks are dropped (issue #1067).
            $collectedResponse .= StreamChunk::visibleText($chunk);
        };

        // For image messages WITHOUT caption, force image description mode
        // If there's a caption (user question), let the classifier route to chat for an answer
        $processingOptions = [];
        if ($isImageMessage) {
            $imageCaption = $dto->incomingMsg['image']['caption'] ?? null;
            if (empty($imageCaption)) {
                // No caption: force brief image description
                $processingOptions['force_image_description'] = true;
            }
            // With caption: let classifier route normally so AI can ANSWER the question
        }

        $result = $this->messageProcessor->processStream($message, $streamCallback, null, $processingOptions);

        if (!$result['success']) {
            $errorMessage = $result['error'] ?? 'Processing failed';
            $this->sendErrorMessage($dto, $errorMessage);

            // Discord notification: Processing failed
            $this->discord->notifyWhatsAppError(
                'processing',
                $dto->from,
                $message->getText(),
                $errorMessage,
                ['message_type' => $dto->type],
                $user->getId(),
            );

            return [
                'success' => false,
                'message_id' => $dto->messageId,
                'error' => $errorMessage,
            ];
        }

        $responseText = $result['response']['content'] ?? $collectedResponse;
        $responseText = $this->memoryService->resolveMemoryTags($responseText, $user);
        $metadata = $result['response']['metadata'] ?? [];
        $classification = is_array($result['classification'] ?? null) ? $result['classification'] : null;
        $fileData = $metadata['file'] ?? null;

        // Issue #652: WhatsApp responses must surface web search citations
        // alongside the answer instead of forcing the user to ask "wo sind die
        // quellen?". We persist the search metadata on the incoming message so
        // the platform chat view (ChatController::getMessages) and the
        // outgoing message both expose the sources, mirroring the streaming
        // flow used by the web UI (StreamController). For text replies we
        // also append a short, WhatsApp-friendly source list to the message
        // body so mobile users see the citations directly in WhatsApp.
        $searchResults = $result['search_results'] ?? null;
        $searchResultsItems = $searchResults['results'] ?? [];
        $hasSearchResults = is_array($searchResultsItems) && [] !== $searchResultsItems;
        if ($hasSearchResults) {
            $searchQuery = (string) ($searchResults['query'] ?? '');
            $searchCount = count($searchResultsItems);
            $message->setMeta('web_search_query', $searchQuery);
            $message->setMeta('web_search_results_count', (string) $searchCount);
            $this->em->flush();
        }

        $this->rateLimitService->recordUsage($user, 'MESSAGES', [
            'provider' => $metadata['provider'] ?? 'unknown',
            'model' => $metadata['model'] ?? 'unknown',
            'usage' => $metadata['usage'] ?? [],
            'model_id' => $metadata['model_id'] ?? null,
            'source' => 'WHATSAPP',
            'response_text' => $responseText,
            'input_text' => $message->getText(),
        ]);

        // 8. Send Response based on input type
        $responseSent = false;

        // Issue #652: For text-style replies we surface the citations inline.
        // Media replies (image/video/AI audio) keep their caption clean —
        // WhatsApp caps captions at 1024 chars and the platform view still
        // shows the sources via the metadata mirrored in storeOutgoingMessage.
        $textResponseWithSources = $hasSearchResults
            ? $this->appendWhatsAppSources($responseText, $searchResultsItems)
            : $responseText;

        // PRIORITY 1: Check if AI generated media (image, video, audio, or
        // document — e.g. .ics / .docx from a multi-task plan)
        if ($fileData) {
            $generatedMediaType = $fileData['type'] ?? null;
            $mediaPath = $fileData['path'] ?? null;

            if ($mediaPath && !empty($this->appUrl) && in_array($generatedMediaType, ['audio', 'video', 'image', 'document'], true)) {
                $mediaUrl = rtrim($this->appUrl, '/').'/'.ltrim($mediaPath, '/');

                $this->logger->info('WhatsApp: Sending AI-generated media response', [
                    'to' => $dto->from,
                    'media_type' => $generatedMediaType,
                    'media_url' => $mediaUrl,
                ]);

                // The response text must never be lost: WhatsApp captions only
                // exist on image/video/document and cap at 1024 chars — audio
                // has no caption at all. Short text rides as the caption
                // (kept clean of the sources block, issue #652); otherwise the
                // FULL text (incl. sources) goes out as its own message BEFORE
                // the media, mirroring how the web chat shows text + media.
                $caption = null;
                $textSentSeparately = false;
                $textMessageId = '';
                $canUseCaption = in_array($generatedMediaType, ['image', 'video', 'document'], true)
                    && !empty($responseText)
                    && mb_strlen($responseText) <= 1024;

                if ($canUseCaption) {
                    $caption = $responseText;
                } elseif ('' !== trim($textResponseWithSources)) {
                    $textSend = $this->sendMessage($dto->from, $textResponseWithSources, $dto->phoneNumberId);
                    $textSentSeparately = !empty($textSend['success']);
                    $textMessageId = (string) ($textSend['message_id'] ?? '');
                    if (!$textSentSeparately) {
                        $this->logger->warning('WhatsApp: Failed to send response text before media', [
                            'to' => $dto->from,
                            'error' => $textSend['error'] ?? 'Unknown',
                        ]);
                    }
                }

                $sendResult = $this->sendMedia($dto->from, $generatedMediaType, $mediaUrl, $dto->phoneNumberId, $caption);
                if ($sendResult['success']) {
                    // Documents have no dedicated media quota — the MESSAGES
                    // usage recorded above already covers the turn.
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

                    $placeholderText = match ($generatedMediaType) {
                        'image' => '[Image response]',
                        'video' => '[Video response]',
                        'document' => '[Document response]',
                        default => '[Audio response]', // audio is the remaining case
                    };
                    // Persist the generated media path so the web chat
                    // history surfaces the player (issue #626) and mirror
                    // the web-search citations onto the outgoing row so the
                    // platform chat view exposes them too (issue #652).
                    // WhatsApp itself already received the asset via
                    // sendMedia(), but the cross-channel mirror lives in the
                    // DB row.
                    $this->storeOutgoingMessage(
                        $user,
                        $dto,
                        $responseText ?: $placeholderText,
                        $sendResult['message_id'],
                        $chat,
                        $searchResults,
                        ['path' => $mediaPath, 'type' => $generatedMediaType],
                        $metadata,
                        $classification,
                    );
                    $responseSent = true;

                    // Discord notification: AI media generated and sent
                    $this->discord->notifyWhatsAppSuccess(
                        $generatedMediaType,
                        $dto->from,
                        $message->getText(),
                        $responseText ?: $placeholderText,
                        [
                            'provider' => $metadata['provider'] ?? null,
                            'model' => $metadata['model'] ?? null,
                            'media_type' => $generatedMediaType,
                        ],
                        $user->getId(),
                    );
                } else {
                    $this->logger->warning('WhatsApp: Failed to send AI media, falling back', [
                        'media_type' => $generatedMediaType,
                        'error' => $sendResult['error'] ?? 'Unknown',
                    ]);

                    // Discord notification: Failed to send AI media
                    $this->discord->notifyWhatsAppError(
                        'send_failed',
                        $dto->from,
                        $message->getText(),
                        $sendResult['error'] ?? 'Unknown error',
                        ['media_type' => $generatedMediaType],
                        $user->getId(),
                    );

                    // The text already reached the user as its own message —
                    // persist it and don't let PRIORITY 3 send it twice.
                    if ($textSentSeparately) {
                        $this->storeOutgoingMessage(
                            $user,
                            $dto,
                            $responseText,
                            $textMessageId,
                            $chat,
                            $searchResults,
                            null,
                            $metadata,
                            $classification,
                        );
                        $responseSent = true;
                    }
                }
            }

            // Multi-task routing (Sprint 5): a multi-node plan can produce more
            // than one output file. metadata['file'] (index 0) was just handled
            // above; send the remaining files as separate WhatsApp media
            // messages (documents included — .ics/.docx outputs were silently
            // dropped before). Only the executor sets metadata['files'], so
            // single-file turns are unaffected. Best-effort: one failed extra
            // must not abort the remaining sends.
            $extraFiles = $metadata['files'] ?? null;
            if (is_array($extraFiles) && count($extraFiles) > 1 && !empty($this->appUrl)) {
                foreach (array_values($extraFiles) as $idx => $taskFile) {
                    if (0 === $idx || !is_array($taskFile)) {
                        continue;
                    }
                    $type = $taskFile['type'] ?? null;
                    $path = $taskFile['path'] ?? null;
                    if (!is_string($path) || '' === $path || !in_array($type, ['audio', 'video', 'image', 'document'], true)) {
                        continue;
                    }
                    $url = rtrim($this->appUrl, '/').'/'.ltrim($path, '/');
                    $this->logger->info('WhatsApp: Sending additional multi-task media', [
                        'to' => $dto->from,
                        'media_type' => $type,
                        'index' => $idx,
                    ]);

                    try {
                        $extraResult = $this->sendMedia($dto->from, $type, $url, $dto->phoneNumberId, null);
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
        }

        // PRIORITY 2: Audio/Video input → Generate TTS response
        if (!$responseSent && $shouldSendAudioResponse && !empty($responseText) && !empty($this->appUrl)) {
            $detectedLanguage = $message->getLanguage() ?: 'en';
            $ttsResult = $this->generateTtsResponse($responseText, $effectiveUserId, $detectedLanguage);

            if ($ttsResult) {
                $audioUrl = rtrim($this->appUrl, '/').'/api/v1/files/uploads/'.$ttsResult['relativePath'];

                $this->logger->info('WhatsApp: Sending TTS response for audio/video message', [
                    'to' => $dto->from,
                    'audio_url' => $audioUrl,
                    'response_length' => strlen($responseText),
                ]);

                $sendResult = $this->sendMedia($dto->from, 'audio', $audioUrl, $dto->phoneNumberId);
                if ($sendResult['success']) {
                    // Persist TTS audio on the DB row so the web chat
                    // history surfaces the audio player (issue #626) and
                    // mirror the web-search citations onto the outgoing row
                    // so the platform chat view exposes them too
                    // (issue #652).
                    $ttsServePath = '/api/v1/files/uploads/'.$ttsResult['relativePath'];
                    $this->storeOutgoingMessage(
                        $user,
                        $dto,
                        $responseText,
                        $sendResult['message_id'],
                        $chat,
                        $searchResults,
                        ['path' => $ttsServePath, 'type' => 'audio'],
                        $metadata,
                        $classification,
                    );
                    $responseSent = true;

                    // Discord notification: TTS response sent
                    $this->discord->notifyWhatsAppSuccess(
                        'tts',
                        $dto->from,
                        $message->getText(),
                        $responseText,
                        [
                            'provider' => $ttsResult['provider'] ?? null,
                            'model' => $ttsResult['model'] ?? null,
                            'media_type' => 'audio',
                        ],
                        $user->getId(),
                    );
                } else {
                    $this->logger->warning('WhatsApp: TTS send failed, falling back to text', [
                        'error' => $sendResult['error'] ?? 'Unknown',
                    ]);

                    // Discord notification: TTS send failed
                    $this->discord->notifyWhatsAppError(
                        'send_failed',
                        $dto->from,
                        $message->getText(),
                        $sendResult['error'] ?? 'Unknown error',
                        ['media_type' => 'audio'],
                        $user->getId(),
                    );
                }
            } else {
                $this->logger->warning('WhatsApp: TTS generation failed, falling back to text');

                // Discord notification: TTS generation failed
                $this->discord->notifyWhatsAppError(
                    'tts',
                    $dto->from,
                    $message->getText(),
                    'TTS generation failed',
                    ['message_type' => $dto->type],
                    $user->getId(),
                );
            }
        }

        // PRIORITY 3: Send text response (fallback or for text/image/video input)
        if (!$responseSent && !empty($textResponseWithSources)) {
            $sendResult = $this->sendMessage($dto->from, $textResponseWithSources, $dto->phoneNumberId);
            if ($sendResult['success']) {
                // Persist the response WITHOUT the appended source block — the
                // platform UI renders sources from metadata, so duplicating
                // them in the stored text would clutter the history view.
                $this->storeOutgoingMessage(
                    $user,
                    $dto,
                    $responseText,
                    $sendResult['message_id'],
                    $chat,
                    $searchResults,
                    null,
                    $metadata,
                    $classification,
                );
                $responseSent = true;

                // Discord notification: Text response sent
                $this->discord->notifyWhatsAppSuccess(
                    'text',
                    $dto->from,
                    $message->getText(),
                    $responseText,
                    [
                        'provider' => $metadata['provider'] ?? null,
                        'model' => $metadata['model'] ?? null,
                    ],
                    $user->getId(),
                );
            } else {
                $this->logger->error('WhatsApp: Failed to send text response', [
                    'error' => $sendResult['error'] ?? 'Unknown',
                ]);

                // Discord notification: Text send failed
                $this->discord->notifyWhatsAppError(
                    'send_failed',
                    $dto->from,
                    $message->getText(),
                    $sendResult['error'] ?? 'Unknown error',
                    ['message_type' => 'text'],
                    $user->getId(),
                );
            }
        }

        // If no response was sent, log an error
        if (!$responseSent) {
            $this->logger->error('WhatsApp: No response sent to user', [
                'message_id' => $dto->messageId,
                'from' => $dto->from,
                'response_text_length' => strlen($responseText),
            ]);

            // Discord notification: No response sent
            $this->discord->notifyWhatsAppError(
                'processing',
                $dto->from,
                $message->getText(),
                'No response could be sent to user',
                ['message_type' => $dto->type],
                $user->getId(),
            );
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
     * @param string $text     The text to synthesize
     * @param int    $userId   User ID for provider selection
     * @param string $language Language code (en, de, es, etc.) for voice selection
     *
     * @return array|null Result with relativePath, or null on failure
     */
    private function generateTtsResponse(string $text, int $userId, string $language = 'en'): ?array
    {
        // Sanitize: strip [Memory:ID], markdown, code blocks, <think> tags
        $text = TtsTextSanitizer::sanitize($text);

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
                'language' => $language,
            ]);

            $result = $this->aiFacade->synthesize($text, $userId, [
                'format' => 'mp3',
                'language' => $language,
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
     *
     * The `default` branch is defense-in-depth only: the outer
     * {@see handleUnsupportedTypeIfNeeded()} filter (issue #633) already
     * short-circuits any type that is not in {@see SUPPORTED_MESSAGE_TYPES},
     * so this method should never see one in production. The fallback
     * string stays around so direct callers (e.g. reflection-driven
     * unit tests) still get a deterministic value instead of an
     * UnhandledMatchError.
     */
    private function extractMessageText(IncomingMessageDto $dto): string
    {
        return match ($dto->type) {
            'text' => $dto->incomingMsg['text']['body'] ?? '',
            'image' => $dto->incomingMsg['image']['caption'] ?? '[Image]',
            'sticker' => '[Sticker]', // Treated like images, analyzed via Vision AI
            'audio' => '[Audio message]', // Will be replaced with transcription
            'video' => $dto->incomingMsg['video']['caption'] ?? '[Video]', // Audio track will be transcribed
            'document' => $dto->incomingMsg['document']['caption'] ?? '[Document]',
            'location' => $this->formatLocationMessage($dto),
            'contacts' => $this->formatContactsMessage($dto),
            default => "[Unsupported message type: {$dto->type}]",
        };
    }

    /**
     * Format a location message into a readable text for AI processing.
     * WhatsApp location payload: { latitude, longitude, name?, address?, url? }.
     */
    private function formatLocationMessage(IncomingMessageDto $dto): string
    {
        $location = $dto->incomingMsg['location'] ?? [];

        $latitude = $location['latitude'] ?? null;
        $longitude = $location['longitude'] ?? null;

        if (null === $latitude || null === $longitude) {
            return '[Location shared - coordinates not available]';
        }

        $parts = ["📍 Standort geteilt: {$latitude}, {$longitude}"];

        // Include location name if available (e.g., "Philz Coffee")
        if (!empty($location['name'])) {
            $parts[] = "Name: {$location['name']}";
        }

        // Include address if available
        if (!empty($location['address'])) {
            $parts[] = "Adresse: {$location['address']}";
        }

        // Include Google Maps URL for context
        if (!empty($location['url'])) {
            $parts[] = "Maps: {$location['url']}";
        } else {
            // Generate a Google Maps URL if not provided
            $parts[] = "Maps: https://www.google.com/maps?q={$latitude},{$longitude}";
        }

        return implode("\n", $parts);
    }

    /**
     * Format a contacts message into a readable text for AI processing.
     * WhatsApp contacts payload: array of contact objects with name, phones, etc.
     */
    private function formatContactsMessage(IncomingMessageDto $dto): string
    {
        $contacts = $dto->incomingMsg['contacts'] ?? [];

        if (empty($contacts)) {
            return '[Contact shared - details not available]';
        }

        $parts = ['📇 Kontakt(e) geteilt:'];

        foreach ($contacts as $contact) {
            $name = $contact['name']['formatted_name']
                ?? $contact['name']['first_name'] ?? 'Unbekannt';
            $contactInfo = ["- {$name}"];

            // Add phone numbers
            if (!empty($contact['phones'])) {
                foreach ($contact['phones'] as $phone) {
                    $phoneType = $phone['type'] ?? 'phone';
                    $phoneNumber = $phone['phone'] ?? $phone['wa_id'] ?? '';
                    if ($phoneNumber) {
                        $contactInfo[] = "  {$phoneType}: {$phoneNumber}";
                    }
                }
            }

            // Add emails
            if (!empty($contact['emails'])) {
                foreach ($contact['emails'] as $email) {
                    $emailType = $email['type'] ?? 'email';
                    $emailAddress = $email['email'] ?? '';
                    if ($emailAddress) {
                        $contactInfo[] = "  {$emailType}: {$emailAddress}";
                    }
                }
            }

            $parts[] = implode("\n", $contactInfo);
        }

        return implode("\n", $parts);
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

            // Issue #976: persist the downloaded asset as a `File` entity
            // attached to the message, mirroring how regular web uploads
            // travel through `MessageController::uploadFileForChat` and
            // `StreamController`. Without this, WhatsApp media bypassed the
            // standard storage pipeline: `Message::filePath` carried a raw
            // relative path while the static-serve controller only matched
            // the `/api/v1/files/uploads/...` prefix, so the audio 404'd in
            // the web chat. Storing it as a File entity also lets the
            // recording show up in the user's Files page and travel through
            // the same access-control path as any other upload.
            $relativePath = $downloadResult['file_path'];
            $file = new File();
            $file->setUserId($effectiveUserId);
            $file->setFilePath($relativePath);
            $file->setFileType($downloadResult['file_type'] ?? 'unknown');
            $file->setFileName(basename($relativePath));
            $file->setFileSize((int) ($downloadResult['size'] ?? 0));
            $file->setFileMime((string) ($downloadResult['mime_type'] ?? 'application/octet-stream'));
            $file->setStatus('uploaded');

            // Persist + attach without an inner flush — `handleIncomingMessage`
            // performs a single flush right after we return, which now
            // covers BOTH the File row AND the Message↔File association
            // in one transaction. An earlier version flushed twice and
            // wrote the M2M row in a separate INSERT, which was wasteful
            // and could leave a half-attached File if the second flush
            // failed.
            $this->em->persist($file);
            $message->addFile($file);
            $message->setFile(1);

            $this->logger->info('WhatsApp: Media downloaded successfully', [
                'media_id' => $mediaId,
                'type' => $dto->type,
                'file_path' => $relativePath,
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
                        $relativePath,
                        $downloadResult['file_type'],
                        $effectiveUserId
                    );

                    if (!empty($extractedText)) {
                        $file->setFileText($extractedText);
                        $file->setStatus('extracted');

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
                        // Extraction failed - return error to user instead of proceeding with placeholder
                        $file->setStatus('error');
                        $this->logger->warning('WhatsApp: No text extracted from audio/video', [
                            'type' => $dto->type,
                            'details' => $extractionDetails ?? [],
                        ]);

                        if ('video' === $dto->type) {
                            return 'Video has no audio track or audio could not be extracted';
                        }

                        return 'Audio transcription failed - no speech detected';
                    }
                } elseif ('image' === $dto->type || 'sticker' === $dto->type) {
                    // For images and stickers: Don't extract text here - let ChatHandler use Vision AI
                    // The ChatHandler has built-in vision support and will analyze the image
                    // Stickers are WebP images and can be analyzed the same way
                    $caption = $dto->incomingMsg[$dto->type]['caption'] ?? null;

                    if (!empty($caption)) {
                        // User asked a question about the image - use it as the prompt
                        $message->setText($caption);
                        $this->logger->info('WhatsApp: Image/sticker with caption, delegating to ChatHandler vision', [
                            'type' => $dto->type,
                            'caption' => $caption,
                            'file_path' => $relativePath,
                        ]);
                    } else {
                        // No caption: ask for a description
                        $prompt = 'sticker' === $dto->type
                            ? 'Describe this sticker. What does it show and what emotion or message does it convey?'
                            : 'Describe what you see in this image.';
                        $message->setText($prompt);
                        $this->logger->info('WhatsApp: Image/sticker without caption, requesting description', [
                            'type' => $dto->type,
                            'file_path' => $relativePath,
                        ]);
                    }
                // Note: fileText is intentionally NOT set on the File entity
                // here — ChatHandler reads the image straight off disk for
                // the Vision API request, and storing a placeholder text
                // would only confuse downstream classifiers.
                } else {
                    // For documents and other types, use standard extraction
                    [$extractedText] = $this->fileProcessor->extractText(
                        $relativePath,
                        $downloadResult['file_type'],
                        $effectiveUserId
                    );

                    if (!empty($extractedText)) {
                        $file->setFileText($extractedText);
                        $file->setStatus('extracted');
                    }
                }
            } catch (\Throwable $e) {
                $extractionError = $e->getMessage();
                $file->setStatus('error');
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

    /**
     * Persist an outgoing WhatsApp message.
     *
     * Mirrors the web-search metadata stored on the incoming message
     * (issue #652). The chat history endpoint
     * ({@see \App\Controller\ChatController::getMessages()}) renders the
     * "Quellen" panel based on `web_search_query` + `web_search_results_count`
     * on the OUTGOING message, then resolves the actual citations from the
     * INCOMING message via `SearchResultRepository::findByMessage()`. Skipping
     * this mirror was the root cause of issue #652 — the platform view simply
     * didn't know that the WhatsApp answer was backed by citations.
     *
     * Also records the serve URL + type when the response carried
     * AI-generated media so the web chat history can replay the
     * image/video/audio player (issue #626). WhatsApp itself already received
     * the asset via sendMedia(), but the cross-channel mirror lives in the DB
     * row.
     *
     * @param array{query?: string, results?: array}|null $searchResults  web-search payload
     *                                                                    carried alongside the
     *                                                                    AI response
     * @param array{path: string, type: string}|null      $generatedFile  optional file metadata
     *                                                                    produced by
     *                                                                    MediaGenerationHandler
     *                                                                    (already normalized to a
     *                                                                    serve URL)
     * @param array<string, mixed>|null                   $aiMetadata     chat model metadata from
     *                                                                    processStream (provider,
     *                                                                    model, model_id, usage)
     * @param array<string, mixed>|null                   $classification sorter metadata for
     *                                                                    ai_sorting_* MessageMeta
     */
    private function storeOutgoingMessage(
        User $user,
        IncomingMessageDto $dto,
        string $text,
        string $externalId,
        ?Chat $chat = null,
        ?array $searchResults = null,
        ?array $generatedFile = null,
        ?array $aiMetadata = null,
        ?array $classification = null,
    ): void {
        $outgoingMessage = new Message();
        $outgoingMessage->setUserId($user->getId());
        if ($chat) {
            $outgoingMessage->setChat($chat);
        }
        $outgoingMessage->setTrackingId(time());
        $outgoingMessage->setProviderIndex('WHATSAPP');
        $outgoingMessage->setUnixTimestamp(time());
        $outgoingMessage->setDateTime(date('YmdHis'));
        $outgoingMessage->setMessageType('WTSP');
        $outgoingMessage->setFile(null !== $generatedFile ? 1 : 0);
        $outgoingMessage->setFilePath($generatedFile['path'] ?? '');
        $outgoingMessage->setFileType($generatedFile['type'] ?? '');
        $outgoingMessage->setTopic($classification['topic'] ?? 'CHAT');
        $outgoingMessage->setLanguage($classification['language'] ?? 'en');
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

        // #975: mirror web StreamController model metadata so the platform
        // chat lightbulb shows which chat/sorting models produced the reply.
        $outgoingMessage->setMeta('ai_chat_provider', (string) ($aiMetadata['provider'] ?? 'unknown'));
        $outgoingMessage->setMeta('ai_chat_model', (string) ($aiMetadata['model'] ?? 'unknown'));
        if (!empty($aiMetadata['model_id'])) {
            $outgoingMessage->setMeta('ai_chat_model_id', (string) $aiMetadata['model_id']);
        }
        if (!empty($aiMetadata['usage']) && is_array($aiMetadata['usage'])) {
            $outgoingMessage->setMeta('ai_chat_usage', (string) json_encode($aiMetadata['usage']));
        }
        if (!empty($classification['sorting_provider'])) {
            $outgoingMessage->setMeta('ai_sorting_provider', (string) $classification['sorting_provider']);
        }
        if (!empty($classification['sorting_model_name'])) {
            $outgoingMessage->setMeta('ai_sorting_model', (string) $classification['sorting_model_name']);
        }
        if (!empty($classification['sorting_model_id'])) {
            $outgoingMessage->setMeta('ai_sorting_model_id', (string) $classification['sorting_model_id']);
        }

        $resultsList = $searchResults['results'] ?? null;
        if (is_array($resultsList) && [] !== $resultsList) {
            $outgoingMessage->setMeta('web_search_query', (string) ($searchResults['query'] ?? ''));
            $outgoingMessage->setMeta('web_search_results_count', (string) count($resultsList));
        }

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
            '%s/%s/%s',
            $this->graphBaseUrl,
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
        // Strip MIME parameters (e.g., "audio/ogg; codecs=opus" → "audio/ogg")
        $baseMimeType = trim(explode(';', $mimeType)[0]);

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
        return $mimeMap[$baseMimeType] ?? 'unknown';
    }

    /**
     * Convert standard Markdown formatting to WhatsApp-compatible formatting.
     *
     * WhatsApp formatting reference:
     * - Bold: *text* (MD uses **text** or __text__)
     * - Italic: _text_ (MD uses *text*)
     * - Strikethrough: ~text~ (MD uses ~~text~~)
     * - Monospace: ```text``` or `text` (same as MD)
     * - Lists: - item or * item (same as MD, but we use • for safety)
     * - Quotes: > text (same as MD)
     * - No support for [text](url) links, ![alt](url) images, # headings or tables
     *
     * Markdown that WhatsApp cannot render is rewritten into a supported subset:
     * headings → bold, links → "text (url)", tables → "*Header:* value" blocks,
     * and ```markdown fences are unwrapped (see {@see convertTablesToWhatsApp()}).
     *
     * @see https://faq.whatsapp.com/539178204879377
     */
    private function convertToWhatsAppMarkdown(string $text): string
    {
        // 1. Protect code blocks and strip language identifiers (```python → ```).
        // Exception: ```markdown / ```md fences are UNWRAPPED instead of frozen.
        // The AI often returns a whole answer inside a ```markdown block when the
        // user asks for "a markdown list/table". WhatsApp renders that fence as
        // monospace, leaking every #, |, ** to the user (issue #268). Unwrapping
        // lets the inner Markdown flow through the conversion below so the user
        // sees formatted text instead of raw syntax. Real code fences
        // (python/js/…) stay monospace — WhatsApp supports and benefits from it.
        $codeBlocks = [];
        $text = preg_replace_callback('/```([^\n`]*)\n?([\s\S]*?)```/', function ($match) use (&$codeBlocks) {
            $language = strtolower(trim($match[1]));
            $content = $match[2];

            if (in_array($language, ['markdown', 'md'], true)) {
                return $content;
            }

            $placeholder = '{{CODE_BLOCK_'.count($codeBlocks).'}}';
            $codeBlocks[$placeholder] = '```'.$content.'```';

            return $placeholder;
        }, $text);

        // 2. Protect inline code
        $inlineCode = [];
        $text = preg_replace_callback('/`[^`]+`/', function ($match) use (&$inlineCode) {
            $placeholder = '{{INLINE_CODE_'.count($inlineCode).'}}';
            $inlineCode[$placeholder] = $match[0];

            return $placeholder;
        }, $text);

        // 2b. Convert Markdown tables to a WhatsApp-friendly layout. WhatsApp has
        // no table support, so a raw pipe table leaks as a wall of "| col |"
        // characters (issue #268). Done before the bold/link steps so the emitted
        // labels and any inline markup inside cells still get converted.
        $text = $this->convertTablesToWhatsApp($text);

        // 3. Convert image links ![alt](url) → alt text or URL
        $text = preg_replace('/!\[([^\]]*)\]\(([^)]+)\)/', '$2', $text);

        // 4. Convert [text](url) links → "text (url)" or just URL if text matches
        $text = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', function ($match) {
            $linkText = trim($match[1]);
            $url = trim($match[2]);
            if ($linkText === $url || empty($linkText)) {
                return $url;
            }

            return $linkText.' ('.$url.')';
        }, $text);

        // 5. Convert bold: **text**, __text__, and headers → placeholder (protect from italic conversion)
        $boldParts = [];
        $text = preg_replace_callback('/\*\*(.+?)\*\*/', function ($match) use (&$boldParts) {
            $placeholder = '{{BOLD_'.count($boldParts).'}}';
            $boldParts[$placeholder] = '*'.$match[1].'*';

            return $placeholder;
        }, $text);
        $text = preg_replace_callback('/__(.+?)__/', function ($match) use (&$boldParts) {
            $placeholder = '{{BOLD_'.count($boldParts).'}}';
            $boldParts[$placeholder] = '*'.$match[1].'*';

            return $placeholder;
        }, $text);
        $text = preg_replace_callback('/^#{1,6}\s+(.+)$/m', function ($match) use (&$boldParts) {
            $placeholder = '{{BOLD_'.count($boldParts).'}}';
            $boldParts[$placeholder] = '*'.$match[1].'*';

            return $placeholder;
        }, $text);

        // 6. Convert *italic* → _italic_ (remaining single * pairs are italic in MD, bold in WA)
        // Require asterisks to be tight around text (no spaces) to avoid matching math like "2 * 3 * 4"
        $text = preg_replace('/(?<!\w)\*(?!\s)([^*\n]+?)(?<!\s)\*(?!\w)/', '_$1_', $text);

        // 7. Restore bold placeholders
        foreach ($boldParts as $placeholder => $bold) {
            $text = str_replace($placeholder, $bold, $text);
        }

        // 8. Convert ~~strikethrough~~ to ~strikethrough~
        $text = preg_replace('/~~(.+?)~~/', '~$1~', $text);

        // 9. Convert bullet points to Unicode bullets
        $text = preg_replace('/^[\-\*]\s+/m', '• ', $text);

        // 10. Convert horizontal rules to a clean separator
        $text = preg_replace('/^[\-\*_]{3,}\s*$/m', '─────', $text);

        // 11. Clean up blockquotes (WhatsApp supports > natively, but strip nested levels)
        $text = preg_replace('/^>{2,}\s?/m', '> ', $text);

        // 12. Restore code blocks and inline code
        foreach ($codeBlocks as $placeholder => $code) {
            $text = str_replace($placeholder, $code, $text);
        }
        foreach ($inlineCode as $placeholder => $code) {
            $text = str_replace($placeholder, $code, $text);
        }

        return $text;
    }

    /**
     * Convert GitHub-flavoured Markdown tables into a WhatsApp-friendly layout.
     *
     * WhatsApp has no table support, so a raw pipe table leaks as a wall of
     * "| col | col |" characters (issue #268). Mobile screens are also too narrow
     * for column alignment in a proportional font, so each data row is rendered
     * as a small block of "*Header:* value" lines instead — readable and using
     * only WhatsApp-supported bold. The header labels are emitted as standard
     * Markdown bold (**Header:**) so the downstream bold step turns them into
     * WhatsApp's single-asterisk bold.
     *
     * A table is only recognised when a row containing a pipe is immediately
     * followed by a separator row (e.g. |---|:--:|), matching the GFM spec.
     */
    private function convertTablesToWhatsApp(string $text): string
    {
        $lines = explode("\n", $text);
        $lineCount = count($lines);
        $out = [];
        $i = 0;

        while ($i < $lineCount) {
            $line = $lines[$i];

            if ($this->isTableRow($line)
                && $i + 1 < $lineCount
                && $this->isTableSeparator($lines[$i + 1])
            ) {
                $headers = $this->splitTableRow($line);
                $i += 2; // skip the header row and the separator row

                $renderedRows = [];
                while ($i < $lineCount && $this->isTableRow($lines[$i]) && !$this->isTableSeparator($lines[$i])) {
                    $renderedRows[] = $this->renderTableRow($headers, $this->splitTableRow($lines[$i]));
                    ++$i;
                }

                if ([] !== $renderedRows) {
                    // Blank line between rows keeps each record visually distinct.
                    $out[] = implode("\n\n", array_filter($renderedRows, static fn (string $row): bool => '' !== $row));
                }

                continue;
            }

            $out[] = $line;
            ++$i;
        }

        return implode("\n", $out);
    }

    /**
     * A table row is any line that contains at least one pipe delimiter.
     */
    private function isTableRow(string $line): bool
    {
        return str_contains(trim($line), '|');
    }

    /**
     * A separator row contains only alignment markers (-, :, |, spaces),
     * e.g. |---|:--:|---:|. This is what distinguishes a real table from a
     * stray sentence that happens to contain a pipe.
     */
    private function isTableSeparator(string $line): bool
    {
        $trimmed = trim($line);
        if (!str_contains($trimmed, '-')) {
            return false;
        }

        $cells = $this->splitTableRow($trimmed);
        if ([] === $cells) {
            return false;
        }

        foreach ($cells as $cell) {
            if (1 !== preg_match('/^:?-{1,}:?$/', trim($cell))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Split a table row into trimmed cells, dropping the optional leading and
     * trailing pipe so boundary cells don't show up as empty entries.
     *
     * @return list<string>
     */
    private function splitTableRow(string $line): array
    {
        $trimmed = trim($line);
        $trimmed = preg_replace('/^\|/', '', $trimmed) ?? $trimmed;
        $trimmed = preg_replace('/\|$/', '', $trimmed) ?? $trimmed;

        return array_map('trim', explode('|', $trimmed));
    }

    /**
     * Render a single data row as "*Header:* value" lines (one per non-empty
     * cell). Cells without a matching header fall back to the bare value.
     *
     * @param list<string> $headers
     * @param list<string> $cells
     */
    private function renderTableRow(array $headers, array $cells): string
    {
        $parts = [];

        foreach ($cells as $index => $cell) {
            if ('' === $cell) {
                continue;
            }

            $header = trim($headers[$index] ?? '');
            // Emit standard Markdown bold (**…**) so the existing bold step
            // converts it to WhatsApp's single-asterisk bold downstream.
            $parts[] = '' !== $header ? '**'.$header.':** '.$cell : $cell;
        }

        return implode("\n", $parts);
    }

    /**
     * Maximum number of web sources appended to a WhatsApp reply.
     *
     * Keeping the list short prevents the 4096-char message limit from
     * pushing the actual answer into the second chunk, and it matches the
     * "top N" UX that the platform chat view already shows next to the
     * response.
     */
    private const MAX_WHATSAPP_SOURCES = 5;

    /**
     * Append a compact list of web-search citations to a WhatsApp reply.
     *
     * Issue #652: WhatsApp users previously had to ask "wo sind die quellen?"
     * to surface the references the AI relied on. The platform UI shows the
     * sources via metadata; for the mobile channel we inline them so the
     * citations travel with the answer.
     *
     * Each entry is rendered as "*[N] Title*\n<url>" — WhatsApp turns bare
     * URLs into clickable previews and respects `*…*` as bold. Empty URLs
     * are skipped so we never produce naked numbers in the citation block.
     *
     * @param string                           $responseText The AI's answer text (already converted to WhatsApp markdown by sendMessage())
     * @param array<int, array<string, mixed>> $sources      Raw Brave-style search hits as returned by BraveSearchService
     */
    private function appendWhatsAppSources(string $responseText, array $sources): string
    {
        $entries = [];
        $position = 1;

        foreach ($sources as $source) {
            if (count($entries) >= self::MAX_WHATSAPP_SOURCES) {
                break;
            }

            $url = trim((string) ($source['url'] ?? ''));
            if ('' === $url) {
                continue;
            }

            $title = trim((string) ($source['title'] ?? ''));
            if ('' === $title) {
                $title = $url;
            }

            // Newlines inside the title would break WhatsApp's list rendering
            // and confuse the markdown bold delimiters below.
            $title = preg_replace('/\s+/', ' ', $title) ?? $title;

            $entries[] = sprintf('*[%d] %s*'."\n".'%s', $position, $title, $url);
            ++$position;
        }

        if ([] === $entries) {
            return $responseText;
        }

        $separator = '' === trim($responseText) ? '' : "\n\n";

        return $responseText.$separator.'🔗 *Quellen:*'."\n".implode("\n\n", $entries);
    }
}
