<?php

declare(strict_types=1);

namespace App\Service\Admin;

use Psr\Log\LoggerInterface;

/**
 * System Configuration Service.
 *
 * Manages reading and writing of .env configuration with security masking.
 * SECURITY: Sensitive fields are NEVER returned in plain text via API.
 */
final class SystemConfigService
{
    private const MASK = '••••••••';

    /** @var array<string, array{tab: string, section: string, type: string, sensitive: bool, description: string, default: string, options?: array<string>}> */
    private array $schema;

    public function __construct(
        private readonly string $projectDir,
        private readonly LoggerInterface $logger,
    ) {
        $this->schema = $this->buildSchema();
    }

    /**
     * Get the configuration schema with field definitions.
     *
     * @return array{tabs: array<string, array{label: string, sections: array<string, array{label: string, fields: array<string>}>}>, fields: array<string, array{tab: string, section: string, type: string, sensitive: bool, description: string, default: string, options?: array<string>}>}
     */
    public function getSchema(): array
    {
        $tabs = [
            'ai' => [
                'label' => 'AI Services',
                'sections' => [
                    'ollama' => ['label' => 'Local AI (Ollama)', 'fields' => ['OLLAMA_BASE_URL']],
                    'cloud' => ['label' => 'Cloud AI Providers', 'fields' => ['OPENAI_API_KEY', 'ANTHROPIC_API_KEY', 'GROQ_API_KEY', 'GOOGLE_GEMINI_API_KEY']],
                    'selfhosted' => ['label' => 'Self-Hosted AI', 'fields' => ['TRITON_SERVER_URL']],
                    'tts' => ['label' => 'Text-to-Speech', 'fields' => ['ELEVENLABS_API_KEY']],
                ],
            ],
            'email' => [
                'label' => 'Email',
                'sections' => [
                    'mailer' => ['label' => 'Primary Mailer', 'fields' => ['MAILER_DSN', 'APP_SENDER_EMAIL', 'APP_SENDER_NAME']],
                ],
            ],
            'auth' => [
                'label' => 'Authentication',
                'sections' => [
                    'recaptcha' => ['label' => 'reCAPTCHA v3', 'fields' => ['RECAPTCHA_ENABLED', 'RECAPTCHA_SITE_KEY', 'RECAPTCHA_SECRET_KEY', 'RECAPTCHA_MIN_SCORE']],
                    'google' => ['label' => 'Google OAuth 2.0', 'fields' => ['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'GOOGLE_CLOUD_PROJECT_ID']],
                    'github' => ['label' => 'GitHub OAuth 2.0', 'fields' => ['GITHUB_CLIENT_ID', 'GITHUB_CLIENT_SECRET']],
                    'oidc' => ['label' => 'OIDC (Enterprise SSO)', 'fields' => ['OIDC_DISCOVERY_URL', 'OIDC_CLIENT_ID', 'OIDC_CLIENT_SECRET']],
                ],
            ],
            'channels' => [
                'label' => 'Inbound Channels',
                'sections' => [
                    'whatsapp' => ['label' => 'WhatsApp Business API', 'fields' => ['WHATSAPP_ENABLED', 'WHATSAPP_ACCESS_TOKEN', 'WHATSAPP_WEBHOOK_VERIFY_TOKEN']],
                    'gmail' => ['label' => 'Smart Mail (Gmail IMAP)', 'fields' => ['GMAIL_USERNAME', 'GMAIL_PASSWORD']],
                ],
            ],
            'processing' => [
                'label' => 'Processing',
                'sections' => [
                    'tika' => ['label' => 'Apache Tika', 'fields' => ['TIKA_BASE_URL', 'TIKA_TIMEOUT_MS', 'TIKA_RETRIES', 'TIKA_HTTP_USER', 'TIKA_HTTP_PASS']],
                    'rasterize' => ['label' => 'PDF Rasterizer', 'fields' => ['RASTERIZE_DPI', 'RASTERIZE_PAGE_CAP', 'RASTERIZE_TIMEOUT_MS']],
                    'whisper' => ['label' => 'Whisper (Audio)', 'fields' => ['WHISPER_ENABLED', 'WHISPER_DEFAULT_MODEL']],
                    'brave' => ['label' => 'Web Search (Brave)', 'fields' => ['BRAVE_SEARCH_ENABLED', 'BRAVE_SEARCH_API_KEY', 'BRAVE_SEARCH_COUNT']],
                ],
            ],
            'vectordb' => [
                'label' => 'Vector DB',
                'sections' => [
                    'qdrant' => ['label' => 'Qdrant Service', 'fields' => ['QDRANT_SERVICE_URL', 'QDRANT_SERVICE_API_KEY']],
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
     * @return array<string, array{value: string, isSet: bool, isMasked: bool}>
     */
    public function getValues(): array
    {
        $values = [];

        foreach ($this->schema as $key => $field) {
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

        return $values;
    }

    /**
     * Update a single configuration value.
     *
     * @return array{success: bool, requiresRestart: bool, message?: string}
     */
    public function setValue(string $key, string $value): array
    {
        if (!isset($this->schema[$key])) {
            return ['success' => false, 'requiresRestart' => false, 'message' => 'Unknown configuration key'];
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
        $url = $this->getEnvValue('QDRANT_SERVICE_URL');
        if (!$url) {
            return ['success' => false, 'message' => 'QDRANT_SERVICE_URL not configured'];
        }

        try {
            $ch = curl_init($url.'/health');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);
            $apiKey = $this->getEnvValue('QDRANT_SERVICE_API_KEY');
            if ($apiKey) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-API-Key: '.$apiKey]);
            }
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (200 === $httpCode) {
                return ['success' => true, 'message' => 'Connected to Qdrant service'];
            }

            return ['success' => false, 'message' => 'Qdrant returned HTTP '.$httpCode];
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
     * @return array<string, array{tab: string, section: string, type: string, sensitive: bool, description: string, default: string, options?: array<string>}>
     */
    private function buildSchema(): array
    {
        return [
            // === AI Services ===
            'OLLAMA_BASE_URL' => [
                'tab' => 'ai', 'section' => 'ollama', 'type' => 'url',
                'sensitive' => false, 'description' => 'Ollama server URL',
                'default' => 'http://ollama:11434',
            ],
            'OPENAI_API_KEY' => [
                'tab' => 'ai', 'section' => 'cloud', 'type' => 'password',
                'sensitive' => true, 'description' => 'OpenAI API key',
                'default' => '',
            ],
            'ANTHROPIC_API_KEY' => [
                'tab' => 'ai', 'section' => 'cloud', 'type' => 'password',
                'sensitive' => true, 'description' => 'Anthropic (Claude) API key',
                'default' => '',
            ],
            'GROQ_API_KEY' => [
                'tab' => 'ai', 'section' => 'cloud', 'type' => 'password',
                'sensitive' => true, 'description' => 'Groq API key',
                'default' => '',
            ],
            'GOOGLE_GEMINI_API_KEY' => [
                'tab' => 'ai', 'section' => 'cloud', 'type' => 'password',
                'sensitive' => true, 'description' => 'Google Gemini API key',
                'default' => '',
            ],
            'TRITON_SERVER_URL' => [
                'tab' => 'ai', 'section' => 'selfhosted', 'type' => 'url',
                'sensitive' => false, 'description' => 'NVIDIA Triton gRPC endpoint',
                'default' => '',
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
            'QDRANT_SERVICE_URL' => [
                'tab' => 'vectordb', 'section' => 'qdrant', 'type' => 'url',
                'sensitive' => false, 'description' => 'Qdrant service URL',
                'default' => 'http://qdrant-service:8090',
            ],
            'QDRANT_SERVICE_API_KEY' => [
                'tab' => 'vectordb', 'section' => 'qdrant', 'type' => 'password',
                'sensitive' => true, 'description' => 'Qdrant service API key',
                'default' => '',
            ],
        ];
    }
}
