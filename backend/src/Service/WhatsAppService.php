<?php

namespace App\Service;

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
     */
    public function handleIncomingMessage(IncomingMessageDto $dto, User $user, bool $isAnonymous): array
    {
        $effectiveUserId = $isAnonymous ? $this->whatsappUserId : $user->getId();

        $this->logger->info('WhatsApp message received', [
            'original_user_id' => $user->getId(),
            'whatsapp_default_user_id' => $this->whatsappUserId,
            'effective_user_id' => $effectiveUserId,
            'from' => $dto->from,
            'to_phone_number_id' => $dto->phoneNumberId,
            'to_display_phone' => $dto->displayPhoneNumber,
            'type' => $dto->type,
            'message_id' => $dto->messageId,
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

        $this->em->persist($message);
        $this->em->flush();

        // 5. Metadata and Media Handling
        $this->storeMessageMetadata($message, $dto, $user);

        if ($mediaId) {
            $this->handleMediaDownload($message, $dto, $mediaId, $mediaUrl, $effectiveUserId);
        }

        $this->em->flush();

        // 6. Usage recording and mark as read
        $this->rateLimitService->recordUsage($user, 'MESSAGES');
        $this->markAsRead($dto->messageId, $dto->phoneNumberId);

        // 7. AI Pipeline Processing (use streaming mode to support TTS/media generation)
        $collectedResponse = '';
        $streamCallback = function (string $chunk) use (&$collectedResponse) {
            $collectedResponse .= $chunk;
        };

        $result = $this->messageProcessor->processStream($message, $streamCallback);

        if (!$result['success']) {
            return [
                'success' => false,
                'message_id' => $dto->messageId,
                'error' => $result['error'] ?? 'Processing failed',
            ];
        }

        $responseText = $result['response']['content'] ?? $collectedResponse;
        $metadata = $result['response']['metadata'] ?? [];
        $fileData = $metadata['file'] ?? null;

        // 8. Send Response (audio file or text)
        $responseSent = false;

        // Check if response contains audio file (TTS response)
        if ($fileData && 'audio' === ($fileData['type'] ?? null)) {
            $audioPath = $fileData['path'] ?? null;
            if ($audioPath && !empty($this->appUrl)) {
                // Build absolute URL for WhatsApp
                $audioUrl = rtrim($this->appUrl, '/').'/'.ltrim($audioPath, '/');

                $this->logger->info('WhatsApp: Sending audio response', [
                    'to' => $dto->from,
                    'audio_url' => $audioUrl,
                ]);

                $sendResult = $this->sendMedia($dto->from, 'audio', $audioUrl, $dto->phoneNumberId);
                if ($sendResult['success']) {
                    // Store the text version as outgoing message for history
                    $this->storeOutgoingMessage($user, $dto, $responseText ?: '[Audio response]', $sendResult['message_id']);
                    $responseSent = true;
                } else {
                    $this->logger->warning('WhatsApp: Failed to send audio, falling back to text', [
                        'error' => $sendResult['error'] ?? 'Unknown',
                    ]);
                }
            }
        }

        // Send text response if no audio was sent (or audio failed)
        if (!$responseSent && !empty($responseText)) {
            $sendResult = $this->sendMessage($dto->from, $responseText, $dto->phoneNumberId);
            if ($sendResult['success']) {
                $this->storeOutgoingMessage($user, $dto, $responseText, $sendResult['message_id']);
                $responseSent = true;
            }
        }

        return [
            'success' => true,
            'message_id' => $dto->messageId,
            'response_sent' => $responseSent,
        ];
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

    private function extractMessageText(IncomingMessageDto $dto): string
    {
        return match ($dto->type) {
            'text' => $dto->incomingMsg['text']['body'] ?? '',
            'image' => $dto->incomingMsg['image']['caption'] ?? '[Image]',
            'audio' => '[Audio message]',
            'video' => $dto->incomingMsg['video']['caption'] ?? '[Video]',
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

    private function handleMediaDownload(Message $message, IncomingMessageDto $dto, string $mediaId, ?string $mediaUrl, int $effectiveUserId): void
    {
        $message->setMeta('media_id', $mediaId);

        try {
            if (!$mediaUrl) {
                $mediaUrl = $this->getMediaUrl($mediaId, $dto->phoneNumberId);
            }

            if ($mediaUrl) {
                $message->setMeta('media_url', $mediaUrl);
                $downloadResult = $this->downloadMedia($mediaId, $dto->phoneNumberId, $effectiveUserId);

                if ($downloadResult && !empty($downloadResult['file_path'])) {
                    $message->setFile(1);
                    $message->setFilePath($downloadResult['file_path']);
                    $message->setFileType($downloadResult['file_type'] ?? 'unknown');

                    // Extract text immediately
                    try {
                        [$extractedText] = $this->fileProcessor->extractText(
                            $downloadResult['file_path'],
                            $downloadResult['file_type'],
                            $effectiveUserId
                        );

                        if (!empty($extractedText)) {
                            $message->setFileText($extractedText);
                        }
                    } catch (\Throwable $e) {
                        $this->logger->error('WhatsApp file extraction failed', ['error' => $e->getMessage()]);
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to download WhatsApp media', ['error' => $e->getMessage()]);
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
