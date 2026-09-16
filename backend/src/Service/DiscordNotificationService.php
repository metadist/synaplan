<?php

namespace App\Service;

use App\AI\Health\ModelHealthAlert;
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
    private const COLOR_WARNING = 0xFFA500; // Orange

    // Truncation limits (Discord API: field value max 1024, total embed max 6000)
    // @see https://discord.com/developers/docs/resources/channel#embed-object-embed-limits
    private const MAX_USER_MESSAGE = 200;  // Truncate user message preview
    private const MAX_RESPONSE = 300;      // Truncate response preview
    private const MAX_ERROR = 450;         // Truncate error (leaves room for code block formatting)
    private const MAX_FIELD_VALUE = 1024;  // Hard Discord limit for a single field value
    private const MAX_DRIFT_ENTRIES = 15;  // Model-availability findings listed before "… and N more"

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
                'value' => $this->truncate(AiResponseSanitizer::stripForDisplay($responseText), self::MAX_RESPONSE),
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
                'value' => "```\n{$this->truncate(AiResponseSanitizer::stripForDisplay($error), self::MAX_ERROR)}\n```",
                'inline' => false,
            ],
        ];

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
     *
     * Set `mentionEveryone = true` to ping the channel via `@everyone`.
     * Discord requires both:
     *   1. the literal `@everyone` token in the message `content`
     *   2. an `allowed_mentions.parse: ['everyone']` entry, otherwise
     *      the webhook silently strips the mention.
     * Use this sparingly — only for incidents that on-call has to ack
     * within minutes (primary embedding provider outage, etc.).
     */
    private function sendEmbed(
        string $title,
        int $color,
        array $fields,
        string $footer = '',
        ?string $description = null,
        bool $mentionEveryone = false,
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

        if ($mentionEveryone) {
            $payload['content'] = '@everyone';
            $payload['allowed_mentions'] = ['parse' => ['everyone']];
        }

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

        // Routing metrics
        $source = $classificationResult['source'] ?? null;
        if (null !== $source) {
            $fields[] = [
                'name' => '⚡ Routing Source',
                'value' => $source,
                'inline' => true,
            ];
        }

        $this->sendEmbed(
            title: '🔍 AI Classification Result',
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
                'value' => $this->truncate(AiResponseSanitizer::stripForDisplay($responseText), self::MAX_RESPONSE),
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
                'value' => "```\n{$this->truncate(AiResponseSanitizer::stripForDisplay($error), self::MAX_ERROR)}\n```",
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
                'value' => '```'.$this->truncate(AiResponseSanitizer::stripForDisplay($error), self::MAX_ERROR).'```',
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
     * Notify a web-chat / sorting / generation provider failure.
     *
     * Always includes the raw provider text so operators can diagnose; the
     * user-facing chat never sees this payload. Callers go through
     * {@see Message\ChatErrorNotifier}, which throttles bursts.
     *
     * @param array<string, mixed> $metadata
     */
    public function notifyChatError(
        string $error,
        string $reason,
        ?string $provider = null,
        ?int $userId = null,
        array $metadata = [],
    ): void {
        if (!$this->isEnabled()) {
            return;
        }

        $fields = [
            [
                'name' => 'Reason',
                'value' => $reason,
                'inline' => true,
            ],
            [
                'name' => 'Provider',
                'value' => $provider ?: 'unknown',
                'inline' => true,
            ],
            [
                'name' => 'Error',
                'value' => '```'.$this->truncate(AiResponseSanitizer::stripForDisplay($error), self::MAX_ERROR).'```',
                'inline' => false,
            ],
        ];

        if (null !== $userId) {
            $fields[] = [
                'name' => 'User ID',
                'value' => (string) $userId,
                'inline' => true,
            ];
        }

        if (isset($metadata['model']) && is_string($metadata['model']) && '' !== $metadata['model']) {
            $fields[] = [
                'name' => 'Model',
                'value' => $this->truncate($metadata['model'], 80),
                'inline' => true,
            ];
        }

        if (isset($metadata['chat_id'])) {
            $fields[] = [
                'name' => 'Chat ID',
                'value' => (string) $metadata['chat_id'],
                'inline' => true,
            ];
        }

        $this->sendEmbed(
            title: '⚠️ Chat Provider Error',
            color: self::COLOR_ERROR,
            fields: $fields,
            footer: 'Synaplan Chat · throttled per provider + reason'
        );
    }

    /**
     * Notify when the embedding fallback provider activates due to a
     * primary-provider failure.
     *
     * This is treated as a P1 incident — the primary embedding stack
     * (e.g. local Ollama / OpenAI) is down and the system is now
     * burning Cloudflare quota for every RAG/Memory request.
     * Operators MUST ack this fast, so we:
     *   - mention `@everyone` (requires `allowed_mentions.parse`,
     *     handled in `sendEmbed`),
     *   - prepend a 🚨 emoji + clear `[INCIDENT]` tag for at-a-glance
     *     scanning in mobile notifications,
     *   - keep the same throttling discipline (1/h per provider pair)
     *     in the caller (`AiFacade`) so the channel does not get
     *     spammed by burst failures.
     */
    public function notifyEmbeddingFallback(string $primaryProvider, string $fallbackProvider, string $error): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $fields = [
            [
                'name' => 'Primary Provider (DOWN)',
                'value' => $primaryProvider,
                'inline' => true,
            ],
            [
                'name' => 'Fallback Provider (active)',
                'value' => $fallbackProvider,
                'inline' => true,
            ],
            [
                'name' => 'Error',
                'value' => '```'.$this->truncate($error, self::MAX_ERROR).'```',
                'inline' => false,
            ],
            [
                'name' => 'Action required',
                'value' => 'Verify primary provider health and capacity. Fallback traffic is billable.',
                'inline' => false,
            ],
        ];

        $this->sendEmbed(
            title: '🚨 [INCIDENT] Embedding Fallback Activated',
            color: self::COLOR_ERROR,
            fields: $fields,
            footer: 'Synaplan Embedding · throttled to 1/hour per provider pair',
            mentionEveryone: true,
        );
    }

    /**
     * Notify that we still offer models a provider no longer lists.
     *
     * Deliberately not an `@everyone` incident: the finding is advisory and the
     * models are still installed and selectable, so there is nothing to ack at
     * 3am. It is a standing reminder that a retirement migration is due before
     * users start seeing provider errors.
     *
     * @param list<string> $missingModels      human-readable lines, most urgent first
     * @param list<string> $uncheckedProviders providers that could not be verified
     */
    public function notifyModelAvailabilityDrift(array $missingModels, array $uncheckedProviders): void
    {
        if (!$this->isEnabled() || [] === $missingModels) {
            return;
        }

        $fields = [
            [
                'name' => sprintf('Missing upstream (%d)', count($missingModels)),
                'value' => $this->bulletList($missingModels, self::MAX_DRIFT_ENTRIES),
                'inline' => false,
            ],
            [
                'name' => 'Action required',
                'value' => 'Confirm on the provider deprecation page, then retire via ModelCatalog::RETIREMENTS (deactivate, never delete) — docs/PRICING_MAINTENANCE.md.',
                'inline' => false,
            ],
        ];

        if ([] !== $uncheckedProviders) {
            $fields[] = [
                'name' => 'Not verified',
                'value' => $this->truncate(implode(', ', $uncheckedProviders), self::MAX_ERROR),
                'inline' => false,
            ];
        }

        $this->sendEmbed(
            title: '⚠️ Discontinued AI models detected',
            color: self::COLOR_WARNING,
            fields: $fields,
            footer: 'Synaplan model availability check · daily',
        );
    }

    /**
     * Report AI models that stopped working, or that started working again.
     *
     * Distinct from {@see self::notifyModelAvailabilityDrift()} on purpose: that
     * one is a maintenance reminder that our catalog drifted from the provider's,
     * this one is an incident about models failing right now — including the
     * account-specific failures a catalog comparison cannot see.
     *
     * One message per provider rather than per model — see
     * {@see ModelHealthAlert}. `@everyone` is reserved for the
     * credential case: a rejected key or an empty balance takes out every model
     * behind that provider and only an operator can fix it, whereas a provider
     * retiring a single model can wait for office hours.
     */
    public function notifyModelHealth(ModelHealthAlert $alert, bool $resolved = false): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $fields = [
            [
                'name' => '🏢 Provider',
                'value' => $alert->name(),
                'inline' => true,
            ],
            [
                'name' => '🔢 Models',
                'value' => (string) $alert->modelCount(),
                'inline' => true,
            ],
            [
                'name' => '🧠 Affected',
                'value' => $this->truncate($alert->previewNames(), self::MAX_RESPONSE),
                'inline' => false,
            ],
            [
                'name' => $resolved ? 'Recovered after' : '⚠️ Reason',
                'value' => '```'.$this->truncate($alert->reason, self::MAX_ERROR).'```',
                'inline' => false,
            ],
        ];

        if (!$resolved) {
            $fields[] = [
                'name' => 'Action required',
                'value' => $alert->actionRequired(),
                'inline' => false,
            ];
        }

        $this->sendEmbed(
            title: $resolved
                ? '✅ [RESOLVED] '.$alert->name().' models available again'
                : '🚨 [INCIDENT] '.$alert->headline(),
            color: $resolved ? self::COLOR_SUCCESS : self::COLOR_ERROR,
            fields: $fields,
            footer: 'Synaplan Model Health · one alert per provider',
            mentionEveryone: !$resolved && ModelHealthAlert::KIND_CREDENTIAL === $alert->kind,
        );
    }

    /**
     * Render lines as a Discord field value, capped at both the entry count and
     * the API's 1024-character field limit.
     *
     * @param list<string> $lines
     */
    private function bulletList(array $lines, int $maxEntries): string
    {
        $shown = array_slice($lines, 0, $maxEntries);
        $value = '';
        $rendered = 0;
        foreach ($shown as $line) {
            $candidate = $value.'• '.$line."\n";
            if (mb_strlen($candidate) > self::MAX_FIELD_VALUE - 40) {
                break;
            }
            $value = $candidate;
            ++$rendered;
        }

        $omitted = count($lines) - $rendered;
        if ($omitted > 0) {
            $value .= sprintf('… and %d more', $omitted);
        }

        return '' === $value ? '(none)' : $value;
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
