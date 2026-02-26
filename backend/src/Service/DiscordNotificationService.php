<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Discord Webhook Notification Service.
 *
 * Sends notifications to Discord via webhook for monitoring WhatsApp interactions.
 */
class DiscordNotificationService
{
    // Discord embed colors
    private const COLOR_SUCCESS = 0x00FF00; // Green
    private const COLOR_ERROR = 0xFF0000;   // Red

    // Truncation limits (Discord API: field value max 1024, total embed max 6000)
    // @see https://discord.com/developers/docs/resources/channel#embed-object-embed-limits
    private const MAX_USER_MESSAGE = 200;  // Truncate user message preview
    private const MAX_RESPONSE = 300;      // Truncate response preview
    private const MAX_ERROR = 450;         // Truncate error (leaves room for code block formatting)

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private ?string $webhookUrl = null,
    ) {
    }

    /**
     * Check if Discord notifications are enabled.
     * Returns false if DISCORD_WEBHOOK_URL is not set or empty.
     */
    public function isEnabled(): bool
    {
        return null !== $this->webhookUrl && '' !== $this->webhookUrl;
    }

    /**
     * Notify successful WhatsApp message processing.
     */
    public function notifyWhatsAppSuccess(
        string $type,
        string $from,
        string $userMessage,
        string $responseText,
        array $metadata = [],
    ): void {
        if (!$this->isEnabled()) {
            return;
        }

        $emoji = match ($type) {
            'text' => '💬',
            'image' => '🖼️',
            'video' => '🎬',
            'audio', 'tts' => '🎵',
            'transcription' => '🎤',
            default => '✅',
        };

        $title = match ($type) {
            'text' => 'Text Message Sent',
            'image' => 'Image Generated & Sent',
            'video' => 'Video Generated & Sent',
            'audio', 'tts' => 'Audio Generated & Sent',
            'transcription' => 'Audio Transcribed',
            default => 'Message Processed',
        };

        $fields = [
            [
                'name' => '📱 From',
                'value' => $this->maskPhoneNumber($from),
                'inline' => true,
            ],
            [
                'name' => '📥 User Message',
                'value' => $this->truncate($userMessage, self::MAX_USER_MESSAGE),
                'inline' => false,
            ],
            [
                'name' => '📤 Response',
                'value' => $this->truncate($responseText, self::MAX_RESPONSE),
                'inline' => false,
            ],
        ];

        // Add metadata fields
        if (!empty($metadata['provider'])) {
            $fields[] = [
                'name' => '🤖 Provider',
                'value' => $metadata['provider'],
                'inline' => true,
            ];
        }

        if (!empty($metadata['model'])) {
            $fields[] = [
                'name' => '🧠 Model',
                'value' => $metadata['model'],
                'inline' => true,
            ];
        }

        if (!empty($metadata['media_type'])) {
            $fields[] = [
                'name' => '📁 Media Type',
                'value' => ucfirst($metadata['media_type']),
                'inline' => true,
            ];
        }

        if (!empty($metadata['duration'])) {
            $fields[] = [
                'name' => '⏱️ Duration',
                'value' => $metadata['duration'].'s',
                'inline' => true,
            ];
        }

        $this->sendEmbed(
            title: "{$emoji} WhatsApp: {$title}",
            color: self::COLOR_SUCCESS,
            fields: $fields,
            footer: 'Synaplan WhatsApp Bot'
        );
    }

    /**
     * Notify WhatsApp processing error.
     */
    public function notifyWhatsAppError(
        string $errorType,
        string $from,
        string $userMessage,
        string $error,
        array $metadata = [],
    ): void {
        if (!$this->isEnabled()) {
            return;
        }

        $emoji = match ($errorType) {
            'transcription' => '🎤',
            'image_generation' => '🖼️',
            'video_generation' => '🎬',
            'audio_generation', 'tts' => '🎵',
            'media_download' => '📥',
            'send_failed' => '📤',
            default => '❌',
        };

        $title = match ($errorType) {
            'transcription' => 'Transcription Failed',
            'image_generation' => 'Image Generation Failed',
            'video_generation' => 'Video Generation Failed',
            'audio_generation', 'tts' => 'Audio Generation Failed',
            'media_download' => 'Media Download Failed',
            'send_failed' => 'Message Send Failed',
            'processing' => 'Message Processing Failed',
            default => 'Error Occurred',
        };

        $fields = [
            [
                'name' => '📱 From',
                'value' => $this->maskPhoneNumber($from),
                'inline' => true,
            ],
            [
                'name' => '📥 User Message',
                'value' => $this->truncate($userMessage, self::MAX_USER_MESSAGE),
                'inline' => false,
            ],
            [
                'name' => '⚠️ Error',
                'value' => "```\n{$this->truncate($error, self::MAX_ERROR)}\n```",
                'inline' => false,
            ],
        ];

        // Add metadata fields
        if (!empty($metadata['message_type'])) {
            $fields[] = [
                'name' => '📁 Message Type',
                'value' => ucfirst($metadata['message_type']),
                'inline' => true,
            ];
        }

        if (!empty($metadata['file_type'])) {
            $fields[] = [
                'name' => '📄 File Type',
                'value' => $metadata['file_type'],
                'inline' => true,
            ];
        }

        if (!empty($metadata['media_type'])) {
            $fields[] = [
                'name' => '📁 Media Type',
                'value' => ucfirst($metadata['media_type']),
                'inline' => true,
            ];
        }

        $this->sendEmbed(
            title: "{$emoji} WhatsApp: {$title}",
            color: self::COLOR_ERROR,
            fields: $fields,
            footer: 'Synaplan WhatsApp Bot'
        );
    }

    /**
     * Send a Discord embed message.
     */
    private function sendEmbed(
        string $title,
        int $color,
        array $fields,
        string $footer = '',
        ?string $description = null,
    ): void {
        $embed = [
            'title' => $title,
            'color' => $color,
            'fields' => $fields,
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ];

        if ($description) {
            $embed['description'] = $description;
        }

        if ($footer) {
            $embed['footer'] = ['text' => $footer];
        }

        $payload = [
            'embeds' => [$embed],
        ];

        try {
            $this->httpClient->request('POST', $this->webhookUrl, [
                'json' => $payload,
                'timeout' => 5,
            ]);
        } catch (\Throwable $e) {
            // Don't let Discord errors affect WhatsApp processing
            $this->logger->warning('Discord notification failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notify AI classification/sorting result (for debugging).
     */
    public function notifyClassification(
        string $userMessage,
        array $classificationResult,
        ?int $userId = null,
    ): void {
        if (!$this->isEnabled()) {
            return;
        }

        $topic = $classificationResult['topic'] ?? 'unknown';
        $language = $classificationResult['language'] ?? 'unknown';
        $mediaType = $classificationResult['media_type'] ?? null;
        $duration = $classificationResult['duration'] ?? null;
        $rawResponse = $classificationResult['raw_response'] ?? '';

        $fields = [
            [
                'name' => '👤 User ID',
                'value' => (string) ($userId ?? 'N/A'),
                'inline' => true,
            ],
            [
                'name' => '📥 User Message',
                'value' => $this->truncate($userMessage, self::MAX_USER_MESSAGE),
                'inline' => false,
            ],
            [
                'name' => '🏷️ Topic',
                'value' => $topic,
                'inline' => true,
            ],
            [
                'name' => '🌍 Language',
                'value' => $language,
                'inline' => true,
            ],
        ];

        if (null !== $mediaType) {
            $fields[] = [
                'name' => '🎬 Media Type',
                'value' => $mediaType,
                'inline' => true,
            ];
        } else {
            $fields[] = [
                'name' => '🎬 Media Type',
                'value' => '❌ NOT DETECTED',
                'inline' => true,
            ];
        }

        if (null !== $duration) {
            $fields[] = [
                'name' => '⏱️ Duration',
                'value' => $duration.'s',
                'inline' => true,
            ];
        }

        if (!empty($rawResponse)) {
            $fields[] = [
                'name' => '🤖 Raw AI Response',
                'value' => '```json'."\n".$this->truncate($rawResponse, 400)."\n".'```',
                'inline' => false,
            ];
        }

        $this->sendEmbed(
            title: '🔍 AI Classification Result',
            color: null !== $mediaType ? self::COLOR_SUCCESS : 0xFFA500, // Orange if no media type
            fields: $fields,
            footer: 'Synaplan Classifier'
        );
    }

    /**
     * Notify duplicate email webhook detection.
     */
    public function notifyDuplicateEmailWebhook(
        string $fromEmail,
        string $toEmail,
        string $subject,
        int $existingMessageId,
        ?int $chatId = null,
        ?string $externalMessageId = null,
        string $detectionMethod = 'external_id',
    ): void {
        if (!$this->isEnabled()) {
            return;
        }

        $fields = [
            [
                'name' => '📥 From',
                'value' => $this->truncate($fromEmail, 120),
                'inline' => true,
            ],
            [
                'name' => '📤 To',
                'value' => $this->truncate($toEmail, 120),
                'inline' => true,
            ],
            [
                'name' => '🧾 Subject',
                'value' => $this->truncate($subject, self::MAX_USER_MESSAGE),
                'inline' => false,
            ],
            [
                'name' => '🆔 Existing Message ID',
                'value' => (string) $existingMessageId,
                'inline' => true,
            ],
            [
                'name' => '🔎 Detection Method',
                'value' => $detectionMethod,
                'inline' => true,
            ],
        ];

        if (null !== $chatId) {
            $fields[] = [
                'name' => '💬 Chat ID',
                'value' => (string) $chatId,
                'inline' => true,
            ];
        }

        if (null !== $externalMessageId && '' !== $externalMessageId) {
            $fields[] = [
                'name' => '📨 External Message ID',
                'value' => $this->truncate($externalMessageId, 200),
                'inline' => false,
            ];
        }

        $this->sendEmbed(
            title: '♻️ Duplicate Email Webhook Detected',
            color: 0xFFA500,
            fields: $fields,
            footer: 'Synaplan Email Webhook'
        );
    }

    /**
     * Mask phone number for privacy (show last 4 digits).
     */
    private function maskPhoneNumber(string $phone): string
    {
        if (strlen($phone) <= 4) {
            return $phone;
        }

        return '***'.substr($phone, -4);
    }

    /**
     * Truncate text to max length.
     */
    private function truncate(string $text, int $maxLength): string
    {
        $text = trim($text);

        if ('' === $text) {
            return '(empty)';
        }

        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength - 3).'...';
    }
}
