<?php

declare(strict_types=1);

namespace App\Service\Admin\SystemConfig;

use App\AI\Credential\ProviderKeyCatalog;
use App\AI\Credential\ProviderKeyStore;
use App\Repository\ConfigRepository;
use App\Service\Auth\RegistrationConfig;
use App\Service\Auth\SignupPauseSwitch;
use App\Service\Chat\ChatArtifactsConfig;
use App\Service\Chat\GuestChatConfig;
use App\Service\Feature\FeatureFlagEnv;
use App\Service\Multitask\MultitaskRoutingConfig;

/**
 * The values Admin → System settings shows, never a secret in plain text: a
 * provider key's status from its store, a BCONFIG row (booleans normalized,
 * its lock), or the environment. A stored switch that an environment
 * variable pins says so (`envOverride`), and the acting admin sees the
 * multi-task routing that applies to them.
 *
 * @phpstan-type SettingValue array{value: string, isSet: bool, isMasked: bool, effectiveForMe?: string, hasPersonalOverride?: bool, envOverride?: bool, effectiveValue?: string, locked?: bool, keySource?: string}
 */
final readonly class SystemConfigValues
{
    public const MASK = '••••••••';

    public function __construct(
        private SystemConfigSchema $schema,
        private ConfigRepository $configRepository,
        private ProviderKeyStore $providerKeyStore,
        private EnvSettings $env,
        private RegistrationConfig $registrationConfig,
        private GuestChatConfig $guestChatConfig,
        private ProviderKeyCatalog $keyCatalog,
        private FeatureFlagEnv $featureFlagEnv = new FeatureFlagEnv(),
        private ?ChatArtifactsConfig $chatArtifactsConfig = null,
        private ?SignupPauseSwitch $signupPauseSwitch = null,
    ) {
    }

    /**
     * @param list<string>|null $onlyKeys limit the lookup to these keys (null = every field)
     *
     * @return array<string, SettingValue>
     */
    public function read(?int $actingUserId = null, ?array $onlyKeys = null): array
    {
        $values = [];
        $wanted = null === $onlyKeys ? null : array_flip($onlyKeys);
        foreach ($this->schema->fields() as $key => $field) {
            if (null === $wanted || isset($wanted[$key])) {
                $values[$key] = $this->valueOf($key, $field);
            }
        }

        return $this->withPersonalMultitask($this->withPinnedSwitches($values), $actingUserId);
    }

    /**
     * @return SettingValue
     */
    private function valueOf(string $key, FieldDefinition $field): array
    {
        // Instance provider keys live in the encrypted ProviderKeyStore
        // (BCONFIG), not in .env — report their status from there so this
        // legacy surface and Providers & keys agree. The secret half of a
        // key + secret pair reports its own presence.
        $storeProvider = $this->keyCatalog->providerForEnvVar($key);
        if (null !== $storeProvider) {
            return $this->providerKeyValue($key, $storeProvider, $field);
        }

        return $field->isStoredInDatabase()
            ? $this->databaseValue($key, $field)
            : $this->environmentValue($key, $field);
    }

    /**
     * @return SettingValue
     */
    private function providerKeyValue(string $key, string $provider, FieldDefinition $field): array
    {
        $status = $this->providerKeyStore->getStatus($provider);
        // Each half of a pair reports its own presence so a missing
        // secret is visible. An env-imported DB row is still "from
        // the environment / Helm", not a UI override.
        $isSet = $this->keyCatalog->isSecretEnvVar($key)
            ? $status['hasSecret']
            : '' !== $status['maskedKey'];
        $keySource = 'db' === $status['source'] && ProviderKeyStore::ORIGIN_ENV === $status['origin']
            ? 'env'
            : $status['source'];

        return [
            'value' => $isSet ? self::MASK : $field->default,
            'isSet' => $isSet,
            'isMasked' => $isSet,
            'keySource' => $keySource,
        ];
    }

    /**
     * @return SettingValue
     */
    private function databaseValue(string $key, FieldDefinition $field): array
    {
        $dbGroup = $field->group();
        $dbKey = $field->setting($key);
        $rawValue = $this->configRepository->getValue(DatabaseRow::OWNER, $dbGroup, $dbKey);
        $isSet = null !== $rawValue && '' !== $rawValue;
        $locked = $this->configRepository
            ->findByOwnerGroupAndSetting(DatabaseRow::OWNER, $dbGroup, $dbKey)
            ?->isBlocked() ?? false;

        // A database-backed secret (e.g. an OAuth client secret) is
        // stored encrypted and must be masked here for the same reason
        // an env secret is: this response reaches the admin UI.
        if ($field->sensitive) {
            return [
                'value' => $isSet ? self::MASK : $field->default,
                'isSet' => $isSet,
                'isMasked' => $isSet,
                'locked' => $locked,
            ];
        }

        return [
            'value' => $this->normalizeStoredValue($field, $rawValue) ?? $field->default,
            'isSet' => $isSet,
            'isMasked' => false,
            'locked' => $locked,
        ];
    }

    /**
     * @return SettingValue
     */
    private function environmentValue(string $key, FieldDefinition $field): array
    {
        $rawValue = $this->env->value($key);
        $isSet = '' !== $rawValue && null !== $rawValue;

        if ($field->sensitive && $isSet) {
            return [
                'value' => self::MASK,
                'isSet' => true,
                'isMasked' => true,
            ];
        }

        return [
            'value' => $rawValue ?? $field->default,
            'isSet' => $isSet,
            'isMasked' => false,
        ];
    }

    /**
     * @param array<string, SettingValue> $values
     *
     * @return array<string, SettingValue>
     */
    private function withPinnedSwitches(array $values): array
    {
        // The access-surface flags are stored in BCONFIG but an explicit
        // environment variable still wins. Report that, otherwise the page shows
        // a toggle the operator can move while nothing changes — the exact kind
        // of silent no-op that costs an afternoon of debugging.
        foreach ([
            'REGISTRATION_ENABLED' => $this->registrationConfig->envOverride(),
            'GUEST_CHAT_ENABLED' => $this->guestChatConfig->envOverride(),
            'CHAT_ARTIFACTS_ENABLED' => $this->chatArtifactsConfig?->envOverride(),
            'SIGNUP_PAUSED' => $this->signupPauseSwitch?->envOverride(),
        ] as $key => $envOverride) {
            if (!isset($values[$key]) || null === $envOverride) {
                continue;
            }

            $values[$key]['envOverride'] = true;
            $values[$key]['effectiveValue'] = $envOverride ? 'true' : 'false';
        }

        // The registration mode is a select, not a toggle, but pinned the same way.
        $modeOverride = $this->registrationConfig->modeEnvOverride();
        if (null !== $modeOverride && isset($values['REGISTRATION_MODE'])) {
            $values['REGISTRATION_MODE']['envOverride'] = true;
            $values['REGISTRATION_MODE']['effectiveValue'] = $modeOverride;
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

        return $values;
    }

    /**
     * #1079: surface effective multitask routing for the acting admin so the
     * UI can warn when a personal BCONFIG override shadows the global toggle.
     *
     * @param array<string, SettingValue> $values
     *
     * @return array<string, SettingValue>
     */
    private function withPersonalMultitask(array $values, ?int $actingUserId): array
    {
        if (null === $actingUserId || $actingUserId <= 0 || !isset($values['MULTITASK_ROUTING_ENABLED'])) {
            return $values;
        }

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

        return $values;
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
