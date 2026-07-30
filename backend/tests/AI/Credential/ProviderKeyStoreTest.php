<?php

declare(strict_types=1);

namespace App\Tests\AI\Credential;

use App\AI\Credential\ProviderKeyCatalog;
use App\AI\Credential\ProviderKeyStore;
use App\Entity\Config;
use App\Repository\ConfigRepository;
use App\Service\EncryptionService;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Deterministic unit tests for the install-wide provider key store.
 *
 * Uses a real {@see EncryptionService} (so the at-rest encryption round-trip
 * is genuinely exercised) over an in-memory fake of {@see ConfigRepository} —
 * same pattern as OpenAiCompatibleEndpointRegistryTest.
 */
final class ProviderKeyStoreTest extends TestCase
{
    /** @var array<string, Config> keyed by "owner|group|setting" */
    private array $store = [];

    private int $getValueCalls = 0;

    private ConfigRepository&Stub $configRepository;
    private EncryptionService $encryption;

    protected function setUp(): void
    {
        $this->store = [];
        $this->getValueCalls = 0;

        $this->configRepository = $this->createStub(ConfigRepository::class);
        $this->configRepository->method('getValue')->willReturnCallback(
            function (int $ownerId, string $group, string $setting): ?string {
                ++$this->getValueCalls;

                return isset($this->store[$ownerId.'|'.$group.'|'.$setting])
                    ? $this->store[$ownerId.'|'.$group.'|'.$setting]->getValue()
                    : null;
            }
        );
        $this->configRepository->method('setValue')->willReturnCallback(
            function (int $ownerId, string $group, string $setting, string $value): Config {
                $config = (new Config())
                    ->setOwnerId($ownerId)
                    ->setGroup($group)
                    ->setSetting($setting)
                    ->setValue($value);
                $this->store[$ownerId.'|'.$group.'|'.$setting] = $config;

                return $config;
            }
        );
        $this->configRepository->method('deleteValue')->willReturnCallback(
            function (int $ownerId, string $group, string $setting): bool {
                $key = $ownerId.'|'.$group.'|'.$setting;
                if (isset($this->store[$key])) {
                    unset($this->store[$key]);

                    return true;
                }

                return false;
            }
        );

        $this->encryption = new EncryptionService('test-app-secret', new NullLogger());
    }

    /**
     * @param array<string, string|list<string|null>|null> $envKeys
     */
    private function makeStore(array $envKeys = []): ProviderKeyStore
    {
        return new ProviderKeyStore($this->configRepository, $this->encryption, new NullLogger(), $envKeys);
    }

    /**
     * @return array{key: string, origin: string}|null the decrypted stored payload
     */
    private function storedPayload(string $provider): ?array
    {
        $config = $this->store['0|'.ProviderKeyStore::CONFIG_GROUP.'|'.$provider] ?? null;
        if (null === $config) {
            return null;
        }

        /* @var array{key: string, origin: string} */
        return json_decode($this->encryption->decrypt($config->getValue()), true, 8, JSON_THROW_ON_ERROR);
    }

    public function testUnsupportedProviderResolvesToNull(): void
    {
        $store = $this->makeStore(['groq' => 'gsk_env']);

        self::assertNull($store->getKey('not-a-provider'));
        self::assertFalse(ProviderKeyStore::isSupported('not-a-provider'));
    }

    public function testUnconfiguredProviderResolvesToNullWithoutWriting(): void
    {
        $store = $this->makeStore();

        self::assertNull($store->getKey('groq'));
        self::assertSame([], $this->store, 'nothing must be persisted for an unconfigured provider');
    }

    public function testEnvKeyIsImportedIntoDbOnFirstResolution(): void
    {
        $store = $this->makeStore(['groq' => 'gsk_from_env']);

        self::assertSame('gsk_from_env', $store->getKey('groq'));

        $payload = $this->storedPayload('groq');
        self::assertNotNull($payload, 'env key must be transferred into BCONFIG');
        self::assertSame('gsk_from_env', $payload['key']);
        self::assertSame(ProviderKeyStore::ORIGIN_ENV, $payload['origin']);
    }

    /**
     * An untouched `.env.example` must NOT look like a configured provider —
     * and the placeholder must never be persisted into BCONFIG.
     */
    public function testPlaceholderEnvValueIsIgnoredEntirely(): void
    {
        $store = $this->makeStore(['groq' => 'your-api-key-here', 'openai' => '<your-key>', 'xai' => 'CHANGEME']);

        self::assertNull($store->getKey('groq'));
        self::assertNull($store->getKey('openai'));
        self::assertNull($store->getKey('xai'));
        self::assertSame([], $this->store, 'a placeholder must not be imported');

        self::assertFalse($store->getStatus('groq')['configured']);
        self::assertSame('none', $store->getStatus('groq')['source']);
    }

    public function testSavingAPlaceholderIsRejected(): void
    {
        $store = $this->makeStore();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/placeholder/i');
        $store->saveKey('groq', 'your-api-key-here');
    }

    /**
     * A client that echoes back the masked display value must not overwrite the
     * stored key with bullet characters.
     */
    public function testSavingTheMaskedDisplayValueIsRejected(): void
    {
        $store = $this->makeStore();
        $store->saveKey('groq', 'gsk_real_key_value');

        try {
            $store->saveKey('groq', ProviderKeyStore::mask('gsk_real_key_value'));
            self::fail('the masked value must be rejected');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('masked', $e->getMessage());
        }

        self::assertSame('gsk_real_key_value', $store->getKey('groq'), 'the stored key must survive');
    }

    /**
     * Google documents GEMINI_API_KEY / GOOGLE_API_KEY as accepted aliases
     * (GoogleProvider::getRequiredEnvVars any_of).
     */
    public function testEnvAliasesAreAcceptedInOrder(): void
    {
        $store = $this->makeStore(['google' => ['', 'gemini_alias_key', 'google_api_key']]);

        self::assertSame('gemini_alias_key', $store->getKey('google'));
    }

    /**
     * The aliases are wired as `%env(default::GEMINI_API_KEY)%`, which the
     * container resolves to NULL — not '' — while the variable is unset. Every
     * provider lookup ran through this list, so a null alias took down the
     * health endpoint, not just Google.
     */
    public function testUnsetAliasesArriveAsNullAndAreSkipped(): void
    {
        $store = $this->makeStore(['google' => [null, null, null], 'groq' => null]);

        self::assertNull($store->getKey('google'));
        self::assertNull($store->getKey('groq'));
        self::assertFalse($store->getStatus('google')['configured']);
        self::assertFalse($store->hasEnvKey('google'));
        self::assertSame([], $this->store);

        $withKey = $this->makeStore(['google' => [null, 'gemini_alias_key', null]]);
        self::assertSame('gemini_alias_key', $withKey->getKey('google'));
    }

    public function testStoredValueIsEncryptedAtRest(): void
    {
        $store = $this->makeStore();
        $store->saveKey('openai', 'sk-proj-super-secret');

        $raw = $this->store['0|'.ProviderKeyStore::CONFIG_GROUP.'|openai']->getValue();
        self::assertStringNotContainsString('sk-proj-super-secret', $raw);
        self::assertStringNotContainsString('sk-proj-super-secret', base64_decode($raw, true) ?: '');
    }

    public function testUiSavedKeyWinsOverDifferentEnvKey(): void
    {
        $store = $this->makeStore(['groq' => 'gsk_old_env']);
        $store->saveKey('groq', 'gsk_from_ui');

        self::assertSame('gsk_from_ui', $store->getKey('groq'));
        self::assertSame(ProviderKeyStore::ORIGIN_UI, $this->storedPayload('groq')['origin'] ?? null);
    }

    public function testEnvOriginRowFollowsEnvRotation(): void
    {
        // First boot: env key imported.
        $this->makeStore(['groq' => 'gsk_v1'])->getKey('groq');

        // Operator rotates the secret; a NEW process sees the new env value.
        $rotated = $this->makeStore(['groq' => 'gsk_v2']);

        self::assertSame('gsk_v2', $rotated->getKey('groq'));
        self::assertSame('gsk_v2', $this->storedPayload('groq')['key'] ?? null, 'DB copy must be refreshed on rotation');
    }

    public function testEnvOriginRowSurvivesEnvVarRemoval(): void
    {
        $this->makeStore(['groq' => 'gsk_v1'])->getKey('groq');

        // Key removed from .env after the transfer — DB copy keeps working.
        $store = $this->makeStore();

        self::assertSame('gsk_v1', $store->getKey('groq'));
    }

    public function testSaveKeyRejectsEmptyKeyAndUnknownProvider(): void
    {
        $store = $this->makeStore();

        try {
            $store->saveKey('groq', '   ');
            self::fail('empty key must be rejected');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\InvalidArgumentException::class);
        $store->saveKey('not-a-provider', 'some-key');
    }

    public function testDeleteKeyFallsBackToEnvReimport(): void
    {
        $store = $this->makeStore(['groq' => 'gsk_env']);
        $store->saveKey('groq', 'gsk_ui');
        self::assertSame('gsk_ui', $store->getKey('groq'));

        self::assertTrue($store->deleteKey('groq'));

        // Next resolution re-imports the env var.
        self::assertSame('gsk_env', $store->getKey('groq'));
        self::assertSame(ProviderKeyStore::ORIGIN_ENV, $this->storedPayload('groq')['origin'] ?? null);
    }

    public function testResolutionIsMemoizedPerProcess(): void
    {
        $store = $this->makeStore(['groq' => 'gsk_env']);

        $store->getKey('groq');
        $callsAfterFirst = $this->getValueCalls;
        $store->getKey('groq');
        $store->getKey('groq');

        self::assertSame($callsAfterFirst, $this->getValueCalls, 'repeated reads within the TTL must not hit the repository');
    }

    public function testSaveInvalidatesMemo(): void
    {
        $store = $this->makeStore(['groq' => 'gsk_env']);
        self::assertSame('gsk_env', $store->getKey('groq'));

        $store->saveKey('groq', 'gsk_new_ui');

        self::assertSame('gsk_new_ui', $store->getKey('groq'), 'a UI save must be visible immediately, not after the memo TTL');
    }

    public function testCorruptCiphertextIsTreatedAsNotConfigured(): void
    {
        $this->store['0|'.ProviderKeyStore::CONFIG_GROUP.'|groq'] = (new Config())
            ->setOwnerId(0)
            ->setGroup(ProviderKeyStore::CONFIG_GROUP)
            ->setSetting('groq')
            ->setValue('not-valid-ciphertext');

        // Falls through to the env bootstrap instead of failing the request.
        $store = $this->makeStore(['groq' => 'gsk_env']);

        self::assertSame('gsk_env', $store->getKey('groq'));
    }

    public function testStatusReportsDbEnvAndNoneSourcesWithMaskedKeys(): void
    {
        $store = $this->makeStore(['openai' => 'sk-proj-envenvenvenv']);
        $store->saveKey('groq', 'gsk_1234567890abcdef');

        $db = $store->getStatus('groq');
        self::assertTrue($db['configured']);
        self::assertSame('db', $db['source']);
        self::assertSame(ProviderKeyStore::ORIGIN_UI, $db['origin']);
        self::assertStringNotContainsString('1234567890', $db['maskedKey']);
        self::assertStringStartsWith('gsk_', $db['maskedKey']);
        self::assertStringEndsWith('cdef', $db['maskedKey']);

        $env = $store->getStatus('openai');
        self::assertTrue($env['configured']);
        self::assertSame('env', $env['source']);

        $none = $store->getStatus('anthropic');
        self::assertFalse($none['configured']);
        self::assertSame('none', $none['source']);
        self::assertSame('', $none['maskedKey']);
    }

    public function testMaskNeverRevealsShortKeys(): void
    {
        self::assertSame('••••••', ProviderKeyStore::mask('secret'));
        self::assertSame('sk-a', substr(ProviderKeyStore::mask('sk-abcdefghijklmnop'), 0, 4));
    }

    public function testCatalogCoversEverySupportedProvider(): void
    {
        foreach (ProviderKeyStore::SUPPORTED_PROVIDERS as $provider) {
            self::assertTrue(ProviderKeyCatalog::has($provider), sprintf('ProviderKeyCatalog is missing metadata for "%s"', $provider));
        }
    }
}
