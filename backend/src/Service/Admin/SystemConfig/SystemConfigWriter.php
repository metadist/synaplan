<?php

declare(strict_types=1);

namespace App\Service\Admin\SystemConfig;

use App\AI\Credential\SecretValueGuard;
use App\Bundle\BundleConfig;
use App\Repository\ConfigRepository;
use App\Service\Agent\AgentConfig;
use App\Service\Config\LayeredConfigResolver;
use App\Service\Digest\MessageDigestConfig;
use App\Service\Mcp\McpClientConfig;
use App\Service\Media\MediaJobConfig;
use App\Service\Message\ConversationSummaryConstants;
use App\Service\Multitask\MultitaskRoutingConfig;
use App\Service\SavedTask\SavedTaskConfig;
use App\Service\SavedTask\WorkflowsConfig;
use App\Service\Security\EncryptionService;
use Psr\Log\LoggerInterface;

/**
 * Writes one setting from Admin → System settings. It refuses an unknown
 * key, a key with another editor, the mask sent back and a blank switch; a
 * blank value clears the setting. A BCONFIG row is validated, encrypted when
 * it holds a credential, checked by the compute guard and applies at once;
 * an .env line needs a restart. A write that sets a flag globally drops the
 * acting admin's own row for it. Secrets never reach the log.
 */
final readonly class SystemConfigWriter
{
    public function __construct(
        private SystemConfigSchema $schema,
        private ConfigRepository $configRepository,
        private EncryptionService $encryption,
        private EnvSettings $env,
        private ComputeSettingGuard $computeGuard,
        private LoggerInterface $logger,
        private ?LayeredConfigResolver $layeredConfigResolver = null,
    ) {
    }

    /**
     * `managedBy` in a failed result means the field has another editor
     * (Providers & keys); the controller answers 422 and the message names it.
     *
     * @return array{success: bool, requiresRestart: bool, message?: string, managedBy?: string}
     */
    public function write(string $key, string $value, ?int $actingUserId = null): array
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

        // Reading a sensitive field returns the mask, so a client that submits
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

        $invalidChoice = $this->invalidChoice($value, $field);
        if (null !== $invalidChoice) {
            return ['success' => false, 'requiresRestart' => false, 'message' => $invalidChoice];
        }

        // Database-backed fields: write to BCONFIG, no restart needed
        if ($field->isStoredInDatabase()) {
            return $this->computeGuard->refusal($key, $value)
                ?? $this->setDatabaseValue($key, $value, $field, $actingUserId);
        }

        $result = $this->env->write($key, $value);
        if ($result['success']) {
            $this->logChange($key, $value);
        }

        return $result;
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
            $this->configRepository->deleteValue(DatabaseRow::OWNER, $group, $setting);
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
     * Save a database-backed configuration value with validation.
     *
     * @return array{success: bool, requiresRestart: bool, message?: string}
     */
    private function setDatabaseValue(string $key, string $value, FieldDefinition $field, ?int $actingUserId = null): array
    {
        $invalid = $this->invalidNumber($key, $value, $field);
        if (null !== $invalid) {
            return ['success' => false, 'requiresRestart' => false, 'message' => $invalid];
        }

        $group = $field->group();
        $setting = $field->setting($key);

        // Encrypted fields hold a real credential; clearing one means storing
        // an empty row, not an empty ciphertext nobody can distinguish.
        $stored = $field->isEncrypted() && '' !== $value
            ? $this->encryption->encrypt($value)
            : $value;

        try {
            $this->configRepository->setValue(DatabaseRow::OWNER, $group, $setting, $stored);
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
     * Why a select refuses $value, or null when it is one of the options.
     * An unknown mode must not be stored: readers treat it as unset and
     * fall back to open, so the write would look successful and do nothing.
     */
    private function invalidChoice(string $value, FieldDefinition $field): ?string
    {
        if ('select' !== $field->type || null === $field->options) {
            return null;
        }
        if (\in_array($value, $field->options, true)) {
            return null;
        }

        return 'Choose one of: '.implode(', ', $field->options).'.';
    }

    /**
     * Why a number field refuses $value, or null when it may be stored.
     */
    private function invalidNumber(string $key, string $value, FieldDefinition $field): ?string
    {
        if ('number' !== $field->type) {
            return null;
        }

        if (!is_numeric($value)) {
            return 'Value must be numeric';
        }

        $numericValue = (float) $value;

        // Score fields must be between 0.0 and 1.0
        if (str_starts_with($key, 'MIN_') && ($numericValue < 0.0 || $numericValue > 1.0)) {
            return 'Score must be between 0.0 and 1.0';
        }

        // Limit fields must be positive integers
        if (str_starts_with($key, 'LIMIT_') || str_starts_with($key, 'MAX_')) {
            if ($numericValue < 1 || floor($numericValue) !== $numericValue) {
                return 'Limit must be a positive integer';
            }
        }

        // Rolling-summary sizes are whole counts (characters, messages,
        // tiers, seconds). ConversationSummaryConfigService discards a
        // non-positive value and uses its default, so reject it here
        // instead of storing a row that does nothing.
        if (ConversationSummaryConstants::CONFIG_GROUP === $field->row?->group
            && ($numericValue < 1 || floor($numericValue) !== $numericValue)
        ) {
            return 'Value must be a positive whole number';
        }

        // Deep-memory knobs: scores are 0.0–1.0 floats; PULL_TOP_N may be
        // 0 (disables verbatim pulling); everything else is a positive
        // whole number. MessageDigestConfig clamps out-of-range values,
        // so a row that would be silently corrected is rejected here.
        if (MessageDigestConfig::CONFIG_GROUP === $field->row?->group) {
            if (str_contains($key, 'MIN_SCORE')) {
                if ($numericValue < 0.0 || $numericValue > 1.0) {
                    return 'Score must be between 0.0 and 1.0';
                }
            } elseif (floor($numericValue) !== $numericValue
                || $numericValue < ('DIGEST_PULL_TOP_N' === $key ? 0 : 1)
            ) {
                return 'DIGEST_PULL_TOP_N' === $key
                    ? 'Value must be a whole number (0 disables pulling)'
                    : 'Value must be a positive whole number';
            }
        }

        return null;
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
     * Remove one KEY= line from .env. Absent keys are already clear.
     *
     * @return array{success: bool, requiresRestart: bool, message?: string}
     */
    private function clearEnvValue(string $key): array
    {
        $result = $this->env->remove($key);
        if ($result['requiresRestart']) {
            $this->logChange($key, '');
        }

        return $result;
    }

    private function logChange(string $key, string $value): void
    {
        $sensitive = $this->schema->field($key)->sensitive ?? false;

        $this->logger->info('System config changed', [
            'key' => $key,
            'value' => $sensitive ? SystemConfigValues::MASK : $value,
            'sensitive' => $sensitive,
        ]);
    }
}
