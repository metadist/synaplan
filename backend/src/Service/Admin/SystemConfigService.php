<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\AI\Credential\ProviderKeyCatalog;
use App\AI\Credential\ProviderKeyStore;
use App\AI\Credential\SecretValueGuard;
use App\Bundle\BundleConfig;
use App\Repository\ConfigRepository;
use App\Service\Admin\SystemConfig\FieldDefinition;
use App\Service\Admin\SystemConfig\SystemConfigSchema;
use App\Service\Agent\AgentConfig;
use App\Service\Auth\RegistrationConfig;
use App\Service\Branding\BrandingService;
use App\Service\Chat\ChatArtifactsConfig;
use App\Service\Chat\GuestChatConfig;
use App\Service\Compute\ComputeClient;
use App\Service\Compute\ComputeConfig;
use App\Service\Config\LayeredConfigResolver;
use App\Service\Digest\MessageDigestConfig;
use App\Service\Feature\FeatureFlagEnv;
use App\Service\Iam\IamConfig;
use App\Service\Mcp\McpClientConfig;
use App\Service\Media\MediaJobConfig;
use App\Service\Message\ConversationSummaryConstants;
use App\Service\Multitask\MultitaskRoutingConfig;
use App\Service\SavedTask\SavedTaskConfig;
use App\Service\SavedTask\WorkflowsConfig;
use App\Service\Security\EncryptionService;
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
    private const DB_OWNER_ID = 0;

    public function __construct(
        private readonly string $projectDir,
        private readonly LoggerInterface $logger,
        private readonly ConfigRepository $configRepository,
        private readonly string $defaultTtsUrl,
        private readonly ProviderKeyStore $providerKeyStore,
        private readonly EncryptionService $encryption,
        private readonly RegistrationConfig $registrationConfig,
        private readonly GuestChatConfig $guestChatConfig,
        private readonly SystemConfigSchema $schema,
        private readonly FeatureFlagEnv $featureFlagEnv = new FeatureFlagEnv(),
        private readonly ?LayeredConfigResolver $layeredConfigResolver = null,
        private readonly ?ComputeClient $computeClient = null,
        private readonly ?ChatArtifactsConfig $chatArtifactsConfig = null,
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
     * Get current configuration values with sensitive fields masked.
     *
     * @param list<string>|null $onlyKeys limit the lookup to these keys (null = every field)
     *
     * @return array<string, array{value: string, isSet: bool, isMasked: bool, effectiveForMe?: string, hasPersonalOverride?: bool, envOverride?: bool, effectiveValue?: string, locked?: bool, keySource?: string}>
     */
    public function getValues(?int $actingUserId = null, ?array $onlyKeys = null): array
    {
        $values = [];
        $wanted = null === $onlyKeys ? null : array_flip($onlyKeys);

        foreach ($this->schema->fields() as $key => $field) {
            if (null !== $wanted && !isset($wanted[$key])) {
                continue;
            }
            // Instance provider keys live in the encrypted ProviderKeyStore
            // (BCONFIG), not in .env — report their status from there so this
            // legacy surface and Providers & keys agree. The secret half of a
            // key + secret pair reports its own presence.
            $storeProvider = ProviderKeyCatalog::providerForEnvVar($key);
            if (null !== $storeProvider) {
                $status = $this->providerKeyStore->getStatus($storeProvider);
                // Each half of a pair reports its own presence so a missing
                // secret is visible. An env-imported DB row is still "from
                // the environment / Helm", not a UI override.
                $isSet = ProviderKeyCatalog::isSecretEnvVar($key)
                    ? $status['hasSecret']
                    : '' !== $status['maskedKey'];
                $keySource = 'db' === $status['source'] && ProviderKeyStore::ORIGIN_ENV === $status['origin']
                    ? 'env'
                    : $status['source'];
                $values[$key] = [
                    'value' => $isSet ? self::MASK : $field->default,
                    'isSet' => $isSet,
                    'isMasked' => $isSet,
                    'keySource' => $keySource,
                ];
                continue;
            }

            if ($field->isStoredInDatabase()) {
                $dbGroup = $field->group();
                $dbKey = $field->setting($key);
                $rawValue = $this->configRepository->getValue(self::DB_OWNER_ID, $dbGroup, $dbKey);
                $isSet = null !== $rawValue && '' !== $rawValue;
                $locked = $this->configRepository
                    ->findByOwnerGroupAndSetting(self::DB_OWNER_ID, $dbGroup, $dbKey)
                    ?->isBlocked() ?? false;

                // A database-backed secret (e.g. an OAuth client secret) is
                // stored encrypted and must be masked here for the same reason
                // an env secret is: this response reaches the admin UI.
                if ($field->sensitive) {
                    $values[$key] = [
                        'value' => $isSet ? self::MASK : $field->default,
                        'isSet' => $isSet,
                        'isMasked' => $isSet,
                        'locked' => $locked,
                    ];
                    continue;
                }

                $values[$key] = [
                    'value' => $this->normalizeStoredValue($field, $rawValue) ?? $field->default,
                    'isSet' => $isSet,
                    'isMasked' => false,
                    'locked' => $locked,
                ];
            } else {
                $rawValue = $this->getEnvValue($key);
                $isSet = '' !== $rawValue && null !== $rawValue;

                if ($field->sensitive && $isSet) {
                    $values[$key] = [
                        'value' => self::MASK,
                        'isSet' => true,
                        'isMasked' => true,
                    ];
                } else {
                    $values[$key] = [
                        'value' => $rawValue ?? $field->default,
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
            'CHAT_ARTIFACTS_ENABLED' => $this->chatArtifactsConfig?->envOverride(),
        ] as $key => $envOverride) {
            if (!isset($values[$key]) || null === $envOverride) {
                continue;
            }

            $values[$key]['envOverride'] = true;
            $values[$key]['effectiveValue'] = $envOverride ? 'true' : 'false';
        }

        // Same rule for every wave feature flag on the Features tab: an
        // automated deployment pins it with FEATURE_<GROUP>_<SETTING>, and the
        // toggle must show that instead of pretending to be movable.
        foreach ($this->schema->fields() as $key => $field) {
            if ('features' !== $field->tab || 'boolean' !== $field->type || !isset($values[$key], $field->row->group, $field->row->key)) {
                continue;
            }
            $pinned = $this->featureFlagEnv->forced($field->row->group, $field->row->key);
            if (null === $pinned) {
                continue;
            }
            $values[$key]['envOverride'] = true;
            $values[$key]['effectiveValue'] = $pinned ? 'true' : 'false';
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
     * `managedBy` in a failed result means the field has another editor
     * (Providers & keys); the controller answers 422 and the message names it.
     *
     * @return array{success: bool, requiresRestart: bool, message?: string, managedBy?: string}
     */
    public function setValue(string $key, string $value, ?int $actingUserId = null): array
    {
        $field = $this->schema->field($key);
        if (null === $field) {
            return ['success' => false, 'requiresRestart' => false, 'message' => 'Unknown configuration key'];
        }

        // One editor per key: instance provider keys are saved, tested and
        // removed under AI infrastructure › Providers & keys. Writing them here
        // would bypass the live check and the key + secret pairing rule.
        if (null !== $field->managedBy) {
            return [
                'success' => false,
                'requiresRestart' => false,
                'managedBy' => $field->managedBy,
                'message' => sprintf('%s is managed under AI infrastructure › Providers & keys (/admin/setup). Save, test or remove the key there.', $key),
            ];
        }

        // Reading a sensitive field returns self::MASK, so a client that submits
        // the form unchanged (or retries it) sends the mask back. Storing that
        // would silently destroy a working secret — refuse it server-side
        // instead of relying on the frontend to filter it out.
        if ($field->sensitive && SecretValueGuard::isMasked($value)) {
            return [
                'success' => false,
                'requiresRestart' => false,
                'message' => 'That is the masked placeholder, not a real value. Leave the field untouched to keep the current secret, or enter a new one.',
            ];
        }

        // An empty value deletes the stored override. Booleans and selects
        // always have a choice, so blank is a mistake rather than a clear.
        if ('' === $value) {
            if (\in_array($field->type, ['boolean', 'select'], true)) {
                return ['success' => false, 'requiresRestart' => false, 'message' => 'This setting needs a value'];
            }

            return $field->isStoredInDatabase()
                ? $this->clearDatabaseValue($key, $field, $actingUserId)
                : $this->clearEnvValue($key);
        }

        // Database-backed fields: write to BCONFIG, no restart needed
        if ($field->isStoredInDatabase()) {
            $capRefuse = $this->refuseComputeAboveSidecarCap($key, $value)
                ?? $this->refuseComputeFeatureSidecarLacks($key, $value)
                ?? $this->refuseComputeBelowRequiredTier($key, $value)
                ?? $this->refuseComputeInvalidTier($key, $value);
            if (null !== $capRefuse) {
                return $capRefuse;
            }

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
     * Drop a stored override so readers fall back to the field default.
     *
     * @return array{success: bool, requiresRestart: bool, message?: string}
     */
    private function clearDatabaseValue(string $key, FieldDefinition $field, ?int $actingUserId): array
    {
        $group = $field->group();
        $setting = $field->setting($key);

        try {
            $this->configRepository->deleteValue(self::DB_OWNER_ID, $group, $setting);
            $this->logChange($key, '');
            $this->applyConfigSideEffects($group, $setting, $field->default, $actingUserId);
            $this->layeredConfigResolver?->reset();

            return ['success' => true, 'requiresRestart' => false];
        } catch (\Throwable $e) {
            $this->logger->error('Failed to clear DB config', ['key' => $key, 'error' => $e->getMessage()]);

            return ['success' => false, 'requiresRestart' => false, 'message' => 'Database write failed'];
        }
    }

    /**
     * Remove one KEY= line from .env. Absent keys are already clear.
     *
     * @return array{success: bool, requiresRestart: bool, message?: string}
     */
    private function clearEnvValue(string $key): array
    {
        $envFile = $this->projectDir.'/.env';
        if (!file_exists($envFile)) {
            return ['success' => false, 'requiresRestart' => false, 'message' => '.env file not found'];
        }

        $content = file_get_contents($envFile);
        if (false === $content) {
            return ['success' => false, 'requiresRestart' => false, 'message' => 'Failed to read .env file'];
        }

        $pattern = '/^'.preg_quote($key, '/').'=.*\n?/m';
        if (!preg_match($pattern, $content)) {
            return ['success' => true, 'requiresRestart' => false];
        }

        $backupFile = $this->createBackup();
        if (!$backupFile) {
            return ['success' => false, 'requiresRestart' => false, 'message' => 'Failed to create backup'];
        }

        $newContent = preg_replace($pattern, '', $content);
        if (!\is_string($newContent)) {
            return ['success' => false, 'requiresRestart' => false, 'message' => 'Failed to update .env file'];
        }

        $tempFile = $envFile.'.tmp';
        if (false === file_put_contents($tempFile, $newContent)) {
            return ['success' => false, 'requiresRestart' => false, 'message' => 'Failed to write temp file'];
        }

        if (!rename($tempFile, $envFile)) {
            @unlink($tempFile);

            return ['success' => false, 'requiresRestart' => false, 'message' => 'Failed to save .env file'];
        }

        $this->logChange($key, '');

        return ['success' => true, 'requiresRestart' => true];
    }

    /**
     * Save a database-backed configuration value with validation.
     *
     * @return array{success: bool, requiresRestart: bool, message?: string}
     */
    private function setDatabaseValue(string $key, string $value, FieldDefinition $field, ?int $actingUserId = null): array
    {
        // Validate numeric fields
        if ('number' === $field->type) {
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
            if (ConversationSummaryConstants::CONFIG_GROUP === $field->row?->group
                && ($numericValue < 1 || floor($numericValue) !== $numericValue)
            ) {
                return ['success' => false, 'requiresRestart' => false, 'message' => 'Value must be a positive whole number'];
            }

            // Deep-memory knobs: scores are 0.0–1.0 floats; PULL_TOP_N may be
            // 0 (disables verbatim pulling); everything else is a positive
            // whole number. MessageDigestConfig clamps out-of-range values,
            // so a row that would be silently corrected is rejected here.
            if (MessageDigestConfig::CONFIG_GROUP === $field->row?->group) {
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

        $group = $field->group();
        $setting = $field->setting($key);

        // Encrypted fields hold a real credential; clearing one means storing
        // an empty row, not an empty ciphertext nobody can distinguish.
        $stored = $field->isEncrypted() && '' !== $value
            ? $this->encryption->encrypt($value)
            : $value;

        try {
            $this->configRepository->setValue(self::DB_OWNER_ID, $group, $setting, $stored);
            $this->logChange($key, $value);

            $this->applyConfigSideEffects($group, $setting, $value, $actingUserId);
            // Resolvers memoize per request; the admin's own follow-up reads in
            // this request (and the next worker-mode request) must see the write.
            $this->layeredConfigResolver?->reset();

            return ['success' => true, 'requiresRestart' => false];
        } catch (\Throwable $e) {
            $this->logger->error('Failed to save DB config', ['key' => $key, 'error' => $e->getMessage()]);

            return ['success' => false, 'requiresRestart' => false, 'message' => 'Database write failed'];
        }
    }

    /**
     * @return array{success: false, requiresRestart: false, message: string}|null
     */
    private function refuseComputeAboveSidecarCap(string $key, string $value): ?array
    {
        $map = [
            'COMPUTE_DEFAULT_TIMEOUT_SEC' => ['timeoutSec', 'seconds'],
            'COMPUTE_MAX_TIMEOUT_SEC' => ['timeoutSec', 'seconds'],
            'COMPUTE_DEFAULT_MEMORY_MB' => ['memoryMb', 'MB of memory'],
            'COMPUTE_DEFAULT_CPU' => ['cpu', 'CPU'],
            'COMPUTE_DEFAULT_PIDS' => ['pids', 'processes'],
            'COMPUTE_DEFAULT_OUTPUT_MB' => ['outputMb', 'MB of result files'],
        ];
        if (!isset($map[$key]) || null === $this->computeClient || !is_numeric($value)) {
            return null;
        }
        try {
            $caps = $this->computeClient->health()->caps;
        } catch (\Throwable) {
            return null;
        }
        [$capKey, $unit] = $map[$key];
        $cap = $caps[$capKey];
        if ((float) $value <= (float) $cap) {
            return null;
        }

        return [
            'success' => false,
            'requiresRestart' => false,
            'message' => sprintf(
                'This installation\'s file-work limit is %s %s. Enter a value at or below that.',
                $cap,
                $unit,
            ),
        ];
    }

    /**
     * A compute child flag may only be switched on when the connected sidecar
     * reports the feature in `GET /v1/health`. Otherwise the UI would show a
     * Workspace tab or promise website access that every run then refuses
     * (U11: a flag must never be a teaser). Switching off is always allowed.
     *
     * @return array{success: false, requiresRestart: false, message: string}|null
     */
    private function refuseComputeFeatureSidecarLacks(string $key, string $value): ?array
    {
        $map = [
            'COMPUTE_WORKSPACES_ENABLED' => ['workspaces', 'Persistent file-work folders are'],
            'COMPUTE_EGRESS_ENABLED' => ['egress', 'Website access for file work is'],
        ];
        if (!isset($map[$key]) || null === $this->computeClient) {
            return null;
        }
        if (!in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true)) {
            return null;
        }
        try {
            $features = $this->computeClient->health()->features;
        } catch (\Throwable) {
            return [
                'success' => false,
                'requiresRestart' => false,
                'message' => 'File work is not reachable right now. The switch stays off.',
            ];
        }
        [$featureKey, $subject] = $map[$key];
        if ($features[$featureKey]) {
            return null;
        }

        return [
            'success' => false,
            'requiresRestart' => false,
            'message' => $subject.' not offered by this installation\'s compute sidecar yet. The switch stays off.',
        ];
    }

    /**
     * COMPUTE.ENABLED may only be switched on when the connected sidecar
     * reports at least the required isolation tier (CS31). The runtime gate
     * in ComputeConfig::isEnabled() enforces the same rule continuously, so
     * raising REQUIRE_TIER later disables compute immediately without a
     * refusal. Switching off is always allowed.
     *
     * @return array{success: false, requiresRestart: false, message: string}|null
     */
    private function refuseComputeBelowRequiredTier(string $key, string $value): ?array
    {
        if ('COMPUTE_ENABLED' !== $key || null === $this->computeClient) {
            return null;
        }
        if (!in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true)) {
            return null;
        }
        try {
            $tier = $this->computeClient->health()->tier;
        } catch (\Throwable) {
            return [
                'success' => false,
                'requiresRestart' => false,
                'message' => 'File work is not reachable right now. The switch stays off.',
            ];
        }
        $required = ComputeConfig::normalizeTier(
            $this->configRepository->getValue(0, ComputeConfig::CONFIG_GROUP, ComputeConfig::KEY_REQUIRE_TIER)
        );
        if (ComputeConfig::tierAtLeast($tier, $required)) {
            return null;
        }

        return [
            'success' => false,
            'requiresRestart' => false,
            'message' => sprintf(
                'the compute service reports %s; this instance requires %s',
                ComputeConfig::tierDisplayName($tier),
                ComputeConfig::tierDisplayName($required),
            ),
        ];
    }

    /**
     * The tier requirement is only meaningful as a known tier. Reject anything
     * else at write time: reads fail closed to the strictest tier, so a bad
     * row would silently pin this instance to microVM.
     *
     * @return array{success: false, requiresRestart: false, message: string}|null
     */
    private function refuseComputeInvalidTier(string $key, string $value): ?array
    {
        if ('COMPUTE_REQUIRE_TIER' !== $key) {
            return null;
        }
        if (isset(ComputeConfig::TIER_ORDER[strtolower(trim($value))])) {
            return null;
        }

        return [
            'success' => false,
            'requiresRestart' => false,
            'message' => 'Isolation tier must be one of: docker, gvisor, microvm.',
        ];
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
        // Every flag below resolves the per-user row before the global one, so an
        // admin still carrying an own override would not see the value they just
        // set. Some of those rows come from a migration (async media,
        // Version20260629120000; multi-task grandfathering from
        // Version20260607000000, dropped again in Version20260706130000), others
        // were set by hand.
        /** @var list<array{string, string, string}> $perUserOverrides */
        $perUserOverrides = [
            [SavedTaskConfig::CONFIG_GROUP, SavedTaskConfig::KEY_ENABLED, 'saved-tasks'],
            [WorkflowsConfig::CONFIG_GROUP, WorkflowsConfig::KEY_BUILDER_ENABLED, 'workflow builder'],
            [AgentConfig::CONFIG_GROUP, AgentConfig::KEY_ENABLED, 'assistants'],
            [AgentConfig::CONFIG_GROUP, AgentConfig::KEY_ROUTABLE_ENABLED, 'routable assistants'],
            [BundleConfig::CONFIG_GROUP, BundleConfig::KEY_ENABLED, 'bundle export'],
            [McpClientConfig::CONFIG_GROUP, McpClientConfig::KEY_CLIENT_ENABLED, 'MCP client'],
            [MultitaskRoutingConfig::CONFIG_GROUP, MultitaskRoutingConfig::KEY_ROUTING_ENABLED, 'multitask routing'],
            [MediaJobConfig::CONFIG_GROUP, MediaJobConfig::KEY_ASYNC_JOBS_ENABLED, 'async media'],
        ];

        foreach ($perUserOverrides as [$overrideGroup, $overrideKey, $label]) {
            if ($group === $overrideGroup && $key === $overrideKey) {
                $this->clearActingUserOverride($overrideGroup, $overrideKey, $label, $value, $actingUserId);

                return;
            }
        }
    }

    /**
     * Drop the acting admin's own row for a flag that was just set globally.
     */
    private function clearActingUserOverride(string $group, string $key, string $label, string $globalValue, ?int $actingUserId): void
    {
        if (null === $actingUserId || $actingUserId <= 0) {
            return;
        }

        try {
            $removed = $this->configRepository->deleteValue($actingUserId, $group, $key);
            $this->logger->info('SystemConfigService: cleared admin per-user '.$label.' override', [
                'userId' => $actingUserId,
                'removed' => $removed,
                'globalValue' => $globalValue,
            ]);
        } catch (\Throwable $sideEffect) {
            $this->logger->error('SystemConfigService: failed clearing per-user '.$label.' override', [
                'userId' => $actingUserId,
                'error' => $sideEffect->getMessage(),
            ]);
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
            'docling' => $this->testDocling(),
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
        $sensitive = $this->schema->field($key)->sensitive ?? false;

        $this->logger->info('System config changed', [
            'key' => $key,
            'value' => $sensitive ? self::MASK : $value,
            'sensitive' => $sensitive,
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
    private function testDocling(): array
    {
        $url = $this->getEnvValue('DOCLING_BASE_URL');
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
     * The admin toggle only treats the strings "true" / "false" as on/off.
     * Seeders and SQL often store "1" / "0"; map those so the switch matches
     * what IamConfig (and the rest of the app) actually do.
     */
    private function normalizeStoredValue(FieldDefinition $field, ?string $rawValue): ?string
    {
        if (null === $rawValue || '' === $rawValue) {
            return $rawValue;
        }
        if ('boolean' !== $field->type) {
            return $rawValue;
        }
        $asBool = filter_var($rawValue, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE);

        return null === $asBool ? $rawValue : ($asBool ? 'true' : 'false');
    }
}
