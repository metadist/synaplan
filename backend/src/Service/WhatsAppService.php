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
    private string $accessToken;
    private bool $enabled;
    private string $apiVersion = 'v21.0';

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        string $whatsappAccessToken,
        bool $whatsappEnabled,
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
    public function getMediaUrl(string $mediaId): ?string
    {
        if (!$this->isAvailable()) {
            return null;
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
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Download media from WhatsApp.
     */
    public function downloadMedia(string $mediaUrl): ?string
    {
        if (!$this->isAvailable()) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', $mediaUrl, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->accessToken,
                ],
            ]);

            return $response->getContent();
        } catch (\Exception $e) {
            $this->logger->error('Failed to download WhatsApp media', [
                'url' => $mediaUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
