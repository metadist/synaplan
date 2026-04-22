<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Discord Webhook Notification Service.
 *
 * Sends notifications to Discord via webhook for monitoring WhatsApp interactions.
 * Notifications are restricted to admin users only — non-admin activity is silently skipped.
 */
final readonly class DiscordNotificationService
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
        private UserRepository $userRepository,
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
     * Determine whether a notification should be sent.
     * Requires both an active webhook and an admin user.
     */
    private function shouldNotify(?int $userId): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        if (null === $userId) {
            return false;
        }

        $user = $this->userRepository->find($userId);

        return $user instanceof User && $user->isAdmin();
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
        ?int $userId = null,
    ): void {
        if (!$this->shouldNotify($userId)) {
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
        ?int $userId = null,
    ): void {
        if (!$this->shouldNotify($userId)) {
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
        if (!$this->shouldNotify($userId)) {
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

        // Synapse Routing metrics
        $source = $classificationResult['source'] ?? null;
        if (null !== $source) {
            $fields[] = [
                'name' => '⚡ Routing Source',
                'value' => $source,
                'inline' => true,
            ];
        }

        $synapseScore = $classificationResult['synapse_score'] ?? null;
        if (null !== $synapseScore) {
            $fields[] = [
                'name' => '📊 Confidence',
                'value' => sprintf('%.4f', $synapseScore),
                'inline' => true,
            ];
        }

        $fallbackReason = $classificationResult['synapse_fallback_reason'] ?? null;
        if (null !== $fallbackReason) {
            $fields[] = [
                'name' => '🔄 Fallback Reason',
                'value' => $fallbackReason,
                'inline' => true,
            ];
        }

        $synapseLatency = $classificationResult['synapse_latency_ms'] ?? null;
        if (null !== $synapseLatency) {
            $fields[] = [
                'name' => '⏱️ Synapse Latency',
                'value' => $synapseLatency.'ms',
                'inline' => true,
            ];
        }

        $isSynapse = str_starts_with($source ?? '', 'synapse_');
        $emoji = $isSynapse ? '⚡' : '🔍';
        $label = $isSynapse ? 'Synapse' : 'AI';

        $this->sendEmbed(
            title: "{$emoji} {$label} Classification Result",
            color: null !== $mediaType ? self::COLOR_SUCCESS : 0xFFA500,
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
        ?int $userId = null,
    ): void {
        if (!$this->shouldNotify($userId)) {
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
     * Notify successful email processing (debug logging for specific senders).
     */
    public function notifyEmailSuccess(
        string $fromEmail,
        string $toEmail,
        string $subject,
        string $userMessage,
        string $responseText,
        array $metadata = [],
    ): void {
        if (!$this->isEnabled()) {
            return;
        }

        $fields = [
            [
                'name' => '📧 From',
                'value' => $fromEmail,
                'inline' => true,
            ],
            [
                'name' => '📬 To',
                'value' => $toEmail,
                'inline' => true,
            ],
            [
                'name' => '🧾 Subject',
                'value' => $this->truncate($subject, self::MAX_USER_MESSAGE),
                'inline' => false,
            ],
            [
                'name' => '📥 User Message',
                'value' => $this->truncate($userMessage, self::MAX_USER_MESSAGE),
                'inline' => false,
            ],
            [
                'name' => '📤 AI Response',
                'value' => $this->truncate($responseText, self::MAX_RESPONSE),
                'inline' => false,
            ],
        ];

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

        if (!empty($metadata['processing_time'])) {
            $fields[] = [
                'name' => '⏱️ Processing Time',
                'value' => round((float) $metadata['processing_time'], 2).'s',
                'inline' => true,
            ];
        }

        if (!empty($metadata['message_id'])) {
            $fields[] = [
                'name' => '🆔 Message ID',
                'value' => (string) $metadata['message_id'],
                'inline' => true,
            ];
        }

        if (!empty($metadata['chat_id'])) {
            $fields[] = [
                'name' => '💬 Chat ID',
                'value' => (string) $metadata['chat_id'],
                'inline' => true,
            ];
        }

        $this->sendEmbed(
            title: '📧 Email: Successfully Processed',
            color: self::COLOR_SUCCESS,
            fields: $fields,
            footer: 'Synaplan Email Channel'
        );
    }

    /**
     * Notify email processing error (debug logging for specific senders).
     */
    public function notifyEmailError(
        string $errorType,
        string $fromEmail,
        string $toEmail,
        string $subject,
        string $error,
        array $metadata = [],
    ): void {
        if (!$this->isEnabled()) {
            return;
        }

        $title = match ($errorType) {
            'processing' => 'AI Processing Failed',
            'send_failed' => 'Response Email Send Failed',
            'tts_failed' => 'TTS Generation Failed',
            'user_creation' => 'User Creation Failed',
            'rate_limit' => 'Rate Limit Exceeded',
            'validation' => 'Validation Failed',
            default => 'Error Occurred',
        };

        $fields = [
            [
                'name' => '📧 From',
                'value' => $fromEmail,
                'inline' => true,
            ],
            [
                'name' => '📬 To',
                'value' => $toEmail,
                'inline' => true,
            ],
            [
                'name' => '🧾 Subject',
                'value' => $this->truncate($subject, self::MAX_USER_MESSAGE),
                'inline' => false,
            ],
            [
                'name' => '⚠️ Error',
                'value' => "```\n{$this->truncate($error, self::MAX_ERROR)}\n```",
                'inline' => false,
            ],
        ];

        if (!empty($metadata['user_message'])) {
            $fields[] = [
                'name' => '📥 User Message',
                'value' => $this->truncate($metadata['user_message'], self::MAX_USER_MESSAGE),
                'inline' => false,
            ];
        }

        $this->sendEmbed(
            title: "❌ Email: {$title}",
            color: self::COLOR_ERROR,
            fields: $fields,
            footer: 'Synaplan Email Channel'
        );
    }

    /**
     * Notify a widget message processing error.
     * Only fires when webhook is enabled — no admin check (system-level event).
     *
     * @param array<string, mixed> $metadata
     */
    public function notifyWidgetError(
        string $widgetId,
        string $error,
        array $metadata = [],
    ): void {
        if (!$this->isEnabled()) {
            return;
        }

        $fields = [
            [
                'name' => '🔧 Widget ID',
                'value' => $widgetId,
                'inline' => true,
            ],
            [
                'name' => '❌ Error',
                'value' => '```'.$this->truncate($error, self::MAX_ERROR).'```',
                'inline' => false,
            ],
        ];

        if (isset($metadata['session_id'])) {
            $fields[] = [
                'name' => '🔑 Session',
                'value' => $this->truncate((string) $metadata['session_id'], 50),
                'inline' => true,
            ];
        }

        if (isset($metadata['file'], $metadata['line'])) {
            $fields[] = [
                'name' => '📍 Location',
                'value' => '`'.basename((string) $metadata['file']).':'.$metadata['line'].'`',
                'inline' => true,
            ];
        }

        $this->sendEmbed(
            title: '⚠️ Widget Message Error',
            color: self::COLOR_ERROR,
            fields: $fields,
            footer: 'Synaplan Widget'
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
