<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Service\Admin\SystemConfig\EnvSettings;
use App\Service\Admin\SystemConfig\FieldDefinition;
use App\Service\Admin\SystemConfig\SystemConfigSchema;
use App\Service\Admin\SystemConfig\SystemConfigValues;
use App\Service\Admin\SystemConfig\SystemConfigWriter;
use App\Service\Branding\BrandingService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Admin → System settings: the schema the UI renders (13 tabs, see
 * SystemConfig\Tab), the values it shows with every secret masked, one
 * setting written at a time, the style reset, the connection checks of the
 * local services and the .env backups.
 */
final readonly class SystemConfigService
{
    public function __construct(
        private SystemConfigSchema $schema,
        private SystemConfigValues $values,
        private SystemConfigWriter $writer,
        private EnvSettings $env,
        private LoggerInterface $logger,
        #[Autowire('%synaplan_tts_url_default%')]
        private string $defaultTtsUrl,
    ) {
    }

    /**
     * Get the configuration schema with field definitions.
     *
     * @return array{tabs: array<string, array{label: string, sections: array<string, array{label: string, fields: array<string>}>}>, fields: array<string, array{tab: string, section: string, type: string, sensitive: bool, description: string, default: string, source?: string, options?: array<string>, dbGroup?: string, dbKey?: string, encrypted?: bool, placeholder?: string, managedBy?: string}>}
     */
    public function getSchema(): array
    {
        return [
            'tabs' => $this->schema->tabs(),
            'fields' => array_map(static fn (FieldDefinition $field): array => $field->toArray(), $this->schema->fields()),
        ];
    }

    /**
     * Current values with every secret masked.
     *
     * @param list<string>|null $onlyKeys limit the lookup to these keys (null = every field)
     *
     * @return array<string, array{value: string, isSet: bool, isMasked: bool, effectiveForMe?: string, hasPersonalOverride?: bool, envOverride?: bool, effectiveValue?: string, locked?: bool, keySource?: string}>
     */
    public function getValues(?int $actingUserId = null, ?array $onlyKeys = null): array
    {
        return $this->values->read($actingUserId, $onlyKeys);
    }

    /**
     * Update a single configuration value.
     *
     * @return array{success: bool, requiresRestart: bool, message?: string, managedBy?: string}
     */
    public function setValue(string $key, string $value, ?int $actingUserId = null): array
    {
        return $this->writer->write($key, $value, $actingUserId);
    }

    /**
     * Restore the default style: clears every brand color and font override so
     * readers fall back to the field defaults. Name, logos, legal links,
     * navigation and attribution are brand identity, not style, and are kept.
     *
     * Each key clears independently through setValue(), so one failing write
     * cannot block the rest; callers report what did and did not reset.
     *
     * @return array{success: bool, reset: list<string>, failed: list<string>, requiresRestart: bool}
     */
    public function resetBrandingStyle(?int $actingUserId = null): array
    {
        $reset = [];
        $failed = [];

        foreach (BrandingService::STYLE_RESET_KEYS as $key) {
            $result = $this->setValue($key, '', $actingUserId);
            if ($result['success']) {
                $reset[] = $key;
            } else {
                $failed[] = $key;
                $this->logger->error('Failed to reset branding style key', [
                    'key' => $key,
                    'error' => $result['message'],
                ]);
            }
        }

        return [
            'success' => [] === $failed,
            'reset' => $reset,
            'failed' => $failed,
            'requiresRestart' => false,
        ];
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
            'docling' => $this->testDocling(),
            'qdrant' => $this->testQdrant(),
            'mailer' => $this->testMailer(),
            'piper' => $this->testPiperTts(),
            default => ['success' => false, 'message' => 'Unknown service: '.$service],
        };
    }

    /**
     * @return list<array{id: string, timestamp: string, size: int}>
     */
    public function getBackups(): array
    {
        return $this->env->backups();
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function restoreBackup(string $backupId): array
    {
        return $this->env->restore($backupId);
    }

    /**
     * @return array{success: bool, message: string, details?: array<string, mixed>}
     */
    private function testOllama(): array
    {
        $url = $this->env->value('OLLAMA_BASE_URL');
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
        $url = $this->env->value('TIKA_BASE_URL');
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
            $httpUser = $this->env->value('TIKA_HTTP_USER');
            if (!empty($httpUser)) {
                curl_setopt($ch, CURLOPT_USERPWD, $httpUser.':'.($this->env->value('TIKA_HTTP_PASS') ?? ''));
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
    private function testDocling(): array
    {
        $url = $this->env->value('DOCLING_BASE_URL');
        if (!$url || 'disabled' === strtolower($url)) {
            return ['success' => false, 'message' => 'DOCLING_BASE_URL not configured'];
        }

        try {
            $ch = curl_init(rtrim($url, '/').'/health');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (200 === $httpCode) {
                return ['success' => true, 'message' => 'Connected to Docling'];
            }

            return ['success' => false, 'message' => 'Docling returned HTTP '.$httpCode];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Connection failed: '.$e->getMessage()];
        }
    }

    /**
     * @return array{success: bool, message: string, details?: array<string, mixed>}
     */
    private function testQdrant(): array
    {
        $url = $this->env->value('QDRANT_URL');
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
        $url = $this->env->value('SYNAPLAN_TTS_URL');
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
        $dsn = $this->env->value('MAILER_DSN');
        if (!$dsn || 'null://null' === $dsn) {
            return ['success' => false, 'message' => 'Mailer not configured (using null transport)'];
        }

        // Basic DSN validation
        if (!str_starts_with($dsn, 'smtp://') && !str_starts_with($dsn, 'sendmail://')) {
            return ['success' => true, 'message' => 'Mailer DSN configured (cannot test without sending)'];
        }

        return ['success' => true, 'message' => 'Mailer DSN configured'];
    }
}
