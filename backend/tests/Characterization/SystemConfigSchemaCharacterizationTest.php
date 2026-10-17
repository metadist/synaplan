<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\AI\Credential\ProviderKeyCatalog;
use App\AI\Credential\ProviderKeyStore;
use App\Entity\Config;
use App\Module\Contract\FeatureModuleInterface;
use App\Module\ModuleRegistry;
use App\Repository\ConfigRepository;
use App\Service\Chat\ChatArtifactsConfig;
use App\Service\Feature\FeatureFlagEnv;
use App\Service\Security\EncryptionService;
use App\Tests\Characterization\Support\CleanupSnapshot;
use App\Tests\Support\SystemConfigServiceFactory;
use App\Tests\Unit\Module\Fixture\BuildsAllModules;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * The admin system settings as the UI receives them, recorded before the
 * schema left SystemConfigService: getSchema() with and without the module
 * registry, and getValues() over stored rows (compute on and off, a locked
 * row, an encrypted secret, the acting admin's own override), the
 * environment (secrets, the access flags, pinned feature flags) and
 * provider keys.
 *
 * Objects compare as maps; lists keep their order. The tabs are recorded as
 * a list, so the tab order, each tab's section order and every section's
 * field list stay pinned. Every schema key is cleared from the environment
 * around each case. Record with UPDATE_CLEANUP_V2_SNAPSHOTS=1.
 *
 * @phpstan-type SettingsCase array{
 *     modules?: bool,
 *     schema?: true|list<string>,
 *     rows?: array<string, string>,
 *     locked?: list<string>,
 *     personal?: array<string, string>,
 *     env?: array<string, string>,
 *     flags?: array<string, string>,
 *     artifactsEnv?: string,
 *     envKeys?: array<string, string>,
 *     envSecrets?: array<string, string>,
 *     savedKeys?: array<string, string>,
 * }
 */
final class SystemConfigSchemaCharacterizationTest extends TestCase
{
    use BuildsAllModules;

    private const SNAPSHOT = __DIR__.'/__snapshots__/system_config_schema.json';
    private const ACTING_ADMIN = 7;
    private const DEFAULT_GROUP = 'QDRANT_SEARCH';

    /** @var array<string, array{env: mixed, server: mixed, getenv: string|false}> */
    private array $savedEnvironment = [];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $fieldIndex = null;

    protected function tearDown(): void
    {
        foreach ($this->savedEnvironment as $key => $saved) {
            unset($_ENV[$key], $_SERVER[$key]);
            if (null !== $saved['env']) {
                $_ENV[$key] = $saved['env'];
            }
            if (null !== $saved['server']) {
                $_SERVER[$key] = $saved['server'];
            }
            putenv(false === $saved['getenv'] ? $key : $key.'='.$saved['getenv']);
        }
        $this->savedEnvironment = [];
    }

    public function testTheSettingsTheAdminSeesMatchTheSnapshot(): void
    {
        $actual = [];
        foreach (self::cases() as $name => $case) {
            $actual[$name] = $this->record($case);
        }

        CleanupSnapshot::assertSame(self::SNAPSHOT, $actual, 'System settings');
    }

    /**
     * @return array<string, SettingsCase>
     */
    private static function cases(): array
    {
        $stored = [
            'REGISTRATION_ENABLED' => '1',
            'GUEST_CHAT_ENABLED' => '0',
            'FEATURE_AGENTS_ENABLED' => 'true',
            'TOOLS_POLICY_READ' => 'ask',
            'MIN_CHAT_MEMORY_SCORE' => '0.42',
            'IAM_AUDIT_RETENTION_DAYS' => '30',
            'BRAND_NAME' => 'Acme',
            'M365_CLIENT_SECRET' => 'stored-ciphertext',
            'SAVEDTASKS_ENABLED' => 'false',
            'MULTITASK_ROUTING_ENABLED' => 'false',
        ];

        return [
            'defaults' => ['schema' => true],
            'modules' => ['modules' => true, 'schema' => true],
            'registration_closed' => ['rows' => ['REGISTRATION_ENABLED' => 'false'], 'schema' => ['IAM_EVERYONE_SHARES']],
            'stored_compute_on' => [
                'modules' => true,
                'rows' => $stored + [
                    'COMPUTE_ENABLED' => 'true',
                    'COMPUTE_REQUIRE_TIER' => 'gvisor',
                    'COMPUTE_WORKSPACES_ENABLED' => '1',
                    'COMPUTE_DEFAULT_TIMEOUT_SEC' => '120',
                ],
                'locked' => ['SAVEDTASKS_ENABLED'],
                'personal' => ['MULTITASK_ROUTING_ENABLED' => 'true'],
            ],
            'stored_compute_off' => [
                'modules' => true,
                'rows' => $stored + ['COMPUTE_ENABLED' => 'false'],
                'personal' => ['MULTITASK_ROUTING_ENABLED' => ''],
            ],
            'environment' => [
                'modules' => true,
                'env' => [
                    'OLLAMA_BASE_URL' => 'http://gpu.internal:11434',
                    'MAILER_DSN' => 'smtp://user:secret@mail:25',
                    'RECAPTCHA_SECRET_KEY' => 'recaptcha-secret',
                    'TIKA_TIMEOUT_MS' => '5000',
                    'WHISPER_ENABLED' => 'true',
                    'APP_SENDER_NAME' => '',
                    'REGISTRATION_ENABLED' => 'false',
                    'GUEST_CHAT_ENABLED' => 'true',
                ],
                'flags' => ['FEATURE_WORKFLOWS_BUILDER_ENABLED' => 'false', 'FEATURE_AGENTS_ENABLED' => 'true'],
                'artifactsEnv' => 'false',
            ],
            'provider_keys' => [
                'envKeys' => ['higgsfield' => 'hf-key-from-env', 'anthropic' => 'sk-ant-from-env'],
                'envSecrets' => ['higgsfield' => 'hf-secret-from-env'],
                'savedKeys' => ['openai' => 'sk-openai-saved-in-the-ui'],
            ],
        ];
    }

    /**
     * @param SettingsCase $case
     *
     * @return array<string, mixed>
     */
    private function record(array $case): array
    {
        $index = $this->fieldIndex();
        $this->isolateEnvironment(array_keys($index), $case['env'] ?? []);

        $rows = [];
        foreach ($case['rows'] ?? [] as $key => $value) {
            $rows[self::rowKey(0, $index[$key], $key)] = $value;
        }
        foreach ($case['personal'] ?? [] as $key => $value) {
            $rows[self::rowKey(self::ACTING_ADMIN, $index[$key], $key)] = $value;
        }
        $locked = array_map(static fn (string $key): string => self::rowKey(0, $index[$key], $key), $case['locked'] ?? []);
        $repository = $this->repository($rows, $locked);

        $encryption = new EncryptionService('test-secret', new NullLogger());
        $store = new ProviderKeyStore($repository, $encryption, new NullLogger(), $case['envKeys'] ?? [], $case['envSecrets'] ?? []);
        foreach ($case['savedKeys'] ?? [] as $provider => $key) {
            $store->saveKey($provider, $key);
        }

        $service = SystemConfigServiceFactory::build([
            'configRepository' => $repository,
            'encryption' => $encryption,
            'providerKeyStore' => $store,
            'featureFlagEnv' => new FeatureFlagEnv($case['flags'] ?? []),
            'modules' => ($case['modules'] ?? false) ? $this->moduleRegistry() : null,
            'chatArtifactsConfig' => new ChatArtifactsConfig($repository, $case['artifactsEnv'] ?? ''),
        ]);

        $record = ['values' => $service->getValues(self::ACTING_ADMIN)];
        $schema = $service->getSchema();
        $wanted = $case['schema'] ?? null;
        if (true === $wanted) {
            $record['tabs'] = self::tabsInOrder($schema['tabs']);
            $record['fields'] = $schema['fields'];
        } elseif (\is_array($wanted)) {
            $record['fields'] = array_intersect_key($schema['fields'], array_flip($wanted));
        }

        return $record;
    }

    /**
     * @param array<string, array{label: string, sections: array<string, array{label: string, fields: array<string>}>}> $tabs
     *
     * @return list<array{id: string, label: string, sections: list<array{id: string, label: string, fields: list<string>}>}>
     */
    private static function tabsInOrder(array $tabs): array
    {
        $ordered = [];
        foreach ($tabs as $id => $tab) {
            $sections = [];
            foreach ($tab['sections'] as $sectionId => $section) {
                $sections[] = ['id' => $sectionId, 'label' => $section['label'], 'fields' => array_values($section['fields'])];
            }
            $ordered[] = ['id' => $id, 'label' => $tab['label'], 'sections' => $sections];
        }

        return $ordered;
    }

    /**
     * Every field of the full schema (module registry present), read once
     * from an empty repository to resolve where a stored row lives.
     *
     * @return array<string, array<string, mixed>>
     */
    private function fieldIndex(): array
    {
        return $this->fieldIndex ??= SystemConfigServiceFactory::build([
            'configRepository' => $this->repository([], []),
            'featureFlagEnv' => new FeatureFlagEnv([]),
            'modules' => $this->moduleRegistry(),
        ])->getSchema()['fields'];
    }

    /**
     * @param array<string, mixed> $field
     */
    private static function rowKey(int $owner, array $field, string $key): string
    {
        return $owner.'|'.($field['dbGroup'] ?? self::DEFAULT_GROUP).'|'.($field['dbKey'] ?? $key);
    }

    /**
     * @param array<string, string> $rows   "owner|group|setting" => value
     * @param list<string>          $locked "owner|group|setting"
     */
    private function repository(array $rows, array $locked): ConfigRepository
    {
        $repository = $this->createStub(ConfigRepository::class);
        $repository->method('getValue')->willReturnCallback(
            static function (int $owner, string $group, string $setting) use (&$rows): ?string {
                return $rows[$owner.'|'.$group.'|'.$setting] ?? null;
            },
        );
        $repository->method('setValue')->willReturnCallback(
            static function (int $owner, string $group, string $setting, string $value) use (&$rows): Config {
                $rows[$owner.'|'.$group.'|'.$setting] = $value;

                return (new Config())->setOwnerId($owner)->setGroup($group)->setSetting($setting)->setValue($value);
            },
        );
        $repository->method('deleteValue')->willReturnCallback(
            static function (int $owner, string $group, string $setting) use (&$rows): bool {
                $existed = isset($rows[$owner.'|'.$group.'|'.$setting]);
                unset($rows[$owner.'|'.$group.'|'.$setting]);

                return $existed;
            },
        );
        $repository->method('findByOwnerGroupAndSetting')->willReturnCallback(
            static function (int $owner, string $group, string $setting) use (&$rows, $locked): ?Config {
                $rowKey = $owner.'|'.$group.'|'.$setting;
                if (!isset($rows[$rowKey])) {
                    return null;
                }

                return (new Config())->setOwnerId($owner)->setGroup($group)->setSetting($setting)
                    ->setValue($rows[$rowKey])->setBlocked(\in_array($rowKey, $locked, true));
            },
        );

        return $repository;
    }

    private function moduleRegistry(): ModuleRegistry
    {
        $factories = [];
        foreach ($this->allModules() as $id => $module) {
            $factories[$id] = static fn (): FeatureModuleInterface => $module;
        }

        return new ModuleRegistry(new ServiceLocator($factories));
    }

    /**
     * Clears every schema key and provider-key variable from the environment,
     * then sets the case's values; tearDown() restores what was there.
     *
     * @param list<string>          $keys
     * @param array<string, string> $values
     */
    private function isolateEnvironment(array $keys, array $values): void
    {
        foreach (array_unique([...$keys, ...ProviderKeyCatalog::managedEnvVars()]) as $key) {
            if (!\array_key_exists($key, $this->savedEnvironment)) {
                $this->savedEnvironment[$key] = ['env' => $_ENV[$key] ?? null, 'server' => $_SERVER[$key] ?? null, 'getenv' => getenv($key)];
            }
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }
        foreach ($values as $key => $value) {
            $_ENV[$key] = $value;
        }
    }
}
