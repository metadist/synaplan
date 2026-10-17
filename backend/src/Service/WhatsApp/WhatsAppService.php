<?php

namespace App\Service\WhatsApp;

use App\DTO\WhatsApp\IncomingMessageDto;
use App\Entity\Chat;
use App\Entity\File;
use App\Entity\Message;
use App\Entity\User;
use App\Service\File\FileHelper;
use App\Service\File\FileProcessor;
use App\Service\File\UserUploadPathBuilder;
use App\Service\Message\SpokenInput;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The WhatsApp Business API transport (Meta Cloud API): sends text (in
 * WhatsApp's markdown, split at the message limit), media and templates,
 * read receipts with a typing indicator, fetches an inbound message's media
 * and extracts its text, and verifies webhook signatures. The inbound turn
 * runs in {@see Turn\WhatsAppTurn}.
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

    private const DEFAULT_GRAPH_BASE = 'https://graph.facebook.com';

    private string $accessToken;
    private bool $enabled;
    private string $apiVersion = 'v21.0';
    private string $graphBaseUrl;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private EntityManagerInterface $em,
        private FileProcessor $fileProcessor,
        private UserUploadPathBuilder $userUploadPathBuilder,
        string $whatsappAccessToken,
        bool $whatsappEnabled,
        private string $uploadsDir,
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
        ?string $filename = null,
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

        // WhatsApp API requires 'filename' for document type. Prefer the
        // original display name so a published `{userId}_document_{ts}.docx`
        // copy still arrives as "notes.docx" rather than the fetchable alias.
        if ('document' === $mediaType) {
            $fromUrl = basename((string) parse_url($mediaUrl, PHP_URL_PATH));
            $mediaPayload['filename'] = (null !== $filename && '' !== $filename)
                ? $filename
                : ($fromUrl ?: 'document');
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
     * Handle media download and text extraction.
     *
     * @return string|null Error message if failed, null on success
     */
    public function handleMediaDownload(Message $message, IncomingMessageDto $dto, string $mediaId, ?string $mediaUrl, int $effectiveUserId): ?string
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

            // Persist + attach without an inner flush — `WhatsAppConversation::store()`
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
                            // Only a caption-less voice note is spoken input.
                            // A video, or audio that already has a caption, keeps
                            // today's attachment handling.
                            if ('audio' === $dto->type && SpokenInput::isReplaceablePlaceholder((string) $currentText)) {
                                SpokenInput::mark($message);
                            }
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

            // An unmapped type gets "unknown", which the allow-list rejects.
            $extension = FileHelper::getExtensionFromMimeType($contentType, 'unknown');

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
}
