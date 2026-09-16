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
     * @param array<string, string|null>                   $envSecrets
     */
    private function makeStore(array $envKeys = [], array $envSecrets = []): ProviderKeyStore
    {
        return new ProviderKeyStore($this->configRepository, $this->encryption, new NullLogger(), $envKeys, $envSecrets);
    }

    /**
     * @return array{key: string, origin: string, secret?: string}|null the decrypted stored payload
     */
    private function storedPayload(string $provider): ?array
    {
        $config = $this->store['0|'.ProviderKeyStore::CONFIG_GROUP.'|'.$provider] ?? null;
        if (null === $config) {
            return null;
        }

        /* @var array{key: string, origin: string, secret?: string} */
        return json_decode($this->encryption->decrypt($config->getValue()), true, 8, JSON_THROW_ON_ERROR);
    }

    // ---- key + secret pairs (Higgsfield) ----

    public function testSecretProviderRefusesAKeyWithoutItsSecret(): void
    {
        $store = $this->makeStore();

        try {
            $store->saveKey('higgsfield', 'hf-key');
            self::fail('a half pair must be rejected');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('secret', $e->getMessage());
        }

        self::assertSame([], $this->store, 'nothing may be persisted for a half pair');
        self::assertNull($store->getKey('higgsfield'));
    }

    public function testSecretProviderStoresBothHalvesEncryptedAndResolvesThem(): void
    {
        $store = $this->makeStore();
        $store->saveKey('higgsfield', 'hf-key-1234567890', ProviderKeyStore::ORIGIN_UI, 'hf-secret-abcdefgh');

        self::assertSame('hf-key-1234567890', $store->getKey('higgsfield'));
        self::assertSame('hf-secret-abcdefgh', $store->getSecret('higgsfield'));

        $raw = $this->store['0|'.ProviderKeyStore::CONFIG_GROUP.'|higgsfield']->getValue();
        self::assertStringNotContainsString('hf-secret-abcdefgh', $raw);

        $status = $store->getStatus('higgsfield');
        self::assertTrue($status['configured']);
        self::assertTrue($status['hasSecret']);
        self::assertStringNotContainsString('abcdefgh', $status['maskedKey'], 'the secret is never shown, not even masked');
    }

    public function testSingleKeyProviderIgnoresAStraySecret(): void
    {
        $store = $this->makeStore();
        $store->saveKey('groq', 'gsk_key', ProviderKeyStore::ORIGIN_UI, 'should-be-dropped');

        self::assertNull($store->getSecret('groq'));
        self::assertArrayNotHasKey('secret', $this->storedPayload('groq') ?? []);
        self::assertFalse($store->getStatus('groq')['hasSecret']);
    }

    public function testHalfEnvPairIsNotConfigured(): void
    {
        $keyOnly = $this->makeStore(['higgsfield' => 'hf-env-key']);
        self::assertNull($keyOnly->getKey('higgsfield'));
        self::assertNull($keyOnly->getSecret('higgsfield'));
        self::assertSame([], $this->store, 'a half env pair must not be imported');
        $keyOnlyStatus = $keyOnly->getStatus('higgsfield');
        self::assertFalse($keyOnlyStatus['configured'], 'key without secret is not connected');
        self::assertSame('env', $keyOnlyStatus['source']);
        self::assertFalse($keyOnlyStatus['hasSecret']);

        $secretOnly = $this->makeStore([], ['higgsfield' => 'hf-env-secret']);
        self::assertNull($secretOnly->getKey('higgsfield'));
        $secretOnlyStatus = $secretOnly->getStatus('higgsfield');
        self::assertFalse($secretOnlyStatus['configured']);
        self::assertSame('env', $secretOnlyStatus['source']);
        self::assertTrue($secretOnlyStatus['hasSecret']);
    }

    public function testFullEnvPairIsImportedAndFollowsSecretRotation(): void
    {
        $store = $this->makeStore(['higgsfield' => 'hf-env-key'], ['higgsfield' => 'hf-env-secret-v1']);

        self::assertSame('hf-env-key', $store->getKey('higgsfield'));
        self::assertSame('hf-env-secret-v1', $store->getSecret('higgsfield'));
        $payload = $this->storedPayload('higgsfield');
        self::assertNotNull($payload);
        self::assertSame('hf-env-secret-v1', $payload['secret'] ?? null);
        self::assertSame(ProviderKeyStore::ORIGIN_ENV, $payload['origin']);

        $status = $store->getStatus('higgsfield');
        self::assertSame('db', $status['source']);
        self::assertTrue($status['hasSecret']);

        // Operator rotates only the secret: the env-origin row follows.
        $rotated = $this->makeStore(['higgsfield' => 'hf-env-key'], ['higgsfield' => 'hf-env-secret-v2']);
        self::assertSame('hf-env-secret-v2', $rotated->getSecret('higgsfield'));
        self::assertSame('hf-env-secret-v2', $this->storedPayload('higgsfield')['secret'] ?? null);
    }

    public function testMediaAndSpeechProvidersAreSupported(): void
    {
        foreach (['thehive', 'higgsfield', 'elevenlabs'] as $provider) {
            self::assertTrue(ProviderKeyStore::isSupported($provider), $provider.' must live in the one key store');
            self::assertTrue(ProviderKeyCatalog::has($provider));
        }

        $store = $this->makeStore(['thehive' => 'hive-env-key']);
        self::assertSame('hive-env-key', $store->getKey('thehive'));
        self::assertNull($store->getSecret('thehive'), 'a single-key provider has no secret half');
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
        foreach (ProviderKeyCatalog::providerNames() as $provider) {
            self::assertTrue(ProviderKeyStore::isSupported($provider), sprintf('ProviderKeyStore cannot store a key for catalog provider "%s"', $provider));
        }
    }

    public function testCatalogDescribesSecretsAndTestability(): void
    {
        self::assertSame('HIGGSFIELD_API_SECRET', ProviderKeyCatalog::secretEnvVarFor('higgsfield'));
        self::assertTrue(ProviderKeyCatalog::requiresSecret('higgsfield'));
        self::assertFalse(ProviderKeyCatalog::requiresSecret('groq'));
        self::assertSame('higgsfield', ProviderKeyCatalog::providerForEnvVar('HIGGSFIELD_API_SECRET'));
        self::assertTrue(ProviderKeyCatalog::isSecretEnvVar('HIGGSFIELD_API_SECRET'));
        self::assertFalse(ProviderKeyCatalog::isSecretEnvVar('HIGGSFIELD_API_KEY'));

        self::assertFalse(ProviderKeyCatalog::isTestable('thehive'), 'no free authenticated endpoint — saved untested');
        self::assertTrue(ProviderKeyCatalog::isTestable('elevenlabs'));

        // Only a real model listing may feed model health / the inventory.
        self::assertTrue(ProviderKeyCatalog::listsModels('groq'));
        foreach (['huggingface', 'higgsfield', 'elevenlabs', 'thehive'] as $provider) {
            self::assertFalse(ProviderKeyCatalog::listsModels($provider), $provider.' must not be read as a model listing');
        }

        self::assertContains('HIGGSFIELD_API_SECRET', ProviderKeyCatalog::managedEnvVars());
        self::assertContains('THEHIVE_API_KEY', ProviderKeyCatalog::managedEnvVars());

        self::assertTrue(ProviderKeyCatalog::servesChat('groq'));
        foreach (['thehive', 'higgsfield', 'elevenlabs'] as $provider) {
            self::assertFalse(ProviderKeyCatalog::servesChat($provider), $provider.' never makes chat ready');
        }
    }

    public function testA2AgentCatalogUsesBearerKeyPlaceholderAndPaidTier(): void
    {
        $meta = ProviderKeyCatalog::get('a2agent');

        self::assertFalse($meta['freeTier']);
        self::assertSame('Bearer {key}', $meta['validation']['headers']['Authorization']);
    }
}
