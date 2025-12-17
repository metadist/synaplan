<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * WhatsApp Business API Service (Meta/Facebook).
 *
 * Handles sending messages via WhatsApp Business API
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
        string $whatsappAccessToken,
        bool $whatsappEnabled,
        private string $uploadsDir,
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
     */
    public function downloadMedia(string $mediaId, string $phoneNumberId): ?array
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

            // Generate unique filename
            $filename = 'whatsapp_'.time().'_'.bin2hex(random_bytes(8)).'.'.$extension;
            $relativePath = 'whatsapp/'.$filename;
            $fullPath = $this->uploadsDir.'/'.$relativePath;

            // Create directory if it doesn't exist
            $dir = dirname($fullPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
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
