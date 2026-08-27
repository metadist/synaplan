<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\AI\Credential\ProviderKeyCatalog;
use App\AI\Credential\ProviderKeyStore;
use App\AI\Credential\SecretValueGuard;
use App\Repository\ConfigRepository;
use App\Service\Branding\BrandingService;
use App\Service\Client\MobileVersionService;
use App\Service\Digest\MessageDigestConfig;
use App\Service\Dropbox\DropboxOAuthConfig;
use App\Service\EncryptionService;
use App\Service\FeedbackConstants;
use App\Service\GuestChatConfig;
use App\Service\MarketingNews\MarketingNewsConfig;
use App\Service\Mcp\McpClientConfig;
use App\Service\Media\MediaJobConfig;
use App\Service\Message\ConversationSummaryConstants;
use App\Service\Microsoft\MicrosoftOAuthConfig;
use App\Service\Multitask\MultitaskRoutingConfig;
use App\Service\RegistrationConfig;
use App\Service\SavedTask\SavedTaskConfig;
use App\Service\UsageTaximeterConfig;
use Psr\Log\LoggerInterface;

/**
 * System Configuration Service.
 *
 * Manages reading and writing of .env configuration with security masking.
 * Supports database-backed fields (source=database) that take effect immediately.
 * SECURITY: Sensitive fields are NEVER returned in plain text via API.
 */
final readonly class SystemConfigService
{
    private const MASK = '••••••••';
    private const DB_GROUP = 'QDRANT_SEARCH';
    private const DB_OWNER_ID = 0;

    /** @var array<string, array{tab: string, section: string, type: string, sensitive: bool, description: string, default: string, source?: string, options?: array<string>, dbGroup?: string, dbKey?: string, encrypted?: bool, placeholder?: string}> */
    private array $schema;

    public function __construct(
        private readonly string $projectDir,
        private readonly LoggerInterface $logger,
        private readonly ConfigRepository $configRepository,
        private readonly string $defaultTtsUrl,
        private readonly ProviderKeyStore $providerKeyStore,
        private readonly EncryptionService $encryption,
        private readonly RegistrationConfig $registrationConfig,
        private readonly GuestChatConfig $guestChatConfig,
    ) {
        $this->schema = $this->buildSchema();
    }

    /**
     * Get the configuration schema with field definitions.
     *
     * @return array{tabs: array<string, array{label: string, sections: array<string, array{label: string, fields: array<string>}>}>, fields: array<string, array{tab: string, section: string, type: string, sensitive: bool, description: string, default: string, source?: string, options?: array<string>, dbGroup?: string, dbKey?: string, encrypted?: bool, placeholder?: string}>}
     */
    public function getSchema(): array
    {
        $tabs = [
            'ai' => [
                'label' => 'AI Services',
                'sections' => [
                    'ollama' => ['label' => 'Local AI (Ollama)', 'fields' => ['OLLAMA_BASE_URL']],
                    'cloud' => ['label' => 'Cloud AI Providers', 'fields' => ['OPENAI_API_KEY', 'ANTHROPIC_API_KEY', 'GROQ_API_KEY', 'GOOGLE_GEMINI_API_KEY', 'MISTRAL_API_KEY', 'XAI_API_KEY', 'TRUSTEDTOKENS_API_KEY', 'HUGGINGFACE_API_KEY', 'GOOGLE_VERTEX_ACCESS_TOKEN']],
                    'selfhosted' => ['label' => 'Self-Hosted AI', 'fields' => ['TRITON_SERVER_URL']],
                    'media' => ['label' => 'Image & Video Generation', 'fields' => ['THEHIVE_API_KEY', 'HIGGSFIELD_API_KEY', 'HIGGSFIELD_API_SECRET']],
                    'embeddings' => ['label' => 'Embeddings (Cloudflare Workers AI)', 'fields' => ['CLOUDFLARE_ACCOUNT_ID', 'CLOUDFLARE_API_TOKEN', 'EMBEDDING_FALLBACK_PROVIDER']],
                    'tts' => ['label' => 'Text-to-Speech', 'fields' => ['SYNAPLAN_TTS_URL', 'ELEVENLABS_API_KEY']],
                ],
            ],
            'email' => [
                'label' => 'Email',
                'sections' => [
                    'mailer' => ['label' => 'Primary Mailer', 'fields' => ['MAILER_DSN', 'APP_SENDER_EMAIL', 'APP_SENDER_NAME', 'APP_ADMIN_EMAIL']],
                ],
            ],
            'branding' => [
                'label' => 'Branding',
                'sections' => [
                    'identity' => ['label' => 'Brand Identity', 'fields' => ['BRAND_NAME', 'BRAND_TAGLINE', 'BRAND_HOMEPAGE_URL']],
                    'colors' => ['label' => 'Colors', 'fields' => ['BRAND_PRIMARY_COLOR', 'BRAND_SECONDARY_COLOR', 'BRAND_ACCENT_COLOR', 'BRAND_PRIMARY_COLOR_DARK', 'BRAND_SECONDARY_COLOR_DARK', 'BRAND_ACCENT_COLOR_DARK']],
                    'fonts' => ['label' => 'Fonts', 'fields' => ['BRAND_FONT_FAMILY', 'BRAND_HEADING_FONT_FAMILY', 'BRAND_FONT_URL']],
                    'logos' => ['label' => 'Logos & Icon', 'fields' => ['BRAND_LOGO_URL', 'BRAND_LOGO_DARK_URL', 'BRAND_ICON_URL']],
                    'legal' => ['label' => 'Legal Links', 'fields' => ['BRAND_PRIVACY_URL', 'BRAND_TERMS_URL']],
                    'navigation' => ['label' => 'Start Page', 'fields' => ['BRAND_LANDING_PAGE', 'BRAND_DEFAULT_ROUTE']],
                    'attribution' => ['label' => 'Attribution ("Powered by")', 'fields' => ['BRAND_SHOW_POWERED_BY', 'BRAND_POWERED_BY_LABEL', 'BRAND_POWERED_BY_URL']],
                ],
            ],
            'mobile' => [
                'label' => 'Mobile App',
                'sections' => [
                    'update' => ['label' => 'Forced Update Gate', 'fields' => ['MIN_APP_VERSION', 'IOS_APP_URL', 'ANDROID_APP_URL']],
                ],
            ],
            'auth' => [
                'label' => 'Authentication',
                'sections' => [
                    'access' => ['label' => 'Who can use this instance', 'fields' => ['REGISTRATION_ENABLED', 'GUEST_CHAT_ENABLED']],
                    'recaptcha' => ['label' => 'reCAPTCHA v3', 'fields' => ['RECAPTCHA_ENABLED', 'RECAPTCHA_SITE_KEY', 'RECAPTCHA_SECRET_KEY', 'RECAPTCHA_MIN_SCORE']],
                    'google' => ['label' => 'Google OAuth 2.0', 'fields' => ['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'GOOGLE_CLOUD_PROJECT_ID']],
                    'github' => ['label' => 'GitHub OAuth 2.0', 'fields' => ['GITHUB_CLIENT_ID', 'GITHUB_CLIENT_SECRET']],
                    'apple' => ['label' => 'Sign in with Apple', 'fields' => ['APPLE_CLIENT_ID', 'APPLE_TEAM_ID', 'APPLE_KEY_ID', 'APPLE_PRIVATE_KEY', 'APPLE_APP_BUNDLE_ID']],
                    'oidc' => ['label' => 'OIDC (Enterprise SSO)', 'fields' => ['OIDC_DISCOVERY_URL', 'OIDC_CLIENT_ID', 'OIDC_CLIENT_SECRET']],
                ],
            ],
            'channels' => [
                'label' => 'Inbound Channels',
                'sections' => [
                    'whatsapp' => ['label' => 'WhatsApp Business API', 'fields' => ['WHATSAPP_ENABLED', 'WHATSAPP_ACCESS_TOKEN', 'WHATSAPP_WEBHOOK_VERIFY_TOKEN']],
                    'gmail' => ['label' => 'Smart Mail (Gmail IMAP)', 'fields' => ['GMAIL_USERNAME', 'GMAIL_PASSWORD']],
                    'm365' => ['label' => 'Microsoft 365 (Graph)', 'fields' => [
                        'M365_ENABLED', 'M365_CLIENT_ID', 'M365_CLIENT_SECRET', 'M365_TENANT', 'M365_REDIRECT_URI',
                    ]],
                    'dropbox' => ['label' => 'Dropbox', 'fields' => [
                        'DROPBOX_ENABLED', 'DROPBOX_APP_KEY', 'DROPBOX_APP_SECRET', 'DROPBOX_REDIRECT_URI',
                    ]],
                    'mcp' => ['label' => 'MCP servers', 'fields' => ['MCP_CLIENT_ENABLED', 'MCP_OAUTH_CONNECTORS_ENABLED']],
                ],
            ],
            'processing' => [
                'label' => 'Processing',
                'sections' => [
                    'tika' => ['label' => 'Apache Tika', 'fields' => ['TIKA_BASE_URL', 'TIKA_TIMEOUT_MS', 'TIKA_RETRIES', 'TIKA_HTTP_USER', 'TIKA_HTTP_PASS']],
                    'rasterize' => ['label' => 'PDF Rasterizer', 'fields' => ['RASTERIZE_DPI', 'RASTERIZE_PAGE_CAP', 'RASTERIZE_TIMEOUT_MS']],
                    'whisper' => ['label' => 'Whisper (Audio)', 'fields' => ['WHISPER_ENABLED', 'WHISPER_DEFAULT_MODEL']],
                    'brave' => ['label' => 'Web Search (Brave)', 'fields' => ['BRAVE_SEARCH_ENABLED', 'BRAVE_SEARCH_API_KEY', 'BRAVE_SEARCH_COUNT']],
                    'media' => ['label' => 'Async media generation', 'fields' => ['MEDIA_ASYNC_JOBS_ENABLED']],
                ],
            ],
            'routing' => [
                'label' => 'Routing',
                'sections' => [
                    'multitask' => ['label' => 'Multi-task routing', 'fields' => ['MULTITASK_ROUTING_ENABLED']],
                    'saved_tasks' => ['label' => 'Saved Tasks', 'fields' => ['SAVEDTASKS_ENABLED']],
                    'conversation_summary' => ['label' => 'Rolling conversation summary', 'fields' => [
                        'CONVERSATION_SUMMARY_ENABLED',
                        'CONVERSATION_SUMMARY_TARGET_WINDOW_CHARS',
                        'CONVERSATION_SUMMARY_RECENT_VERBATIM_CHARS',
                        'CONVERSATION_SUMMARY_MAX_CHARS',
                        'CONVERSATION_SUMMARY_MAX_SOURCE_MESSAGES',
                        'CONVERSATION_SUMMARY_TIERS',
                        'CONVERSATION_SUMMARY_CACHE_TTL',
                    ]],
                    'deep_memory' => ['label' => 'Deep memory (message digests)', 'fields' => [
                        'DIGEST_ENABLED',
                        'DIGEST_TOP_K',
                        'DIGEST_MIN_SCORE',
                        'DIGEST_RECENCY_HALF_LIFE_DAYS',
                        'DIGEST_PULL_TOP_N',
                        'DIGEST_PULL_MIN_SCORE',
                        'DIGEST_BLOCK_MAX_CHARS',
                        'DIGEST_BATCH_SIZE',
                        'DIGEST_MAX_BATCHES_PER_USER',
                        'DIGEST_QUIET_SECONDS',
                        'DIGEST_MAX_PER_USER',
                    ]],
                ],
            ],
            'interface' => [
                'label' => 'Interface',
                'sections' => [
                    'usage_display' => ['label' => 'Usage display', 'fields' => ['USAGE_TAXIMETER_ENABLED']],
                ],
            ],
            'guest_landing' => [
                'label' => 'Guest Landing',
                'sections' => [
                    'marketing_news' => ['label' => 'Marketing News', 'fields' => [
                        'MARKETING_NEWS_ENABLED',
                        'MARKETING_NEWS_FEED_URL_EN',
                        'MARKETING_NEWS_FEED_URL_DE',
                        'MARKETING_NEWS_FEED_URL_DEFAULT',
                    ]],
                ],
            ],
            'vectordb' => [
                'label' => 'Vector DB',
                'sections' => [
                    'qdrant' => ['label' => 'Qdrant', 'fields' => ['QDRANT_URL']],
                    'qdrant_search' => ['label' => 'Search Thresholds', 'fields' => [
                        'MIN_CHAT_FEEDBACK_SCORE', 'MIN_CHAT_MEMORY_SCORE', 'MIN_CONTRADICTION_SCORE',
                        'MIN_RESEARCH_SCORE', 'MIN_MEMORY_RESEARCH_SCORE', 'MIN_EXTRACTION_SCORE',
                        'LIMIT_PER_NAMESPACE', 'MAX_CHAT_MEMORIES',
                    ]],
                ],
            ],
        ];

        return [
            'tabs' => $tabs,
            'fields' => $this->schema,
        ];
    }

    /**
     * Get current configuration values with sensitive fields masked.
     *
     * @return array<string, array{value: string, isSet: bool, isMasked: bool, effectiveForMe?: string, hasPersonalOverride?: bool, envOverride?: bool, effectiveValue?: string}>
     */
    public function getValues(?int $actingUserId = null): array
    {
        $values = [];

        foreach ($this->schema as $key => $field) {
            $source = $field['source'] ?? 'env';

            // Cloud provider API keys live in the encrypted ProviderKeyStore
            // (BCONFIG), not in .env — report their status from there so this
            // legacy surface and the provider-key wizard agree.
            $storeProvider = ProviderKeyCatalog::providerForEnvVar($key);
            if (null !== $storeProvider) {
                $status = $this->providerKeyStore->getStatus($storeProvider);
                $values[$key] = [
                    'value' => $status['configured'] ? self::MASK : $field['default'],
                    'isSet' => $status['configured'],
                    'isMasked' => $status['configured'],
                ];
                continue;
            }

            if ('database' === $source) {
                $rawValue = $this->configRepository->getValue(
                    self::DB_OWNER_ID,
                    $field['dbGroup'] ?? self::DB_GROUP,
                    $field['dbKey'] ?? $key,
                );
                $isSet = null !== $rawValue && '' !== $rawValue;

                // A database-backed secret (e.g. an OAuth client secret) is
                // stored encrypted and must be masked here for the same reason
                // an env secret is: this response reaches the admin UI.
                if ($field['sensitive']) {
                    $values[$key] = [
                        'value' => $isSet ? self::MASK : $field['default'],
                        'isSet' => $isSet,
                        'isMasked' => $isSet,
                    ];
                    continue;
                }

                $values[$key] = [
                    'value' => $rawValue ?? $field['default'],
                    'isSet' => $isSet,
                    'isMasked' => false,
                ];
            } else {
                $rawValue = $this->getEnvValue($key);
                $isSet = '' !== $rawValue && null !== $rawValue;

                if ($field['sensitive'] && $isSet) {
                    $values[$key] = [
                        'value' => self::MASK,
                        'isSet' => true,
                        'isMasked' => true,
                    ];
                } else {
                    $values[$key] = [
                        'value' => $rawValue ?? $field['default'],
                        'isSet' => $isSet,
                        'isMasked' => false,
                    ];
                }
            }
        }

        // The access-surface flags are stored in BCONFIG but an explicit
        // environment variable still wins. Report that, otherwise the page shows
        // a toggle the operator can move while nothing changes — the exact kind
        // of silent no-op that costs an afternoon of debugging.
        foreach ([
            'REGISTRATION_ENABLED' => $this->registrationConfig->envOverride(),
            'GUEST_CHAT_ENABLED' => $this->guestChatConfig->envOverride(),
        ] as $key => $envOverride) {
            if (!isset($values[$key]) || null === $envOverride) {
                continue;
            }

            $values[$key]['envOverride'] = true;
            $values[$key]['effectiveValue'] = $envOverride ? 'true' : 'false';
        }

        // #1079: surface effective multitask routing for the acting admin so the
        // UI can warn when a personal BCONFIG override shadows the global toggle.
        if (null !== $actingUserId && $actingUserId > 0 && isset($values['MULTITASK_ROUTING_ENABLED'])) {
            $personal = $this->configRepository->getValue(
                $actingUserId,
                MultitaskRoutingConfig::CONFIG_GROUP,
                MultitaskRoutingConfig::KEY_ROUTING_ENABLED,
            );
            $hasOverride = null !== $personal && '' !== $personal;
            $effective = $hasOverride
                ? $personal
                : $values['MULTITASK_ROUTING_ENABLED']['value'];
            $values['MULTITASK_ROUTING_ENABLED']['hasPersonalOverride'] = $hasOverride;
            $values['MULTITASK_ROUTING_ENABLED']['effectiveForMe'] = filter_var(
                $effective,
                \FILTER_VALIDATE_BOOL,
                \FILTER_NULL_ON_FAILURE
            ) ? 'true' : 'false';
        }

        return $values;
    }

    /**
     * Update a single configuration value.
     *
     * @return array{success: bool, requiresRestart: bool, message?: string}
     */
    public function setValue(string $key, string $value, ?int $actingUserId = null): array
    {
        if (!isset($this->schema[$key])) {
            return ['success' => false, 'requiresRestart' => false, 'message' => 'Unknown configuration key'];
        }

        $field = $this->schema[$key];
        $source = $field['source'] ?? 'env';

        // Reading a sensitive field returns self::MASK, so a client that submits
        // the form unchanged (or retries it) sends the mask back. Storing that
        // would silently destroy a working secret — refuse it server-side
        // instead of relying on the frontend to filter it out.
        if ($field['sensitive'] && SecretValueGuard::isMasked($value)) {
            return [
                'success' => false,
                'requiresRestart' => false,
                'message' => 'That is the masked placeholder, not a real value. Leave the field untouched to keep the current secret, or enter a new one.',
            ];
        }

        // Cloud provider API keys are stored encrypted in the ProviderKeyStore
        // and apply without a restart (providers resolve keys per call). An empty
        // value removes the stored key (an env fallback, if set, then applies
        // again) — the admin UI clears keys on the setup page instead, so this
        // branch serves API clients that PUT an empty string.
        $storeProvider = ProviderKeyCatalog::providerForEnvVar($key);
        if (null !== $storeProvider) {
            try {
                if ('' === trim($value)) {
                    $this->providerKeyStore->deleteKey($storeProvider);
                } else {
                    $this->providerKeyStore->saveKey($storeProvider, $value, ProviderKeyStore::ORIGIN_UI);
                }
                $this->logChange($key, $value);

                return ['success' => true, 'requiresRestart' => false];
            } catch (\InvalidArgumentException $e) {
                // Rejected value (placeholder, mask) — the message names the
                // problem and is safe to show: it never contains a real key.
                return ['success' => false, 'requiresRestart' => false, 'message' => $e->getMessage()];
            } catch (\Throwable $e) {
                $this->logger->error('Failed to save provider key via system config', [
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);

                return ['success' => false, 'requiresRestart' => false, 'message' => 'Failed to save the API key'];
            }
        }

        // Database-backed fields: write to BCONFIG, no restart needed
        if ('database' === $source) {
            return $this->setDatabaseValue($key, $value, $field, $actingUserId);
        }

        $envFile = $this->projectDir.'/.env';
        if (!file_exists($envFile)) {
            return ['success' => false, 'requiresRestart' => false, 'message' => '.env file not found'];
        }

        // Create backup
        $backupFile = $this->createBackup();
        if (!$backupFile) {
            return ['success' => false, 'requiresRestart' => false, 'message' => 'Failed to create backup'];
        }

        // Read current file
        $content = file_get_contents($envFile);
        if (false === $content) {
            return ['success' => false, 'requiresRestart' => false, 'message' => 'Failed to read .env file'];
        }

        // Update or add the value
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
        $replacement = $key.'='.$this->escapeEnvValue($value);

        if (preg_match($pattern, $content)) {
            $newContent = preg_replace($pattern, $replacement, $content);
        } else {
            // Append to file
            $newContent = rtrim($content)."\n".$replacement."\n";
        }

        // Atomic write: write to temp file, then rename
        $tempFile = $envFile.'.tmp';
        if (false === file_put_contents($tempFile, $newContent)) {
            return ['success' => false, 'requiresRestart' => false, 'message' => 'Failed to write temp file'];
        }

        if (!rename($tempFile, $envFile)) {
            @unlink($tempFile);

            return ['success' => false, 'requiresRestart' => false, 'message' => 'Failed to save .env file'];
        }

        // Log the change (mask sensitive values)
        $this->logChange($key, $value);

        return ['success' => true, 'requiresRestart' => true];
    }

    /**
     * Save a database-backed configuration value with validation.
     *
     * @param array{type: string, default: string, dbGroup?: string, dbKey?: string, encrypted?: bool} $field
     *
     * @return array{success: bool, requiresRestart: bool, message?: string}
     */
    private function setDatabaseValue(string $key, string $value, array $field, ?int $actingUserId = null): array
    {
        // Validate numeric fields
        if ('number' === $field['type']) {
            if (!is_numeric($value)) {
                return ['success' => false, 'requiresRestart' => false, 'message' => 'Value must be numeric'];
            }

            $numericValue = (float) $value;

            // Score fields must be between 0.0 and 1.0
            if (str_starts_with($key, 'MIN_') && ($numericValue < 0.0 || $numericValue > 1.0)) {
                return ['success' => false, 'requiresRestart' => false, 'message' => 'Score must be between 0.0 and 1.0'];
            }

            // Limit fields must be positive integers
            if (str_starts_with($key, 'LIMIT_') || str_starts_with($key, 'MAX_')) {
                if ($numericValue < 1 || floor($numericValue) !== $numericValue) {
                    return ['success' => false, 'requiresRestart' => false, 'message' => 'Limit must be a positive integer'];
                }
            }

            // Rolling-summary sizes are whole counts (characters, messages,
            // tiers, seconds). ConversationSummaryConfigService discards a
            // non-positive value and uses its default, so reject it here
            // instead of storing a row that does nothing.
            if (ConversationSummaryConstants::CONFIG_GROUP === ($field['dbGroup'] ?? null)
                && ($numericValue < 1 || floor($numericValue) !== $numericValue)
            ) {
                return ['success' => false, 'requiresRestart' => false, 'message' => 'Value must be a positive whole number'];
            }

            // Deep-memory knobs: scores are 0.0–1.0 floats; PULL_TOP_N may be
            // 0 (disables verbatim pulling); everything else is a positive
            // whole number. MessageDigestConfig clamps out-of-range values,
            // so a row that would be silently corrected is rejected here.
            if (MessageDigestConfig::CONFIG_GROUP === ($field['dbGroup'] ?? null)) {
                if (str_contains($key, 'MIN_SCORE')) {
                    if ($numericValue < 0.0 || $numericValue > 1.0) {
                        return ['success' => false, 'requiresRestart' => false, 'message' => 'Score must be between 0.0 and 1.0'];
                    }
                } elseif (floor($numericValue) !== $numericValue
                    || $numericValue < ('DIGEST_PULL_TOP_N' === $key ? 0 : 1)
                ) {
                    return ['success' => false, 'requiresRestart' => false, 'message' => 'DIGEST_PULL_TOP_N' === $key
                        ? 'Value must be a whole number (0 disables pulling)'
                        : 'Value must be a positive whole number'];
                }
            }
        }

        $group = $field['dbGroup'] ?? self::DB_GROUP;
        $setting = $field['dbKey'] ?? $key;

        // Encrypted fields hold a real credential; clearing one means storing
        // an empty row, not an empty ciphertext nobody can distinguish.
        $stored = ($field['encrypted'] ?? false) && '' !== $value
            ? $this->encryption->encrypt($value)
            : $value;

        try {
            $this->configRepository->setValue(self::DB_OWNER_ID, $group, $setting, $stored);
            $this->logChange($key, $value);

            $this->applyConfigSideEffects($group, $setting, $value, $actingUserId);

            return ['success' => true, 'requiresRestart' => false];
        } catch (\Throwable $e) {
            $this->logger->error('Failed to save DB config', ['key' => $key, 'error' => $e->getMessage()]);

            return ['success' => false, 'requiresRestart' => false, 'message' => 'Database write failed'];
        }
    }

    /**
     * Side-effects triggered by specific BCONFIG writes.
     *
     * Kept as an explicit, narrow dispatch table rather than an event
     * subscriber. Add new entries here only when a config write has to mutate
     * state outside BCONFIG. Failures are logged and swallowed: the primary
     * BCONFIG write has already succeeded.
     */
    private function applyConfigSideEffects(string $group, string $key, string $value, ?int $actingUserId = null): void
    {
        // Multi-task routing master switch: a per-user row overrides this global
        // flag (the Version20260607000000 grandfather rows are gone since
        // Version20260706130000, but hand-set overrides can still exist). Drop
        // the acting admin's own override so the value they just set actually
        // applies to their own account immediately.
        if (SavedTaskConfig::CONFIG_GROUP === $group
            && SavedTaskConfig::KEY_ENABLED === $key
            && null !== $actingUserId && $actingUserId > 0
        ) {
            try {
                $removed = $this->configRepository->deleteValue(
                    $actingUserId,
                    SavedTaskConfig::CONFIG_GROUP,
                    SavedTaskConfig::KEY_ENABLED,
                );
                $this->logger->info('SystemConfigService: cleared admin per-user saved-tasks override', [
                    'userId' => $actingUserId,
                    'removed' => $removed,
                    'globalValue' => $value,
                ]);
            } catch (\Throwable $sideEffect) {
                $this->logger->error('SystemConfigService: failed clearing per-user saved-tasks override', [
                    'userId' => $actingUserId,
                    'error' => $sideEffect->getMessage(),
                ]);
            }
        }

        if (McpClientConfig::CONFIG_GROUP === $group
            && McpClientConfig::KEY_CLIENT_ENABLED === $key
            && null !== $actingUserId && $actingUserId > 0
        ) {
            try {
                $removed = $this->configRepository->deleteValue(
                    $actingUserId,
                    McpClientConfig::CONFIG_GROUP,
                    McpClientConfig::KEY_CLIENT_ENABLED,
                );
                $this->logger->info('SystemConfigService: cleared admin per-user MCP client override', [
                    'userId' => $actingUserId,
                    'removed' => $removed,
                    'globalValue' => $value,
                ]);
            } catch (\Throwable $sideEffect) {
                $this->logger->error('SystemConfigService: failed clearing per-user MCP client override', [
                    'userId' => $actingUserId,
                    'error' => $sideEffect->getMessage(),
                ]);
            }
        }

        if (MultitaskRoutingConfig::CONFIG_GROUP === $group
            && MultitaskRoutingConfig::KEY_ROUTING_ENABLED === $key
            && null !== $actingUserId && $actingUserId > 0
        ) {
            try {
                $removed = $this->configRepository->deleteValue(
                    $actingUserId,
                    MultitaskRoutingConfig::CONFIG_GROUP,
                    MultitaskRoutingConfig::KEY_ROUTING_ENABLED,
                );
                $this->logger->info('SystemConfigService: cleared admin per-user multitask routing override', [
                    'userId' => $actingUserId,
                    'removed' => $removed,
                    'globalValue' => $value,
                ]);
            } catch (\Throwable $sideEffect) {
                $this->logger->error('SystemConfigService: failed clearing per-user multitask override', [
                    'userId' => $actingUserId,
                    'error' => $sideEffect->getMessage(),
                ]);
            }
        }

        // Async media master switch: existing users were grandfathered to an
        // explicit per-user OFF row (migration Version20260629120000), which
        // overrides this global flag. Drop the acting admin's own override so the
        // value they just set actually applies to their own account immediately.
        if (MediaJobConfig::CONFIG_GROUP === $group
            && MediaJobConfig::KEY_ASYNC_JOBS_ENABLED === $key
            && null !== $actingUserId && $actingUserId > 0
        ) {
            try {
                $removed = $this->configRepository->deleteValue(
                    $actingUserId,
                    MediaJobConfig::CONFIG_GROUP,
                    MediaJobConfig::KEY_ASYNC_JOBS_ENABLED,
                );
                $this->logger->info('SystemConfigService: cleared admin per-user async media override', [
                    'userId' => $actingUserId,
                    'removed' => $removed,
                    'globalValue' => $value,
                ]);
            } catch (\Throwable $sideEffect) {
                $this->logger->error('SystemConfigService: failed clearing per-user async media override', [
                    'userId' => $actingUserId,
                    'error' => $sideEffect->getMessage(),
                ]);
            }
        }
    }

    /**
     * Test connection to a service.
     *
     * @return array{success: bool, message: string, details?: array<string, mixed>}
     */
    public function testConnection(string $service): array
    {
        return match ($service) {
            'ollama' => $this->testOllama(),
            'tika' => $this->testTika(),
            'qdrant' => $this->testQdrant(),
            'mailer' => $this->testMailer(),
            'piper' => $this->testPiperTts(),
            default => ['success' => false, 'message' => 'Unknown service: '.$service],
        };
    }

    /**
     * Get list of available backups.
     *
     * @return array<array{id: string, timestamp: string, size: int}>
     */
    public function getBackups(): array
    {
        $backupDir = $this->projectDir.'/var/env-backups';
        if (!is_dir($backupDir)) {
            return [];
        }

        $files = glob($backupDir.'/.env.backup.*');
        if (false === $files) {
            return [];
        }

        $backups = [];
        foreach ($files as $file) {
            $filename = basename($file);
            if (preg_match('/\.env\.backup\.(\d{8}_\d{6})$/', $filename, $matches)) {
                $dt = \DateTime::createFromFormat('Ymd_His', $matches[1]);
                $backups[] = [
                    'id' => $matches[1],
                    'timestamp' => false !== $dt ? $dt->format('Y-m-d H:i:s') : $matches[1],
                    'size' => filesize($file) ?: 0,
                ];
            }
        }

        // Sort by timestamp descending
        usort($backups, fn ($a, $b) => strcmp($b['id'], $a['id']));

        return array_slice($backups, 0, 10); // Keep last 10
    }

    /**
     * Restore a backup.
     *
     * @return array{success: bool, message: string}
     */
    public function restoreBackup(string $backupId): array
    {
        $backupDir = $this->projectDir.'/var/env-backups';
        $backupFile = $backupDir.'/.env.backup.'.$backupId;

        if (!file_exists($backupFile)) {
            return ['success' => false, 'message' => 'Backup not found'];
        }

        $envFile = $this->projectDir.'/.env';

        // Create backup of current state before restore
        $this->createBackup();

        // Copy backup to .env
        if (!copy($backupFile, $envFile)) {
            return ['success' => false, 'message' => 'Failed to restore backup'];
        }

        $this->logger->info('Restored .env from backup', ['backup_id' => $backupId]);

        return ['success' => true, 'message' => 'Backup restored successfully'];
    }

    /**
     * Create a backup of the current .env file.
     */
    private function createBackup(): ?string
    {
        $envFile = $this->projectDir.'/.env';
        if (!file_exists($envFile)) {
            return null;
        }

        $backupDir = $this->projectDir.'/var/env-backups';
        if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true)) {
            return null;
        }

        $timestamp = date('Ymd_His');
        $backupFile = $backupDir.'/.env.backup.'.$timestamp;

        if (!copy($envFile, $backupFile)) {
            return null;
        }

        // Clean up old backups (keep last 5)
        $this->cleanupOldBackups($backupDir, 5);

        return $backupFile;
    }

    private function cleanupOldBackups(string $dir, int $keep): void
    {
        $files = glob($dir.'/.env.backup.*');
        if (false === $files || count($files) <= $keep) {
            return;
        }

        // Sort by name (timestamp) descending
        rsort($files);

        // Delete old ones
        foreach (array_slice($files, $keep) as $file) {
            @unlink($file);
        }
    }

    private function getEnvValue(string $key): ?string
    {
        // First check $_ENV, then getenv()
        if (isset($_ENV[$key])) {
            return $_ENV[$key];
        }

        $value = getenv($key);

        return false !== $value ? $value : null;
    }

    private function escapeEnvValue(string $value): string
    {
        // If value contains special characters, quote it
        if (preg_match('/[\s#\'"]/', $value) || str_contains($value, '=')) {
            // Escape existing quotes and wrap in quotes
            $escaped = str_replace('"', '\\"', $value);

            return '"'.$escaped.'"';
        }

        return $value;
    }

    private function logChange(string $key, string $value): void
    {
        $field = $this->schema[$key] ?? null;
        $logValue = ($field && $field['sensitive']) ? self::MASK : $value;

        $this->logger->info('System config changed', [
            'key' => $key,
            'value' => $logValue,
            'sensitive' => $field['sensitive'] ?? false,
        ]);
    }

    /**
     * @return array{success: bool, message: string, details?: array<string, mixed>}
     */
    private function testOllama(): array
    {
        $url = $this->getEnvValue('OLLAMA_BASE_URL');
        if (!$url) {
            return ['success' => false, 'message' => 'OLLAMA_BASE_URL not configured'];
        }

        try {
            $ch = curl_init($url.'/api/tags');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (200 === $httpCode && $response) {
                $data = json_decode($response, true);

                return [
                    'success' => true,
                    'message' => 'Connected to Ollama',
                    'details' => ['models' => count($data['models'] ?? [])],
                ];
            }

            return ['success' => false, 'message' => 'Ollama returned HTTP '.$httpCode];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Connection failed: '.$e->getMessage()];
        }
    }

    /**
     * @return array{success: bool, message: string, details?: array<string, mixed>}
     */
    private function testTika(): array
    {
        $url = $this->getEnvValue('TIKA_BASE_URL');
        if (!$url) {
            return ['success' => false, 'message' => 'TIKA_BASE_URL not configured'];
        }

        try {
            $ch = curl_init($url.'/tika');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);

            // Send HTTP Basic Auth when Tika is protected (same pattern as TikaClient)
            $httpUser = $this->getEnvValue('TIKA_HTTP_USER');
            if (!empty($httpUser)) {
                curl_setopt($ch, CURLOPT_USERPWD, $httpUser.':'.($this->getEnvValue('TIKA_HTTP_PASS') ?? ''));
            }

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (200 === $httpCode) {
                return ['success' => true, 'message' => 'Connected to Apache Tika'];
            }

            return ['success' => false, 'message' => 'Tika returned HTTP '.$httpCode];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Connection failed: '.$e->getMessage()];
        }
    }

    /**
     * @return array{success: bool, message: string, details?: array<string, mixed>}
     */
    private function testQdrant(): array
    {
        $url = $this->getEnvValue('QDRANT_URL');
        if (!$url) {
            return ['success' => false, 'message' => 'QDRANT_URL not configured'];
        }

        try {
            $ch = curl_init($url.'/healthz');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (200 === $httpCode) {
                return ['success' => true, 'message' => 'Connected to Qdrant'];
            }

            return ['success' => false, 'message' => 'Qdrant returned HTTP '.$httpCode];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Connection failed: '.$e->getMessage()];
        }
    }

    /**
     * @return array{success: bool, message: string, details?: array<string, mixed>}
     */
    private function testPiperTts(): array
    {
        $url = $this->getEnvValue('SYNAPLAN_TTS_URL');
        if (!$url) {
            $url = $this->defaultTtsUrl;
        }

        try {
            $ch = curl_init($url.'/health');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (200 === $httpCode && $response) {
                $data = json_decode($response, true);

                return [
                    'success' => true,
                    'message' => 'Connected to Piper TTS',
                    'details' => [
                        'status' => $data['status'] ?? 'unknown',
                        'voices' => $data['voices'] ?? [],
                    ],
                ];
            }

            return ['success' => false, 'message' => 'Piper TTS returned HTTP '.$httpCode];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Connection failed: '.$e->getMessage()];
        }
    }

    /**
     * @return array{success: bool, message: string}
     */
    private function testMailer(): array
    {
        $dsn = $this->getEnvValue('MAILER_DSN');
        if (!$dsn || 'null://null' === $dsn) {
            return ['success' => false, 'message' => 'Mailer not configured (using null transport)'];
        }

        // Basic DSN validation
        if (!str_starts_with($dsn, 'smtp://') && !str_starts_with($dsn, 'sendmail://')) {
            return ['success' => true, 'message' => 'Mailer DSN configured (cannot test without sending)'];
        }

        return ['success' => true, 'message' => 'Mailer DSN configured'];
    }

    /**
     * Build the configuration schema.
     *
     * @return array<string, array{tab: string, section: string, type: string, sensitive: bool, description: string, default: string, source?: string, options?: array<string>, dbGroup?: string, dbKey?: string, encrypted?: bool, placeholder?: string}>
     */
    private function buildSchema(): array
    {
        return [
            // === Access surface (database-backed, no restart required) ===
            // Written by the first-run setup wizard and editable here. An
            // explicit REGISTRATION_ENABLED / GUEST_CHAT_ENABLED environment
            // variable still wins over the stored row — getValues() reports that
            // as `envOverride` so this page never shows a toggle that silently
            // does nothing.
            'REGISTRATION_ENABLED' => [
                'tab' => 'auth', 'section' => 'access', 'type' => 'boolean',
                'sensitive' => false,
                'description' => 'Allow visitors to create their own account with email and password. Turn this off for an invite-only instance (an administrator then creates every account) or for SSO-only deployments. The sign-up page and the API both refuse when off.',
                'default' => 'true',
                'source' => 'database',
                'dbGroup' => RegistrationConfig::CONFIG_GROUP,
                'dbKey' => RegistrationConfig::KEY_ENABLED,
            ],
            'GUEST_CHAT_ENABLED' => [
                'tab' => 'auth', 'section' => 'access', 'type' => 'boolean',
                'sensitive' => false,
                'description' => 'Let visitors try the chat without signing in. Turn this off so everyone has to sign in first — unauthenticated visitors are sent to the login page and every guest endpoint is refused.',
                'default' => 'true',
                'source' => 'database',
                'dbGroup' => GuestChatConfig::CONFIG_GROUP,
                'dbKey' => GuestChatConfig::KEY_ENABLED,
            ],
            // === Routing (database-backed, no restart required) ===
            // Stored in BCONFIG group MULTITASK / setting ROUTING_ENABLED (the row
            // MultitaskRoutingConfig reads), not the default QDRANT_SEARCH group.
            'MULTITASK_ROUTING_ENABLED' => [
                'tab' => 'routing', 'section' => 'multitask', 'type' => 'boolean',
                'sensitive' => false,
                'description' => 'Route messages through the multi-task planner (turns a request into a small DAG of capability tasks). When OFF, the legacy single-topic AI sorter handles routing. Global default for all users; an explicit per-user BCONFIG row still overrides it.',
                'default' => 'true',
                'source' => 'database',
                'dbGroup' => MultitaskRoutingConfig::CONFIG_GROUP,
                'dbKey' => MultitaskRoutingConfig::KEY_ROUTING_ENABLED,
            ],
            // Outbound MCP client master switch (BCONFIG group MCP / CLIENT_ENABLED).
            // Also toggled from Channels → MCP Servers. Seeded ON for new installs;
            // an explicit 0 row is the operator kill switch.
            'MCP_CLIENT_ENABLED' => [
                'tab' => 'channels', 'section' => 'mcp', 'type' => 'boolean',
                'sensitive' => false,
                'description' => 'Allow the assistant to call connected MCP servers (Jira, Confluence, CRM, and any other MCP endpoint). When off, saved connections stay in place but no calls are made. You can also turn this on from Channels → MCP Servers.',
                'default' => 'true',
                'source' => 'database',
                'dbGroup' => McpClientConfig::CONFIG_GROUP,
                'dbKey' => McpClientConfig::KEY_CLIENT_ENABLED,
            ],
            'MCP_OAUTH_CONNECTORS_ENABLED' => [
                'tab' => 'channels', 'section' => 'mcp', 'type' => 'boolean',
                'sensitive' => false,
                'description' => 'Let users connect remote MCP servers that sign in with OAuth (Notion, Higgsfield, and any other standard remote MCP). When off, users can still add servers that use an access token. Seeded off — turn this on after you have reviewed the connections page.',
                'default' => 'false',
                'source' => 'database',
                'dbGroup' => McpClientConfig::CONFIG_GROUP,
                'dbKey' => McpClientConfig::KEY_OAUTH_CONNECTORS_ENABLED,
            ],
            // === Microsoft 365 app registration (database-backed) ===
            // BCONFIG group M365 (ownerId=0), read by MicrosoftOAuthConfig.
            // Operator-owned and install-wide: Synaplan Cloud runs a
            // multi-tenant registration, self-hosters register their own
            // (connector plan 07 §S3). Users never see these values — they only
            // click "Connect Microsoft 365" and consent.
            'M365_ENABLED' => [
                'tab' => 'channels', 'section' => 'm365', 'type' => 'boolean',
                'sensitive' => false,
                'description' => 'Offer Microsoft 365 as a connection. Requires a client ID and client secret from an Azure app registration; the "Connect Microsoft 365" action stays hidden until all three are set.',
                'default' => 'false',
                'source' => 'database',
                'dbGroup' => MicrosoftOAuthConfig::CONFIG_GROUP,
                'dbKey' => MicrosoftOAuthConfig::KEY_ENABLED,
            ],
            'M365_CLIENT_ID' => [
                'tab' => 'channels', 'section' => 'm365', 'type' => 'text',
                'sensitive' => false,
                'description' => 'Application (client) ID of the Azure app registration. Azure portal → App registrations → your app → Overview.',
                'default' => '',
                'placeholder' => '11111111-2222-3333-4444-555555555555',
                'source' => 'database',
                'dbGroup' => MicrosoftOAuthConfig::CONFIG_GROUP,
                'dbKey' => MicrosoftOAuthConfig::KEY_CLIENT_ID,
            ],
            'M365_CLIENT_SECRET' => [
                'tab' => 'channels', 'section' => 'm365', 'type' => 'password',
                'sensitive' => true,
                'encrypted' => true,
                'description' => 'Client secret from Azure portal → your app → Certificates & secrets → New client secret. Copy the "Value" column, NOT the "Secret ID". Stored encrypted and never shown again; leave the field untouched to keep the current one.',
                'default' => '',
                'placeholder' => 'Example: 8Qm~aB1cD2eF3gH4iJ5kL6mN7oP8qR9sT0uV1wX2',
                'source' => 'database',
                'dbGroup' => MicrosoftOAuthConfig::CONFIG_GROUP,
                'dbKey' => MicrosoftOAuthConfig::KEY_CLIENT_SECRET,
            ],
            'M365_TENANT' => [
                'tab' => 'channels', 'section' => 'm365', 'type' => 'text',
                'sensitive' => false,
                'description' => 'Which accounts may sign in: "common" (work, school and personal accounts), "organizations" (work and school only), or a single tenant GUID to allow only your own organisation. Self-hosters normally use their own tenant GUID (Azure portal → your app → Overview → Directory (tenant) ID).',
                'default' => MicrosoftOAuthConfig::DEFAULT_TENANT,
                'placeholder' => 'common — or 11111111-2222-3333-4444-555555555555',
                'source' => 'database',
                'dbGroup' => MicrosoftOAuthConfig::CONFIG_GROUP,
                'dbKey' => MicrosoftOAuthConfig::KEY_TENANT,
            ],
            'M365_REDIRECT_URI' => [
                'tab' => 'channels', 'section' => 'm365', 'type' => 'text',
                'sensitive' => false,
                'description' => 'Only needed when a proxy changes the public URL. Leave empty to use APP_URL + '.MicrosoftOAuthConfig::CALLBACK_PATH.'. Whatever is used here must be registered in Azure character for character.',
                'default' => '',
                'placeholder' => 'https://your-synaplan-host'.MicrosoftOAuthConfig::CALLBACK_PATH,
                'source' => 'database',
                'dbGroup' => MicrosoftOAuthConfig::CONFIG_GROUP,
                'dbKey' => MicrosoftOAuthConfig::KEY_REDIRECT_URI,
            ],
            // === Dropbox app (database-backed) ===
            // BCONFIG group DROPBOX (ownerId=0), read by DropboxOAuthConfig.
            // Operator-owned and install-wide, exactly like the M365 block
            // above (connector plan 07 C13). Users never see these values —
            // they only click "Connect Dropbox" and consent.
            'DROPBOX_ENABLED' => [
                'tab' => 'channels', 'section' => 'dropbox', 'type' => 'boolean',
                'sensitive' => false,
                'description' => 'Offer Dropbox as a connection. Requires an app key and app secret from a Dropbox app; the "Connect Dropbox" action stays hidden until all three are set.',
                'default' => 'false',
                'source' => 'database',
                'dbGroup' => DropboxOAuthConfig::CONFIG_GROUP,
                'dbKey' => DropboxOAuthConfig::KEY_ENABLED,
            ],
            'DROPBOX_APP_KEY' => [
                'tab' => 'channels', 'section' => 'dropbox', 'type' => 'text',
                'sensitive' => false,
                'description' => 'App key of the Dropbox app. Dropbox App Console (dropbox.com/developers/apps) → your app → Settings.',
                'default' => '',
                'placeholder' => 'a1b2c3d4e5f6g7h',
                'source' => 'database',
                'dbGroup' => DropboxOAuthConfig::CONFIG_GROUP,
                'dbKey' => DropboxOAuthConfig::KEY_APP_KEY,
            ],
            'DROPBOX_APP_SECRET' => [
                'tab' => 'channels', 'section' => 'dropbox', 'type' => 'password',
                'sensitive' => true,
                'encrypted' => true,
                'description' => 'App secret from the Dropbox App Console → your app → Settings → App secret (click "Show"). Stored encrypted and never shown again; leave the field untouched to keep the current one.',
                'default' => '',
                'placeholder' => 'Example: z9y8x7w6v5u4t3s',
                'source' => 'database',
                'dbGroup' => DropboxOAuthConfig::CONFIG_GROUP,
                'dbKey' => DropboxOAuthConfig::KEY_APP_SECRET,
            ],
            'DROPBOX_REDIRECT_URI' => [
                'tab' => 'channels', 'section' => 'dropbox', 'type' => 'text',
                'sensitive' => false,
                'description' => 'Only needed when a proxy changes the public URL. Leave empty to use APP_URL + '.DropboxOAuthConfig::CALLBACK_PATH.'. Whatever is used here must be registered in the Dropbox App Console character for character.',
                'default' => '',
                'placeholder' => 'https://your-synaplan-host'.DropboxOAuthConfig::CALLBACK_PATH,
                'source' => 'database',
                'dbGroup' => DropboxOAuthConfig::CONFIG_GROUP,
                'dbKey' => DropboxOAuthConfig::KEY_REDIRECT_URI,
            ],
            'SAVEDTASKS_ENABLED' => [
                'tab' => 'routing', 'section' => 'saved_tasks', 'type' => 'boolean',
                'sensitive' => false,
                'description' => 'Allow users to pin a Task Prompt as a Saved Task and run it on demand or on a schedule. When OFF, AI Instructions stay unchanged and no Saved Task APIs or UI are exposed. Per-user BCONFIG row overrides the global row; code default is OFF when no row exists.',
                'default' => 'true',
                'source' => 'database',
                'dbGroup' => SavedTaskConfig::CONFIG_GROUP,
                'dbKey' => SavedTaskConfig::KEY_ENABLED,
            ],
            // === Routing — rolling conversation summary (database-backed) ===
            // BCONFIG group CONVERSATION_SUMMARY (ownerId=0), the rows
            // ConversationSummaryConfigService reads. No row means "use the
            // ConversationSummaryConstants default", so the defaults below must
            // stay in sync with that class.
            'CONVERSATION_SUMMARY_ENABLED' => [
                'tab' => 'routing', 'section' => 'conversation_summary', 'type' => 'boolean',
                'sensitive' => false,
                'description' => 'Condense the earlier part of a long chat into a rolling summary that is injected into the system prompt, while the newest turns are still replayed word for word. Keeps the topic, the user\'s position and past decisions alive many answers later. The summary is written asynchronously after each turn by the Summary model (configurable under AI → Routing), so answering stays snappy. When OFF, a long chat only ever sees the most recent turns.',
                'default' => var_export(ConversationSummaryConstants::ENABLED, true),
                'source' => 'database',
                'dbGroup' => ConversationSummaryConstants::CONFIG_GROUP,
                'dbKey' => ConversationSummaryConstants::KEY_ENABLED,
            ],
            'CONVERSATION_SUMMARY_TARGET_WINDOW_CHARS' => [
                'tab' => 'routing', 'section' => 'conversation_summary', 'type' => 'number',
                'sensitive' => false,
                'description' => 'Total characters of conversational memory sent to the answering model: the verbatim recent turns plus the injected summary. Clamped to '.ConversationSummaryConstants::MIN_WINDOW_CHARS.'–'.ConversationSummaryConstants::MAX_WINDOW_CHARS.'.',
                'default' => (string) ConversationSummaryConstants::TARGET_WINDOW_CHARS,
                'source' => 'database',
                'dbGroup' => ConversationSummaryConstants::CONFIG_GROUP,
                'dbKey' => ConversationSummaryConstants::KEY_TARGET_WINDOW_CHARS,
            ],
            'CONVERSATION_SUMMARY_RECENT_VERBATIM_CHARS' => [
                'tab' => 'routing', 'section' => 'conversation_summary', 'type' => 'number',
                'sensitive' => false,
                'description' => 'Share of the window reserved for the newest turns, which are replayed word for word. Everything older is condensed. Raise it to keep more literal context, lower it to summarize sooner. Always leaves at least 500 characters for the summary.',
                'default' => (string) ConversationSummaryConstants::RECENT_VERBATIM_CHARS,
                'source' => 'database',
                'dbGroup' => ConversationSummaryConstants::CONFIG_GROUP,
                'dbKey' => ConversationSummaryConstants::KEY_RECENT_VERBATIM_CHARS,
            ],
            'CONVERSATION_SUMMARY_MAX_CHARS' => [
                'tab' => 'routing', 'section' => 'conversation_summary', 'type' => 'number',
                'sensitive' => false,
                'description' => 'Hard cap on the injected summary itself. Capped at whatever the window has left after the verbatim turns.',
                'default' => (string) ConversationSummaryConstants::SUMMARY_MAX_CHARS,
                'source' => 'database',
                'dbGroup' => ConversationSummaryConstants::CONFIG_GROUP,
                'dbKey' => ConversationSummaryConstants::KEY_SUMMARY_MAX_CHARS,
            ],
            'CONVERSATION_SUMMARY_MAX_SOURCE_MESSAGES' => [
                'tab' => 'routing', 'section' => 'conversation_summary', 'type' => 'number',
                'sensitive' => false,
                'description' => 'Upper bound on how many older messages are fed to the Summary model in one call. Bounds the cost of summarizing a very long conversation; older messages beyond this are represented by the previous summary.',
                'default' => (string) ConversationSummaryConstants::MAX_SOURCE_MESSAGES,
                'source' => 'database',
                'dbGroup' => ConversationSummaryConstants::CONFIG_GROUP,
                'dbKey' => ConversationSummaryConstants::KEY_MAX_SOURCE_MESSAGES,
            ],
            'CONVERSATION_SUMMARY_TIERS' => [
                'tab' => 'routing', 'section' => 'conversation_summary', 'type' => 'number',
                'sensitive' => false,
                'description' => 'Number of recency tiers used for gradient compression: the oldest tier is condensed hardest, the tier next to the verbatim turns the least. 1–5.',
                'default' => (string) ConversationSummaryConstants::TIERS,
                'source' => 'database',
                'dbGroup' => ConversationSummaryConstants::CONFIG_GROUP,
                'dbKey' => ConversationSummaryConstants::KEY_TIERS,
            ],
            'CONVERSATION_SUMMARY_CACHE_TTL' => [
                'tab' => 'routing', 'section' => 'conversation_summary', 'type' => 'number',
                'sensitive' => false,
                'description' => 'Seconds the stored summary is kept before Redis expires it. The summary is refreshed asynchronously after each long-chat turn, so a follow-up turn never waits on the summarizer. Raise this on quiet installs; lower it only if you need settings changes to take effect faster.',
                'default' => (string) ConversationSummaryConstants::CACHE_TTL,
                'source' => 'database',
                'dbGroup' => ConversationSummaryConstants::CONFIG_GROUP,
                'dbKey' => ConversationSummaryConstants::KEY_CACHE_TTL,
            ],
            // === Routing — deep memory / message digests (database-backed) ===
            // BCONFIG group DIGEST (ownerId=0), the rows MessageDigestConfig
            // reads. No row means "use the MessageDigestConfig default", so
            // the defaults below must stay in sync with that class. The
            // per-user scan cursor lives in the same group under the user's
            // own id and is never exposed here.
            'DIGEST_ENABLED' => [
                'tab' => 'routing', 'section' => 'deep_memory', 'type' => 'boolean',
                'sensitive' => false,
                'description' => 'Deep memory master switch. A daily job condenses each user\'s KEY messages (documents, decisions, important facts) into one-line digests indexed in the vector DB; a chat prompt months later can then find and quote the original message ("the office rent letter from May"). Controls both the daily indexing job and the retrieval during chat. When OFF, older conversations are only reachable through extracted memories.',
                'default' => var_export(MessageDigestConfig::DEFAULT_ENABLED, true),
                'source' => 'database',
                'dbGroup' => MessageDigestConfig::CONFIG_GROUP,
                'dbKey' => MessageDigestConfig::KEY_ENABLED,
            ],
            'DIGEST_TOP_K' => [
                'tab' => 'routing', 'section' => 'deep_memory', 'type' => 'number',
                'sensitive' => false,
                'description' => 'Maximum digest hits considered per chat turn (after re-ranking by similarity and recency). 1–20.',
                'default' => (string) MessageDigestConfig::DEFAULT_TOP_K,
                'source' => 'database',
                'dbGroup' => MessageDigestConfig::CONFIG_GROUP,
                'dbKey' => MessageDigestConfig::KEY_TOP_K,
            ],
            'DIGEST_MIN_SCORE' => [
                'tab' => 'routing', 'section' => 'deep_memory', 'type' => 'number',
                'sensitive' => false,
                'description' => 'Vector similarity floor (0.0–1.0) a digest must clear to be considered at all. Raise it to cut noise, lower it to increase recall. Tuned with app:digest:eval.',
                'default' => (string) MessageDigestConfig::DEFAULT_MIN_SCORE,
                'source' => 'database',
                'dbGroup' => MessageDigestConfig::CONFIG_GROUP,
                'dbKey' => MessageDigestConfig::KEY_MIN_SCORE,
            ],
            'DIGEST_RECENCY_HALF_LIFE_DAYS' => [
                'tab' => 'routing', 'section' => 'deep_memory', 'type' => 'number',
                'sensitive' => false,
                'description' => 'Half-life of the recency decay in days: at this age a digest\'s effective score is halved. Deliberately slow, so an old but highly relevant message still beats a recent vague one.',
                'default' => (string) MessageDigestConfig::DEFAULT_RECENCY_HALF_LIFE_DAYS,
                'source' => 'database',
                'dbGroup' => MessageDigestConfig::CONFIG_GROUP,
                'dbKey' => MessageDigestConfig::KEY_RECENCY_HALF_LIFE_DAYS,
            ],
            'DIGEST_PULL_TOP_N' => [
                'tab' => 'routing', 'section' => 'deep_memory', 'type' => 'number',
                'sensitive' => false,
                'description' => 'How many of the best hits get their ORIGINAL message pulled verbatim into the prompt (so the model can quote amounts and dates, not just know the message exists). 0 disables pulling; the digest lines still appear.',
                'default' => (string) MessageDigestConfig::DEFAULT_PULL_TOP_N,
                'source' => 'database',
                'dbGroup' => MessageDigestConfig::CONFIG_GROUP,
                'dbKey' => MessageDigestConfig::KEY_PULL_TOP_N,
            ],
            'DIGEST_PULL_MIN_SCORE' => [
                'tab' => 'routing', 'section' => 'deep_memory', 'type' => 'number',
                'sensitive' => false,
                'description' => 'Raw similarity (0.0–1.0) a hit must clear before its source message is pulled verbatim. Higher than the search floor: pulling costs prompt space.',
                'default' => (string) MessageDigestConfig::DEFAULT_PULL_MIN_SCORE,
                'source' => 'database',
                'dbGroup' => MessageDigestConfig::CONFIG_GROUP,
                'dbKey' => MessageDigestConfig::KEY_PULL_MIN_SCORE,
            ],
            'DIGEST_BLOCK_MAX_CHARS' => [
                'tab' => 'routing', 'section' => 'deep_memory', 'type' => 'number',
                'sensitive' => false,
                'description' => 'Hard character cap for the whole "Older conversations" block injected into the system prompt (digest lines plus pulled excerpts).',
                'default' => (string) MessageDigestConfig::DEFAULT_BLOCK_MAX_CHARS,
                'source' => 'database',
                'dbGroup' => MessageDigestConfig::CONFIG_GROUP,
                'dbKey' => MessageDigestConfig::KEY_BLOCK_MAX_CHARS,
            ],
            'DIGEST_BATCH_SIZE' => [
                'tab' => 'routing', 'section' => 'deep_memory', 'type' => 'number',
                'sensitive' => false,
                'description' => 'Messages handed to the digest model per call during the daily indexing job. 5–100.',
                'default' => (string) MessageDigestConfig::DEFAULT_BATCH_SIZE,
                'source' => 'database',
                'dbGroup' => MessageDigestConfig::CONFIG_GROUP,
                'dbKey' => MessageDigestConfig::KEY_BATCH_SIZE,
            ],
            'DIGEST_MAX_BATCHES_PER_USER' => [
                'tab' => 'routing', 'section' => 'deep_memory', 'type' => 'number',
                'sensitive' => false,
                'description' => 'Cost cap: maximum digest-model calls per user per daily run. Unprocessed history is picked up by the next run (the per-user cursor never loses its place).',
                'default' => (string) MessageDigestConfig::DEFAULT_MAX_BATCHES_PER_USER,
                'source' => 'database',
                'dbGroup' => MessageDigestConfig::CONFIG_GROUP,
                'dbKey' => MessageDigestConfig::KEY_MAX_BATCHES_PER_USER,
            ],
            'DIGEST_QUIET_SECONDS' => [
                'tab' => 'routing', 'section' => 'deep_memory', 'type' => 'number',
                'sensitive' => false,
                'description' => 'Messages younger than this many seconds are left for a later run — the rolling summary covers the live conversation; the digest is the long-term index and must not race a chat still in progress.',
                'default' => (string) MessageDigestConfig::DEFAULT_QUIET_SECONDS,
                'source' => 'database',
                'dbGroup' => MessageDigestConfig::CONFIG_GROUP,
                'dbKey' => MessageDigestConfig::KEY_QUIET_SECONDS,
            ],
            'DIGEST_MAX_PER_USER' => [
                'tab' => 'routing', 'section' => 'deep_memory', 'type' => 'number',
                'sensitive' => false,
                'description' => 'Per-user cap on active digest entries (the deep-memory sibling of the 500-memory limit). On overflow the oldest entries are deactivated first. Minimum 100.',
                'default' => (string) MessageDigestConfig::DEFAULT_MAX_PER_USER,
                'source' => 'database',
                'dbGroup' => MessageDigestConfig::CONFIG_GROUP,
                'dbKey' => MessageDigestConfig::KEY_MAX_PER_USER,
            ],
            // Stored in BCONFIG group MEDIA / setting ASYNC_JOBS_ENABLED (the row
            // MediaJobConfig reads). Master switch for detaching media renders to
            // background jobs vs running them inline.
            'MEDIA_ASYNC_JOBS_ENABLED' => [
                'tab' => 'processing', 'section' => 'media', 'type' => 'boolean',
                'sensitive' => false,
                'description' => 'Run media generation (image, video, audio) as background jobs so the chat is never blocked — the assistant shows a live status banner and a completion toast. When OFF, renders run inline and the turn blocks until they finish. Requires the worker container. Global default; existing users keep their own setting until they opt in.',
                'default' => 'true',
                'source' => 'database',
                'dbGroup' => MediaJobConfig::CONFIG_GROUP,
                'dbKey' => MediaJobConfig::KEY_ASYNC_JOBS_ENABLED,
            ],
            // === Interface — in-chat usage taximeter (database-backed, no restart) ===
            // Master switch (BCONFIG group USAGE_TAXIMETER, ownerId=0) for the in-chat
            // consumption bar/ring + per-message token-cost badge. Default ON.
            'USAGE_TAXIMETER_ENABLED' => [
                'tab' => 'interface', 'section' => 'usage_display', 'type' => 'boolean',
                'sensitive' => false,
                'description' => 'Show the in-chat usage display: a consumption bar (desktop) / ring (mobile) with today\'s spend, a session statistics popover, and a token-cost badge on each AI reply. When OFF, none of these render and the app skips the extra daily-total queries. This does NOT affect the full Statistics page or the recorded usage data — it only toggles the in-chat display. On by default.',
                'default' => 'true',
                'source' => 'database',
                'dbGroup' => UsageTaximeterConfig::CONFIG_GROUP,
                'dbKey' => UsageTaximeterConfig::KEY_ENABLED,
            ],
            // === Branding (database-backed, no restart required) ===
            // Stored in BCONFIG group BRANDING (ownerId=0) — the rows BrandingService
            // reads and the public runtime-config endpoint surfaces to the frontend.
            'BRAND_NAME' => [
                'tab' => 'branding', 'section' => 'identity', 'type' => 'text',
                'sensitive' => false,
                'description' => 'Displayed brand/product name (document title, auth screens, attribution).',
                'default' => BrandingService::DEFAULT_NAME,
                'source' => 'database', 'dbGroup' => BrandingService::GROUP, 'dbKey' => BrandingService::KEY_NAME,
            ],
            'BRAND_TAGLINE' => [
                'tab' => 'branding', 'section' => 'identity', 'type' => 'text',
                'sensitive' => false,
                'description' => 'Optional short tagline/description shown beside the brand.',
                'default' => BrandingService::DEFAULT_TAGLINE,
                'source' => 'database', 'dbGroup' => BrandingService::GROUP, 'dbKey' => BrandingService::KEY_TAGLINE,
            ],
            'BRAND_HOMEPAGE_URL' => [
                'tab' => 'branding', 'section' => 'identity', 'type' => 'url',
                'sensitive' => false,
                'description' => 'Brand homepage link used on auth and footer surfaces.',
                'default' => BrandingService::DEFAULT_HOMEPAGE_URL,
                'source' => 'database', 'dbGroup' => BrandingService::GROUP, 'dbKey' => BrandingService::KEY_HOMEPAGE_URL,
            ],
            'BRAND_PRIVACY_URL' => [
                'tab' => 'branding', 'section' => 'legal', 'type' => 'url',
                'sensitive' => false,
                'description' => 'Privacy-policy link. Reachable in-app (Settings) and used in store metadata; store policy (Apple/Google) requires it. White-label brands point this at their own page.',
                'default' => BrandingService::DEFAULT_PRIVACY_URL,
                'source' => 'database', 'dbGroup' => BrandingService::GROUP, 'dbKey' => BrandingService::KEY_PRIVACY_URL,
            ],
            'BRAND_TERMS_URL' => [
                'tab' => 'branding', 'section' => 'legal', 'type' => 'url',
                'sensitive' => false,
                'description' => 'Terms-of-use link. Reachable in-app (Settings) and used in store metadata. White-label brands point this at their own page.',
                'default' => BrandingService::DEFAULT_TERMS_URL,
                'source' => 'database', 'dbGroup' => BrandingService::GROUP, 'dbKey' => BrandingService::KEY_TERMS_URL,
            ],
            'BRAND_PRIMARY_COLOR' => [
                'tab' => 'branding', 'section' => 'colors', 'type' => 'text',
                'sensitive' => false,
                'description' => 'Primary accent color as a hex value (e.g. #003fc7). Injected into the --brand CSS variables at runtime.',
                'default' => BrandingService::DEFAULT_PRIMARY_COLOR,
                'source' => 'database', 'dbGroup' => BrandingService::GROUP, 'dbKey' => BrandingService::KEY_PRIMARY_COLOR,
            ],
            'BRAND_SECONDARY_COLOR' => [
                'tab' => 'branding', 'section' => 'colors', 'type' => 'text',
                'sensitive' => false,
                'description' => 'Optional secondary color as a hex value. Leave empty to keep the default palette.',
                'default' => BrandingService::DEFAULT_SECONDARY_COLOR,
                'source' => 'database', 'dbGroup' => BrandingService::GROUP, 'dbKey' => BrandingService::KEY_SECONDARY_COLOR,
            ],
            'BRAND_ACCENT_COLOR' => [
                'tab' => 'branding', 'section' => 'colors', 'type' => 'text',
                'sensitive' => false,
                'description' => 'Optional accent color as a hex value. Leave empty to keep the default palette.',
                'default' => BrandingService::DEFAULT_ACCENT_COLOR,
                'source' => 'database', 'dbGroup' => BrandingService::GROUP, 'dbKey' => BrandingService::KEY_ACCENT_COLOR,
            ],
            'BRAND_PRIMARY_COLOR_DARK' => [
                'tab' => 'branding', 'section' => 'colors', 'type' => 'text',
                'sensitive' => false,
                'description' => 'Primary accent color used in DARK mode as a hex value. Leave empty to auto-derive a dark-friendly tint from the light primary color.',
                'default' => BrandingService::DEFAULT_PRIMARY_COLOR_DARK,
                'source' => 'database', 'dbGroup' => BrandingService::GROUP, 'dbKey' => BrandingService::KEY_PRIMARY_COLOR_DARK,
            ],
            'BRAND_SECONDARY_COLOR_DARK' => [
                'tab' => 'branding', 'section' => 'colors', 'type' => 'text',
                'sensitive' => false,
                'description' => 'Optional secondary color used in DARK mode as a hex value. Leave empty to reuse the light secondary color.',
                'default' => BrandingService::DEFAULT_SECONDARY_COLOR_DARK,
                'source' => 'database', 'dbGroup' => BrandingService::GROUP, 'dbKey' => BrandingService::KEY_SECONDARY_COLOR_DARK,
            ],
            'BRAND_ACCENT_COLOR_DARK' => [
                'tab' => 'branding', 'section' => 'colors', 'type' => 'text',
                'sensitive' => false,
                'description' => 'Optional accent color used in DARK mode as a hex value. Leave empty to reuse the light accent color.',
                'default' => BrandingService::DEFAULT_ACCENT_COLOR_DARK,
                'source' => 'database', 'dbGroup' => BrandingService::GROUP, 'dbKey' => BrandingService::KEY_ACCENT_COLOR_DARK,
            ],
            'BRAND_FONT_FAMILY' => [
                'tab' => 'branding', 'section' => 'fonts', 'type' => 'text',
                'sensitive' => false,
                'description' => 'Body font-family CSS stack (e.g. "Inter, sans-serif"). Leave empty to keep the default font.',
                'default' => BrandingService::DEFAULT_FONT_FAMILY,
                'source' => 'database', 'dbGroup' => BrandingService::GROUP, 'dbKey' => BrandingService::KEY_FONT_FAMILY,
            ],
            'BRAND_HEADING_FONT_FAMILY' => [
                'tab' => 'branding', 'section' => 'fonts', 'type' => 'text',
                'sensitive' => false,
                'description' => 'Optional heading font-family CSS stack. Leave empty to fall back to the body font/default.',
                'default' => BrandingService::DEFAULT_HEADING_FONT_FAMILY,
                'source' => 'database', 'dbGroup' => BrandingService::GROUP, 'dbKey' => BrandingService::KEY_HEADING_FONT_FAMILY,
            ],
            'BRAND_FONT_URL' => [
                'tab' => 'branding', 'section' => 'fonts', 'type' => 'url',
                'sensitive' => false,
                'description' => 'Optional web-font stylesheet URL (self-hosted or provider). The origin must be on the CSP allow-list; for the app, on the configured server\'s allowed origins. Leave empty for no external font.',
                'default' => BrandingService::DEFAULT_FONT_URL,
                'source' => 'database', 'dbGroup' => BrandingService::GROUP, 'dbKey' => BrandingService::KEY_FONT_URL,
            ],
            'BRAND_LOGO_URL' => [
                'tab' => 'branding', 'section' => 'logos', 'type' => 'url',
                'sensitive' => false,
                'description' => 'Light-mode logo URL. Leave empty to use the bundled Synaplan logo.',
                'default' => '',
                'source' => 'database', 'dbGroup' => BrandingService::GROUP, 'dbKey' => BrandingService::KEY_LOGO_URL,
            ],
            'BRAND_LOGO_DARK_URL' => [
                'tab' => 'branding', 'section' => 'logos', 'type' => 'url',
                'sensitive' => false,
                'description' => 'Dark-mode logo URL. Leave empty to use the bundled Synaplan logo.',
                'default' => '',
                'source' => 'database', 'dbGroup' => BrandingService::GROUP, 'dbKey' => BrandingService::KEY_LOGO_DARK_URL,
            ],
            'BRAND_ICON_URL' => [
                'tab' => 'branding', 'section' => 'logos', 'type' => 'url',
                'sensitive' => false,
                'description' => 'Brand icon/favicon URL. Leave empty to use the bundled asset (app icons are produced by Epic 6).',
                'default' => '',
                'source' => 'database', 'dbGroup' => BrandingService::GROUP, 'dbKey' => BrandingService::KEY_ICON_URL,
            ],
            'BRAND_LANDING_PAGE' => [
                'tab' => 'branding', 'section' => 'navigation', 'type' => 'text',
                'sensitive' => false,
                'description' => 'Logged-out landing: a route name (e.g. "login") or a free-form path starting with "/" (e.g. "/welcome"). Must be a public page. Leave empty to keep the default; unknown/non-public values fail safe to the default.',
                'default' => BrandingService::DEFAULT_LANDING_PAGE,
                'source' => 'database', 'dbGroup' => BrandingService::GROUP, 'dbKey' => BrandingService::KEY_LANDING_PAGE,
            ],
            'BRAND_DEFAULT_ROUTE' => [
                'tab' => 'branding', 'section' => 'navigation', 'type' => 'text',
                'sensitive' => false,
                'description' => 'Post-login default: a route name (e.g. "chat") or a free-form path starting with "/" (e.g. "/files"). Leave empty to keep the default; unknown values fail safe to the default.',
                'default' => BrandingService::DEFAULT_DEFAULT_ROUTE,
                'source' => 'database', 'dbGroup' => BrandingService::GROUP, 'dbKey' => BrandingService::KEY_DEFAULT_ROUTE,
            ],
            'BRAND_SHOW_POWERED_BY' => [
                'tab' => 'branding', 'section' => 'attribution', 'type' => 'boolean',
                'sensitive' => false,
                'description' => 'Show the "· powered by <label>" attribution across auth, logged-out, shared-chat and widget surfaces.',
                'default' => BrandingService::DEFAULT_SHOW_POWERED_BY,
                'source' => 'database', 'dbGroup' => BrandingService::GROUP, 'dbKey' => BrandingService::KEY_SHOW_POWERED_BY,
            ],
            'BRAND_POWERED_BY_LABEL' => [
                'tab' => 'branding', 'section' => 'attribution', 'type' => 'text',
                'sensitive' => false,
                'description' => 'Attribution label — the platform being credited (e.g. "Synaplan").',
                'default' => BrandingService::DEFAULT_POWERED_BY_LABEL,
                'source' => 'database', 'dbGroup' => BrandingService::GROUP, 'dbKey' => BrandingService::KEY_POWERED_BY_LABEL,
            ],
            'BRAND_POWERED_BY_URL' => [
                'tab' => 'branding', 'section' => 'attribution', 'type' => 'url',
                'sensitive' => false,
                'description' => 'Attribution link target for the "powered by" label.',
                'default' => BrandingService::DEFAULT_POWERED_BY_URL,
                'source' => 'database', 'dbGroup' => BrandingService::GROUP, 'dbKey' => BrandingService::KEY_POWERED_BY_URL,
            ],

            // === Mobile app forced-update gate (database-backed, no restart required) ===
            // Stored in BCONFIG group MOBILE (ownerId=0) — read by MobileVersionService
            // and surfaced in the public runtime config so the native app can block
            // too-old installs (Epic 8.2).
            'MIN_APP_VERSION' => [
                'tab' => 'mobile', 'section' => 'update', 'type' => 'text',
                'sensitive' => false,
                'description' => 'Minimum supported mobile app version (e.g. 4.0 or 4.1.2). Apps older than this are blocked with a "please update" screen. Leave empty to disable the gate.',
                'default' => MobileVersionService::DEFAULT_MIN_APP_VERSION,
                'source' => 'database', 'dbGroup' => MobileVersionService::GROUP, 'dbKey' => MobileVersionService::KEY_MIN_APP_VERSION,
            ],
            'IOS_APP_URL' => [
                'tab' => 'mobile', 'section' => 'update', 'type' => 'url',
                'sensitive' => false,
                'description' => 'App Store link shown on the forced-update screen (iOS).',
                'default' => MobileVersionService::DEFAULT_IOS_APP_URL,
                'source' => 'database', 'dbGroup' => MobileVersionService::GROUP, 'dbKey' => MobileVersionService::KEY_IOS_APP_URL,
            ],
            'ANDROID_APP_URL' => [
                'tab' => 'mobile', 'section' => 'update', 'type' => 'url',
                'sensitive' => false,
                'description' => 'Play Store link shown on the forced-update screen (Android).',
                'default' => MobileVersionService::DEFAULT_ANDROID_APP_URL,
                'source' => 'database', 'dbGroup' => MobileVersionService::GROUP, 'dbKey' => MobileVersionService::KEY_ANDROID_APP_URL,
            ],

            // === Guest Landing — Marketing News (database-backed, no restart) ===
            // Master switch + per-locale RSS feed URLs in BCONFIG group MARKETING_NEWS.
            // Seeded OFF; feed URLs have no effect until the master switch is ON.
            'MARKETING_NEWS_ENABLED' => [
                'tab' => 'guest_landing', 'section' => 'marketing_news', 'type' => 'boolean',
                'sensitive' => false,
                'description' => 'Show marketing news on the guest landing. When enabled, anonymous visitors see a news card grid on the empty chat screen (fetched from the feed URLs below). When disabled, the landing shows only the welcome text and no feed is fetched. Off by default on every installation.',
                'default' => 'false',
                'source' => 'database',
                'dbGroup' => MarketingNewsConfig::CONFIG_GROUP,
                'dbKey' => MarketingNewsConfig::KEY_ENABLED,
            ],
            'MARKETING_NEWS_FEED_URL_EN' => [
                'tab' => 'guest_landing', 'section' => 'marketing_news', 'type' => 'url',
                'sensitive' => false,
                'description' => 'RSS/WordPress feed URL for English-speaking visitors. Ignored while the master switch is off.',
                'default' => MarketingNewsConfig::DEFAULT_FEED_URL_EN,
                'source' => 'database',
                'dbGroup' => MarketingNewsConfig::CONFIG_GROUP,
                'dbKey' => MarketingNewsConfig::KEY_FEED_URL_EN,
            ],
            'MARKETING_NEWS_FEED_URL_DE' => [
                'tab' => 'guest_landing', 'section' => 'marketing_news', 'type' => 'url',
                'sensitive' => false,
                'description' => 'RSS/WordPress feed URL for German-speaking visitors. Ignored while the master switch is off.',
                'default' => MarketingNewsConfig::DEFAULT_FEED_URL_DE,
                'source' => 'database',
                'dbGroup' => MarketingNewsConfig::CONFIG_GROUP,
                'dbKey' => MarketingNewsConfig::KEY_FEED_URL_DE,
            ],
            'MARKETING_NEWS_FEED_URL_DEFAULT' => [
                'tab' => 'guest_landing', 'section' => 'marketing_news', 'type' => 'url',
                'sensitive' => false,
                'description' => 'Fallback RSS/WordPress feed URL for all other languages (e.g. ES, TR). Ignored while the master switch is off.',
                'default' => MarketingNewsConfig::DEFAULT_FEED_URL_EN,
                'source' => 'database',
                'dbGroup' => MarketingNewsConfig::CONFIG_GROUP,
                'dbKey' => MarketingNewsConfig::KEY_FEED_URL_DEFAULT,
            ],
            // === AI Services ===
            'OLLAMA_BASE_URL' => [
                'tab' => 'ai', 'section' => 'ollama', 'type' => 'url',
                'sensitive' => false, 'description' => 'Ollama server URL',
                'default' => 'http://ollama:11434',
            ],
            // Every field below whose env var is known to ProviderKeyCatalog is
            // stored encrypted in BCONFIG by ProviderKeyStore and applies without
            // a restart — hence 'source' => 'database'. getValues()/setValue()
            // route them through the store before the source check ever runs; the
            // marker only tells the UI to label them "saved live".
            'OPENAI_API_KEY' => [
                'tab' => 'ai', 'section' => 'cloud', 'type' => 'password',
                'sensitive' => true, 'description' => 'OpenAI API key',
                'default' => '', 'source' => 'database',
            ],
            'ANTHROPIC_API_KEY' => [
                'tab' => 'ai', 'section' => 'cloud', 'type' => 'password',
                'sensitive' => true, 'description' => 'Anthropic (Claude) API key',
                'default' => '', 'source' => 'database',
            ],
            'GROQ_API_KEY' => [
                'tab' => 'ai', 'section' => 'cloud', 'type' => 'password',
                'sensitive' => true, 'description' => 'Groq API key (free tier available)',
                'default' => '', 'source' => 'database',
            ],
            'GOOGLE_GEMINI_API_KEY' => [
                'tab' => 'ai', 'section' => 'cloud', 'type' => 'password',
                'sensitive' => true, 'description' => 'Google Gemini API key — also unlocks Imagen, Nano Banana, Veo and Gemini TTS',
                'default' => '', 'source' => 'database',
            ],
            'MISTRAL_API_KEY' => [
                'tab' => 'ai', 'section' => 'cloud', 'type' => 'password',
                'sensitive' => true, 'description' => 'Mistral API key — chat, vision and the Voxtral audio pair',
                'default' => '', 'source' => 'database',
            ],
            'XAI_API_KEY' => [
                'tab' => 'ai', 'section' => 'cloud', 'type' => 'password',
                'sensitive' => true,
                'description' => 'xAI (Grok) API key — chat, image understanding, Grok Imagine media, and Grok voice',
                'default' => '', 'source' => 'database',
            ],
            'TRUSTEDTOKENS_API_KEY' => [
                'tab' => 'ai', 'section' => 'cloud', 'type' => 'password',
                'sensitive' => true,
                'description' => 'TrustedTokens API key — sovereign inference on German GPUs (GLM, Qwen, GPT OSS)',
                'default' => '', 'source' => 'database',
            ],
            'HUGGINGFACE_API_KEY' => [
                'tab' => 'ai', 'section' => 'cloud', 'type' => 'password',
                'sensitive' => true, 'description' => 'HuggingFace API token — routes the Kimi models through HF Inference',
                'default' => '', 'source' => 'database',
            ],
            'GOOGLE_VERTEX_ACCESS_TOKEN' => [
                'tab' => 'ai', 'section' => 'cloud', 'type' => 'password',
                'sensitive' => true,
                'description' => 'Optional OAuth bearer for Vertex AI Imagen; leave empty to use Gemini API (Imagen 4) with the key above',
                'default' => '',
            ],
            'TRITON_SERVER_URL' => [
                'tab' => 'ai', 'section' => 'selfhosted', 'type' => 'url',
                'sensitive' => false, 'description' => 'NVIDIA Triton gRPC endpoint',
                'default' => '',
            ],
            'THEHIVE_API_KEY' => [
                'tab' => 'ai', 'section' => 'media', 'type' => 'password',
                'sensitive' => true, 'description' => 'TheHive API key — Flux Schnell and SDXL image generation',
                'default' => '',
            ],
            'HIGGSFIELD_API_KEY' => [
                'tab' => 'ai', 'section' => 'media', 'type' => 'password',
                'sensitive' => true, 'description' => 'Higgsfield API key — Soul/Reve images, DoP and Kling video. Both halves are required',
                'default' => '',
            ],
            'HIGGSFIELD_API_SECRET' => [
                'tab' => 'ai', 'section' => 'media', 'type' => 'password',
                'sensitive' => true, 'description' => 'Higgsfield API secret — the key alone will not authenticate',
                'default' => '',
            ],
            'CLOUDFLARE_ACCOUNT_ID' => [
                'tab' => 'ai', 'section' => 'embeddings', 'type' => 'text',
                'sensitive' => false, 'description' => 'Cloudflare account ID for Workers AI embeddings (bge-m3)',
                'default' => '',
            ],
            'CLOUDFLARE_API_TOKEN' => [
                'tab' => 'ai', 'section' => 'embeddings', 'type' => 'password',
                'sensitive' => true, 'description' => 'Cloudflare API token with Workers AI access',
                'default' => '',
            ],
            'EMBEDDING_FALLBACK_PROVIDER' => [
                'tab' => 'ai', 'section' => 'embeddings', 'type' => 'text',
                'sensitive' => false,
                'description' => 'Provider to try when the primary embedding fails (e.g. "cloudflare"); empty disables fallback',
                'default' => '',
            ],
            'SYNAPLAN_TTS_URL' => [
                'tab' => 'ai', 'section' => 'tts', 'type' => 'url',
                'sensitive' => false, 'description' => 'Synaplan TTS service URL (self-hosted, Piper-based)',
                'default' => $this->defaultTtsUrl,
            ],
            'ELEVENLABS_API_KEY' => [
                'tab' => 'ai', 'section' => 'tts', 'type' => 'password',
                'sensitive' => true, 'description' => 'ElevenLabs TTS API key',
                'default' => '',
            ],

            // === Email ===
            'MAILER_DSN' => [
                'tab' => 'email', 'section' => 'mailer', 'type' => 'text',
                'sensitive' => true, 'description' => 'SMTP connection string',
                'default' => 'null://null',
            ],
            'APP_SENDER_EMAIL' => [
                'tab' => 'email', 'section' => 'mailer', 'type' => 'email',
                'sensitive' => false, 'description' => 'Sender email address',
                'default' => '',
            ],
            'APP_SENDER_NAME' => [
                'tab' => 'email', 'section' => 'mailer', 'type' => 'text',
                'sensitive' => false, 'description' => 'Sender name',
                'default' => 'Synaplan',
            ],
            'APP_ADMIN_EMAIL' => [
                'tab' => 'email', 'section' => 'mailer', 'type' => 'email',
                'sensitive' => false, 'description' => 'Operator inbox for incident + content-moderation alerts',
                'default' => '',
            ],

            // === Authentication ===
            'RECAPTCHA_ENABLED' => [
                'tab' => 'auth', 'section' => 'recaptcha', 'type' => 'boolean',
                'sensitive' => false, 'description' => 'Enable reCAPTCHA',
                'default' => 'false',
            ],
            'RECAPTCHA_SITE_KEY' => [
                'tab' => 'auth', 'section' => 'recaptcha', 'type' => 'text',
                'sensitive' => false, 'description' => 'reCAPTCHA site key',
                'default' => '',
            ],
            'RECAPTCHA_SECRET_KEY' => [
                'tab' => 'auth', 'section' => 'recaptcha', 'type' => 'password',
                'sensitive' => true, 'description' => 'reCAPTCHA secret key',
                'default' => '',
            ],
            'RECAPTCHA_MIN_SCORE' => [
                'tab' => 'auth', 'section' => 'recaptcha', 'type' => 'number',
                'sensitive' => false, 'description' => 'Minimum score (0.0-1.0)',
                'default' => '0.5',
            ],
            'GOOGLE_CLIENT_ID' => [
                'tab' => 'auth', 'section' => 'google', 'type' => 'text',
                'sensitive' => false, 'description' => 'Google OAuth client ID',
                'default' => '',
            ],
            'GOOGLE_CLIENT_SECRET' => [
                'tab' => 'auth', 'section' => 'google', 'type' => 'password',
                'sensitive' => true, 'description' => 'Google OAuth client secret',
                'default' => '',
            ],
            'GOOGLE_CLOUD_PROJECT_ID' => [
                'tab' => 'auth', 'section' => 'google', 'type' => 'text',
                'sensitive' => false, 'description' => 'Google Cloud project ID',
                'default' => '',
            ],
            'GITHUB_CLIENT_ID' => [
                'tab' => 'auth', 'section' => 'github', 'type' => 'text',
                'sensitive' => false, 'description' => 'GitHub OAuth client ID',
                'default' => '',
            ],
            'GITHUB_CLIENT_SECRET' => [
                'tab' => 'auth', 'section' => 'github', 'type' => 'password',
                'sensitive' => true, 'description' => 'GitHub OAuth client secret',
                'default' => '',
            ],
            'APPLE_CLIENT_ID' => [
                'tab' => 'auth', 'section' => 'apple', 'type' => 'text',
                'sensitive' => false, 'description' => 'Apple Services ID (web OAuth client ID / id_token audience)',
                'default' => '',
            ],
            'APPLE_TEAM_ID' => [
                'tab' => 'auth', 'section' => 'apple', 'type' => 'text',
                'sensitive' => false, 'description' => 'Apple Developer Team ID (10 chars)',
                'default' => '',
            ],
            'APPLE_KEY_ID' => [
                'tab' => 'auth', 'section' => 'apple', 'type' => 'text',
                'sensitive' => false, 'description' => 'Key ID of the Sign-in-with-Apple private key (.p8)',
                'default' => '',
            ],
            'APPLE_PRIVATE_KEY' => [
                'tab' => 'auth', 'section' => 'apple', 'type' => 'password',
                'sensitive' => true, 'description' => 'Contents of the AuthKey_XXXX.p8 (PKCS#8 PEM)',
                'default' => '',
            ],
            'APPLE_APP_BUNDLE_ID' => [
                'tab' => 'auth', 'section' => 'apple', 'type' => 'text',
                'sensitive' => false, 'description' => 'Native iOS bundle id (identity-token audience)',
                'default' => 'com.synaplan.app',
            ],
            'OIDC_DISCOVERY_URL' => [
                'tab' => 'auth', 'section' => 'oidc', 'type' => 'url',
                'sensitive' => false, 'description' => 'OIDC discovery URL',
                'default' => '',
            ],
            'OIDC_CLIENT_ID' => [
                'tab' => 'auth', 'section' => 'oidc', 'type' => 'text',
                'sensitive' => false, 'description' => 'OIDC client ID',
                'default' => '',
            ],
            'OIDC_CLIENT_SECRET' => [
                'tab' => 'auth', 'section' => 'oidc', 'type' => 'password',
                'sensitive' => true, 'description' => 'OIDC client secret',
                'default' => '',
            ],

            // === Inbound Channels ===
            'WHATSAPP_ENABLED' => [
                'tab' => 'channels', 'section' => 'whatsapp', 'type' => 'boolean',
                'sensitive' => false, 'description' => 'Enable WhatsApp integration',
                'default' => 'false',
            ],
            'WHATSAPP_ACCESS_TOKEN' => [
                'tab' => 'channels', 'section' => 'whatsapp', 'type' => 'password',
                'sensitive' => true, 'description' => 'WhatsApp access token',
                'default' => '',
            ],
            'WHATSAPP_WEBHOOK_VERIFY_TOKEN' => [
                'tab' => 'channels', 'section' => 'whatsapp', 'type' => 'password',
                'sensitive' => true, 'description' => 'Webhook verification token',
                'default' => '',
            ],
            'GMAIL_USERNAME' => [
                'tab' => 'channels', 'section' => 'gmail', 'type' => 'email',
                'sensitive' => false, 'description' => 'Gmail address for Smart Mail',
                'default' => '',
            ],
            'GMAIL_PASSWORD' => [
                'tab' => 'channels', 'section' => 'gmail', 'type' => 'password',
                'sensitive' => true, 'description' => 'Gmail App Password',
                'default' => '',
            ],

            // === Document Processing ===
            'TIKA_BASE_URL' => [
                'tab' => 'processing', 'section' => 'tika', 'type' => 'url',
                'sensitive' => false, 'description' => 'Apache Tika URL',
                'default' => 'http://tika:9998',
            ],
            'TIKA_TIMEOUT_MS' => [
                'tab' => 'processing', 'section' => 'tika', 'type' => 'number',
                'sensitive' => false, 'description' => 'Request timeout (ms)',
                'default' => '30000',
            ],
            'TIKA_RETRIES' => [
                'tab' => 'processing', 'section' => 'tika', 'type' => 'number',
                'sensitive' => false, 'description' => 'Max retries',
                'default' => '2',
            ],
            'TIKA_HTTP_USER' => [
                'tab' => 'processing', 'section' => 'tika', 'type' => 'text',
                'sensitive' => false, 'description' => 'HTTP auth username',
                'default' => '',
            ],
            'TIKA_HTTP_PASS' => [
                'tab' => 'processing', 'section' => 'tika', 'type' => 'password',
                'sensitive' => true, 'description' => 'HTTP auth password',
                'default' => '',
            ],
            'RASTERIZE_DPI' => [
                'tab' => 'processing', 'section' => 'rasterize', 'type' => 'number',
                'sensitive' => false, 'description' => 'PDF rasterization DPI',
                'default' => '150',
            ],
            'RASTERIZE_PAGE_CAP' => [
                'tab' => 'processing', 'section' => 'rasterize', 'type' => 'number',
                'sensitive' => false, 'description' => 'Max pages to rasterize',
                'default' => '10',
            ],
            'RASTERIZE_TIMEOUT_MS' => [
                'tab' => 'processing', 'section' => 'rasterize', 'type' => 'number',
                'sensitive' => false, 'description' => 'Rasterization timeout (ms)',
                'default' => '30000',
            ],
            'WHISPER_ENABLED' => [
                'tab' => 'processing', 'section' => 'whisper', 'type' => 'boolean',
                'sensitive' => false, 'description' => 'Enable audio transcription',
                'default' => 'true',
            ],
            'WHISPER_DEFAULT_MODEL' => [
                'tab' => 'processing', 'section' => 'whisper', 'type' => 'select',
                'sensitive' => false, 'description' => 'Default Whisper model',
                'default' => 'base',
                'options' => ['tiny', 'base', 'small', 'medium', 'large'],
            ],
            'BRAVE_SEARCH_ENABLED' => [
                'tab' => 'processing', 'section' => 'brave', 'type' => 'boolean',
                'sensitive' => false, 'description' => 'Enable web search',
                'default' => 'false',
            ],
            'BRAVE_SEARCH_API_KEY' => [
                'tab' => 'processing', 'section' => 'brave', 'type' => 'password',
                'sensitive' => true, 'description' => 'Brave Search API key',
                'default' => '',
            ],
            'BRAVE_SEARCH_COUNT' => [
                'tab' => 'processing', 'section' => 'brave', 'type' => 'number',
                'sensitive' => false, 'description' => 'Results per search',
                'default' => '10',
            ],

            // === Vector Database ===
            'QDRANT_URL' => [
                'tab' => 'vectordb', 'section' => 'qdrant', 'type' => 'url',
                'sensitive' => false, 'description' => 'Qdrant REST API URL',
                'default' => 'http://qdrant:6333',
            ],
            // === Search Thresholds (database-backed, no restart required) ===
            'MIN_CHAT_FEEDBACK_SCORE' => [
                'tab' => 'vectordb', 'section' => 'qdrant_search', 'type' => 'number',
                'sensitive' => false, 'description' => 'Min score for feedback in chat context (0.0–1.0)',
                'default' => (string) FeedbackConstants::MIN_CHAT_FEEDBACK_SCORE,
                'source' => 'database',
            ],
            'MIN_CHAT_MEMORY_SCORE' => [
                'tab' => 'vectordb', 'section' => 'qdrant_search', 'type' => 'number',
                'sensitive' => false, 'description' => 'Min score for memories in chat context (0.0–1.0)',
                'default' => (string) FeedbackConstants::MIN_CHAT_MEMORY_SCORE,
                'source' => 'database',
            ],
            'MIN_CONTRADICTION_SCORE' => [
                'tab' => 'vectordb', 'section' => 'qdrant_search', 'type' => 'number',
                'sensitive' => false, 'description' => 'Min score for contradiction detection (0.0–1.0)',
                'default' => (string) FeedbackConstants::MIN_CONTRADICTION_SCORE,
                'source' => 'database',
            ],
            'MIN_RESEARCH_SCORE' => [
                'tab' => 'vectordb', 'section' => 'qdrant_search', 'type' => 'number',
                'sensitive' => false, 'description' => 'Min score for KB/document research (0.0–1.0)',
                'default' => (string) FeedbackConstants::MIN_RESEARCH_SCORE,
                'source' => 'database',
            ],
            'MIN_MEMORY_RESEARCH_SCORE' => [
                'tab' => 'vectordb', 'section' => 'qdrant_search', 'type' => 'number',
                'sensitive' => false, 'description' => 'Min score for memory research (0.0–1.0)',
                'default' => (string) FeedbackConstants::MIN_MEMORY_RESEARCH_SCORE,
                'source' => 'database',
            ],
            'MIN_EXTRACTION_SCORE' => [
                'tab' => 'vectordb', 'section' => 'qdrant_search', 'type' => 'number',
                'sensitive' => false, 'description' => 'Min score for memory extraction context (0.0–1.0)',
                'default' => (string) FeedbackConstants::MIN_EXTRACTION_SCORE,
                'source' => 'database',
            ],
            'LIMIT_PER_NAMESPACE' => [
                'tab' => 'vectordb', 'section' => 'qdrant_search', 'type' => 'number',
                'sensitive' => false, 'description' => 'Max results per Qdrant namespace',
                'default' => (string) FeedbackConstants::LIMIT_PER_NAMESPACE,
                'source' => 'database',
            ],
            'MAX_CHAT_MEMORIES' => [
                'tab' => 'vectordb', 'section' => 'qdrant_search', 'type' => 'number',
                'sensitive' => false, 'description' => 'Max memories loaded into chat context',
                'default' => '5',
                'source' => 'database',
            ],
        ];
    }
}
