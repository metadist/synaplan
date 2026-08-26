<?php

namespace App\Tests\Unit;

use App\AI\Interface\ProviderMetadataInterface;
use App\AI\Service\OllamaModelInventory;
use App\AI\Service\ProviderRegistry;
use App\Entity\Config;
use App\Entity\Model;
use App\Repository\ConfigRepository;
use App\Repository\ModelHealthRepository;
use App\Repository\ModelRepository;
use App\Repository\UserRepository;
use App\Service\ModelConfigService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;

class ModelConfigServiceTest extends TestCase
{
    // Intersection types so PHPStan understands that the mocks expose
    // PHPUnit's `expects()`/`method()`. The previous concrete-only typing
    // forced phpstan-baseline.neon to swallow ~50 "undefined method"
    // entries for this file (Copilot review on PR #986).
    private ConfigRepository&MockObject $configRepository;
    private ModelRepository&MockObject $modelRepository;
    private UserRepository&MockObject $userRepository;
    private CacheItemPoolInterface&MockObject $cache;
    private ProviderRegistry&MockObject $providerRegistry;
    private OllamaModelInventory&MockObject $ollamaModelInventory;
    private ModelHealthRepository&MockObject $modelHealthRepository;
    private ModelConfigService $service;
    private CacheItemInterface&MockObject $cacheItem;

    protected function setUp(): void
    {
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->modelRepository = $this->createMock(ModelRepository::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->cache = $this->createMock(CacheItemPoolInterface::class);
        $this->providerRegistry = $this->createMock(ProviderRegistry::class);
        $this->cacheItem = $this->createMock(CacheItemInterface::class);

        // Default: return some available providers for fallback tests
        $this->providerRegistry->method('getAvailableProviders')
            ->willReturn(['openai', 'groq']);

        $this->ollamaModelInventory = $this->createMock(OllamaModelInventory::class);

        $this->modelHealthRepository = $this->createMock(ModelHealthRepository::class);
        $this->modelHealthRepository->method('findOfflineModelIds')->willReturn([]);

        $this->service = new ModelConfigService(
            $this->configRepository,
            $this->modelRepository,
            $this->userRepository,
            $this->cache,
            $this->providerRegistry,
            $this->ollamaModelInventory,
            $this->modelHealthRepository,
            new NullLogger(),
        );
    }

    public function testGetDefaultProviderWithUserSpecificConfig(): void
    {
        $userId = 1;
        $capability = 'chat';
        $expectedProvider = 'openai';

        // Mock cache miss
        $this->cacheItem->method('isHit')->willReturn(false);
        $this->cache->method('getItem')->willReturn($this->cacheItem);

        // Mock user-specific config
        $config = $this->createMock(Config::class);
        $config->method('getValue')->willReturn($expectedProvider);

        $this->configRepository
            ->expects($this->once())
            ->method('findByOwnerGroupAndSetting')
            ->with($userId, 'ai', 'default_chat_provider')
            ->willReturn($config);

        $result = $this->service->getDefaultProvider($userId, $capability);

        $this->assertEquals($expectedProvider, $result);
    }

    public function testGetDefaultProviderWithGlobalConfig(): void
    {
        $userId = 1;
        $capability = 'chat';
        $expectedProvider = 'claude';

        // Mock cache miss
        $this->cacheItem->method('isHit')->willReturn(false);
        $this->cache->method('getItem')->willReturn($this->cacheItem);

        // Mock no user-specific config, but global config exists
        $this->configRepository
            ->expects($this->exactly(2))
            ->method('findByOwnerGroupAndSetting')
            ->willReturnCallback(function ($ownerId, $group, $setting) use ($expectedProvider) {
                if (0 === $ownerId) {
                    $config = $this->createMock(Config::class);
                    $config->method('getValue')->willReturn($expectedProvider);

                    return $config;
                }

                return null;
            });

        $result = $this->service->getDefaultProvider($userId, $capability);

        $this->assertEquals($expectedProvider, $result);
    }

    public function testGetDefaultProviderFallback(): void
    {
        $userId = 1;
        $capability = 'chat';

        // Mock cache miss
        $this->cacheItem->method('isHit')->willReturn(false);
        $this->cache->method('getItem')->willReturn($this->cacheItem);

        // Mock no config found
        $this->configRepository
            ->method('findByOwnerGroupAndSetting')
            ->willReturn(null);

        $result = $this->service->getDefaultProvider($userId, $capability);

        $this->assertEquals('test', $result);
    }

    public function testGetDefaultProviderFromCache(): void
    {
        $userId = 1;
        $capability = 'chat';
        $cachedProvider = 'cached_openai';

        // Mock cache hit
        $this->cacheItem->method('isHit')->willReturn(true);
        $this->cacheItem->method('get')->willReturn($cachedProvider);
        $this->cache->method('getItem')->willReturn($this->cacheItem);

        // Should not call repository
        $this->configRepository
            ->expects($this->never())
            ->method('findByOwnerGroupAndSetting');

        $result = $this->service->getDefaultProvider($userId, $capability);

        $this->assertEquals($cachedProvider, $result);
    }

    public function testGetDefaultModelWithUserConfig(): void
    {
        $capability = 'CHAT';
        $userId = 1;
        $expectedModelId = 42;

        $this->givenModels([$expectedModelId => 'Ollama']);

        // Mock user-specific config
        $config = $this->createMock(Config::class);
        $config->method('getValue')->willReturn((string) $expectedModelId);

        $this->configRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with([
                'ownerId' => $userId,
                'group' => 'DEFAULTMODEL',
                'setting' => 'CHAT',
            ])
            ->willReturn($config);

        $result = $this->service->getDefaultModel($capability, $userId);

        $this->assertEquals($expectedModelId, $result);
    }

    public function testGetDefaultModelWithGlobalConfig(): void
    {
        $capability = 'CHAT';
        $userId = 1;
        $expectedModelId = 99;

        // Mock no user config, but global config exists
        $globalConfig = $this->createMock(Config::class);
        $globalConfig->method('getValue')->willReturn((string) $expectedModelId);

        $this->configRepository
            ->expects($this->exactly(2))
            ->method('findOneBy')
            ->willReturnCallback(function ($criteria) use ($globalConfig) {
                if (0 === $criteria['ownerId']) {
                    return $globalConfig;
                }

                return null;
            });

        $result = $this->service->getDefaultModel($capability, $userId);

        $this->assertEquals($expectedModelId, $result);
    }

    public function testGetDefaultModelReturnsNullWhenNotFound(): void
    {
        $capability = 'CHAT';
        $userId = 1;

        $this->configRepository
            ->method('findOneBy')
            ->willReturn(null);

        $result = $this->service->getDefaultModel($capability, $userId);

        $this->assertNull($result);
    }

    /**
     * The failure this guards against: an install whose first API key arrives
     * AFTER the first account was created. resetUserDefaults() has already
     * written the code-recommended per-user bindings, the key-save path repairs
     * only the global row, and the user is left pointed at a provider they
     * never configured — chat fails and the setup banner keeps asking for a
     * provider that is plainly connected.
     */
    public function testGetDefaultModelSkipsAUserOverrideWhoseProviderHasNoKey(): void
    {
        $this->givenModels([249 => 'Anthropic', 9 => 'Groq']);
        $this->givenUsableProviders(['groq']);
        $this->givenDefaultModelRows([1 => 249, 0 => 9]);

        self::assertSame(9, $this->service->getDefaultModel('CHAT', 1));
    }

    public function testGetDefaultModelKeepsAUserOverrideWhoseProviderHasAKey(): void
    {
        $this->givenModels([249 => 'Anthropic', 9 => 'Groq']);
        $this->givenUsableProviders(['anthropic', 'groq']);
        $this->givenDefaultModelRows([1 => 249, 0 => 9]);

        self::assertSame(249, $this->service->getDefaultModel('CHAT', 1));
    }

    /**
     * Nothing is usable, so the global default is no better than the user's own
     * binding. Keep the configured one rather than shuffling to an equally
     * dead alternative.
     */
    public function testGetDefaultModelKeepsTheUserOverrideWhenTheGlobalDefaultIsAlsoUnusable(): void
    {
        $this->givenModels([249 => 'Anthropic', 255 => 'OpenAI']);
        $this->givenUsableProviders(['groq']);
        $this->givenDefaultModelRows([1 => 249, 0 => 255]);

        self::assertSame(249, $this->service->getDefaultModel('CHAT', 1));
    }

    /**
     * An empty registry means "cannot tell", not "nothing works" — a bare
     * ProviderRegistry must never be the reason a configured default is
     * replaced.
     */
    public function testGetDefaultModelKeepsTheUserOverrideWhenNoProviderIsRegistered(): void
    {
        $this->givenModels([249 => 'Anthropic', 9 => 'Groq']);
        $this->givenUsableProviders([]);
        $this->givenDefaultModelRows([1 => 249, 0 => 9]);

        self::assertSame(249, $this->service->getDefaultModel('CHAT', 1));
    }

    /**
     * The test catalog binds capabilities to negative placeholder BIDs that
     * have no BMODELS row. Those mean "let the provider registry decide" and
     * must resolve unchanged.
     */
    public function testGetDefaultModelKeepsAnOverridePointingAtAPlaceholderId(): void
    {
        $this->givenModels([]);
        $this->givenDefaultModelRows([1 => -1, 0 => 9]);

        self::assertSame(-1, $this->service->getDefaultModel('CHAT', 1));
    }

    /**
     * A positive BID with no row is a deleted model, not a placeholder. Passing
     * it on leaves the caller with a model id but no provider and no model
     * name, so the registry quietly answers from its own default — the user
     * gets a different model than the one configured and nothing says so.
     */
    public function testGetDefaultModelSkipsABindingWhoseModelRowIsGone(): void
    {
        $this->givenModels([255 => 'OpenAI']);
        $this->givenUsableProviders(['openai']);
        $this->givenDefaultModelRows([1 => 9, 0 => 255]);

        self::assertSame(255, $this->service->getDefaultModel('CHAT', 1));
    }

    public function testResolveUsableModelIdSwapsAnOverrideWhoseModelRowIsGone(): void
    {
        $this->givenModels([255 => 'OpenAI']);
        $this->givenUsableProviders(['openai']);
        $this->givenDefaultModelRows([0 => 255]);

        self::assertSame(255, $this->service->resolveUsableModelId(9, 'CHAT', 1));
    }

    /**
     * Ollama reports itself available as soon as the server answers, which a
     * stock install does while holding nothing but the embedding model. Falling
     * back to a local model nobody downloaded would trade one dead binding for
     * another — and discard the user's choice on the way.
     */
    public function testGetDefaultModelKeepsTheUserOverrideWhenTheGlobalOllamaModelIsNotPulled(): void
    {
        $this->givenModels([249 => 'Anthropic', 77 => 'Ollama'], [77 => 'llama3']);
        $this->givenUsableProviders(['ollama']);
        $this->givenDefaultModelRows([1 => 249, 0 => 77]);

        $this->ollamaModelInventory->method('isPulled')->with('llama3')->willReturn(false);

        self::assertSame(249, $this->service->getDefaultModel('CHAT', 1));
    }

    public function testGetDefaultModelFallsBackToAnOllamaModelThatIsPulled(): void
    {
        $this->givenModels([249 => 'Anthropic', 77 => 'Ollama'], [77 => 'llama3']);
        $this->givenUsableProviders(['ollama']);
        $this->givenDefaultModelRows([1 => 249, 0 => 77]);

        $this->ollamaModelInventory->method('isPulled')->with('llama3')->willReturn(true);

        self::assertSame(77, $this->service->getDefaultModel('CHAT', 1));
    }

    /**
     * The mirror image: a user who deliberately bound chat to a local model
     * keeps it, and only loses it while that model is absent.
     */
    public function testGetDefaultModelSkipsAUserOverrideWhoseOllamaModelIsNotPulled(): void
    {
        $this->givenModels([77 => 'Ollama', 9 => 'Groq'], [77 => 'llama3']);
        $this->givenUsableProviders(['ollama', 'groq']);
        $this->givenDefaultModelRows([1 => 77, 0 => 9]);

        $this->ollamaModelInventory->method('isPulled')->with('llama3')->willReturn(false);

        self::assertSame(9, $this->service->getDefaultModel('CHAT', 1));
    }

    /**
     * The production failure this guards against: Groq shut down
     * llama-3.3-70b-versatile (BID 9), Version20260819080000 deactivated the
     * row, and every account still bound to it kept sending the dead upstream
     * id — one hard "model_not_found" per message, including for anonymous
     * visitors on the guest path.
     */
    public function testGetDefaultModelSkipsADeactivatedUserOverride(): void
    {
        $this->givenModels([9 => 'Groq', 255 => 'OpenAI'], [], inactiveModelIds: [9]);
        $this->givenUsableProviders(['groq', 'openai']);
        $this->givenDefaultModelRows([1 => 9, 0 => 255]);

        self::assertSame(255, $this->service->getDefaultModel('CHAT', 1));
    }

    /**
     * The global row used to be returned unchecked, so a whole install could
     * sit on a retired model with no per-user binding to save it.
     */
    public function testGetDefaultModelSkipsADeactivatedGlobalBinding(): void
    {
        $this->givenModels([9 => 'Groq', 324 => 'Groq'], [], inactiveModelIds: [9]);
        $this->givenUsableProviders(['groq']);
        $this->givenDefaultModelRows([0 => 9]);
        $this->givenCapabilityCatalog('chat', [324 => 'Groq']);

        self::assertSame(324, $this->service->getDefaultModel('CHAT', 1));
    }

    /**
     * Both bindings dead: rather than fail the request, pick a live model of
     * the same capability.
     */
    public function testGetDefaultModelFallsBackToALiveModelWhenEveryBindingIsDeactivated(): void
    {
        $this->givenModels([9 => 'Groq', 17 => 'Groq', 324 => 'Groq'], [], inactiveModelIds: [9, 17]);
        $this->givenUsableProviders(['groq']);
        $this->givenDefaultModelRows([1 => 9, 0 => 17]);
        $this->givenCapabilityCatalog('chat', [324 => 'Groq']);

        self::assertSame(324, $this->service->getDefaultModel('CHAT', 1));
    }

    /**
     * No live candidate either — keep reporting the configured binding instead
     * of returning null, so callers can still name the model they meant to use.
     */
    public function testGetDefaultModelKeepsTheDeadBindingWhenNoLiveModelExists(): void
    {
        $this->givenModels([9 => 'Groq'], [], inactiveModelIds: [9]);
        $this->givenUsableProviders(['groq']);
        $this->givenDefaultModelRows([1 => 9]);

        self::assertSame(9, $this->service->getDefaultModel('CHAT', 1));
    }

    /**
     * A widget's aiModelId or a prompt's aiModel override is read AHEAD of the
     * default, so it has to be revalidated on its own.
     */
    public function testResolveUsableModelIdSwapsADeactivatedOverrideForTheDefault(): void
    {
        $this->givenModels([9 => 'Groq', 255 => 'OpenAI'], [], inactiveModelIds: [9]);
        $this->givenUsableProviders(['groq', 'openai']);
        $this->givenDefaultModelRows([0 => 255]);

        self::assertSame(255, $this->service->resolveUsableModelId(9, 'CHAT', 1));
    }

    public function testResolveUsableModelIdKeepsAnOverrideThatStillWorks(): void
    {
        $this->givenModels([255 => 'OpenAI']);
        $this->givenUsableProviders(['openai']);

        self::assertSame(255, $this->service->resolveUsableModelId(255, 'CHAT', 1));
    }

    /**
     * Nothing to validate — a caller that never picked a model must not be
     * handed one behind its back.
     */
    public function testResolveUsableModelIdPassesNullThrough(): void
    {
        $this->givenModels([]);

        self::assertNull($this->service->resolveUsableModelId(null, 'CHAT', 1));
    }

    /**
     * @param array<int, string> $servicesByModelId
     * @param array<int, string> $providerIdsByModelId
     * @param list<int>          $inactiveModelIds     BIDs to hand back with BACTIVE = 0
     */
    private function givenModels(
        array $servicesByModelId,
        array $providerIdsByModelId = [],
        array $inactiveModelIds = [],
    ): void {
        $this->modelRepository
            ->method('find')
            ->willReturnCallback(function (int $modelId) use ($servicesByModelId, $providerIdsByModelId, $inactiveModelIds): ?Model {
                if (!isset($servicesByModelId[$modelId])) {
                    return null;
                }

                $model = $this->createMock(Model::class);
                $model->method('getService')->willReturn($servicesByModelId[$modelId]);
                $model->method('getProviderId')->willReturn($providerIdsByModelId[$modelId] ?? '');
                $model->method('getActive')->willReturn(in_array($modelId, $inactiveModelIds, true) ? 0 : 1);

                return $model;
            });
    }

    /**
     * Catalog rows the last-resort capability pick can choose from. Without
     * this, findByTag() returns an empty list and getDefaultModel() falls
     * through to the configured binding.
     *
     * @param array<int, string> $servicesByModelId
     */
    private function givenCapabilityCatalog(string $tag, array $servicesByModelId): void
    {
        $models = [];
        foreach ($servicesByModelId as $modelId => $service) {
            $model = $this->createMock(Model::class);
            $model->method('getId')->willReturn($modelId);
            $model->method('getService')->willReturn($service);
            $model->method('getProviderId')->willReturn('');
            $model->method('getActive')->willReturn(1);
            $models[] = $model;
        }

        $this->modelRepository
            ->method('findByTag')
            ->willReturnCallback(static fn (string $requested): array => $requested === $tag ? $models : []);
    }

    /**
     * @param list<string> $names
     * @param list<string> $unavailable registered providers that currently have no credentials
     */
    private function givenUsableProviders(array $names, array $unavailable = []): void
    {
        $providers = [];
        foreach ($names as $name) {
            $provider = $this->createMock(ProviderMetadataInterface::class);
            $provider->method('getName')->willReturn($name);
            $provider->method('isAvailable')->willReturn(true);
            $providers[$name] = $provider;
        }
        foreach ($unavailable as $name) {
            $provider = $this->createMock(ProviderMetadataInterface::class);
            $provider->method('getName')->willReturn($name);
            $provider->method('isAvailable')->willReturn(false);
            $providers[$name] = $provider;
        }

        $this->providerRegistry->method('getUniqueProviders')->willReturn($providers);

        // The snapshot is cached; these tests want it computed every time.
        $this->cacheItem->method('isHit')->willReturn(false);
        $this->cache->method('getItem')->willReturn($this->cacheItem);
    }

    /**
     * @param array<int, int> $modelIdByOwnerId
     */
    private function givenDefaultModelRows(array $modelIdByOwnerId): void
    {
        $this->configRepository
            ->method('findOneBy')
            ->willReturnCallback(function (array $criteria) use ($modelIdByOwnerId): ?Config {
                $modelId = $modelIdByOwnerId[$criteria['ownerId']] ?? null;
                if (null === $modelId) {
                    return null;
                }

                $config = $this->createMock(Config::class);
                $config->method('getValue')->willReturn((string) $modelId);

                return $config;
            });
    }

    public function testGetProviderForModel(): void
    {
        $modelId = 5;
        $expectedProvider = 'OpenAI';

        $model = $this->createMock(Model::class);
        $model->method('getService')->willReturn($expectedProvider);

        $this->modelRepository
            ->expects($this->once())
            ->method('find')
            ->with($modelId)
            ->willReturn($model);

        $result = $this->service->getProviderForModel($modelId);

        $this->assertEquals('openai', $result); // lowercased
    }

    public function testGetProviderForModelReturnsNullWhenNotFound(): void
    {
        $modelId = 999;

        $this->modelRepository
            ->expects(self::any())
            ->method('find')
            ->with($modelId)
            ->willReturn(null);

        $result = $this->service->getProviderForModel($modelId);

        $this->assertNull($result);
    }

    public function testGetModelName(): void
    {
        $modelId = 10;
        $expectedProviderId = 'gpt-4';

        $model = $this->createMock(Model::class);
        $model->method('getProviderId')->willReturn($expectedProviderId);

        $this->modelRepository
            ->expects($this->once())
            ->method('find')
            ->with($modelId)
            ->willReturn($model);

        $result = $this->service->getModelName($modelId);

        $this->assertEquals($expectedProviderId, $result);
    }

    public function testGetModelNameFallsBackToName(): void
    {
        $modelId = 10;
        $expectedName = 'GPT-4 Model';

        $model = $this->createMock(Model::class);
        $model->method('getProviderId')->willReturn(''); // empty provider ID
        $model->method('getName')->willReturn($expectedName);

        $this->modelRepository
            ->expects(self::any())
            ->method('find')
            ->with($modelId)
            ->willReturn($model);

        $result = $this->service->getModelName($modelId);

        $this->assertEquals($expectedName, $result);
    }

    public function testGetModelNameReturnsNullWhenNotFound(): void
    {
        $modelId = 999;

        $this->modelRepository
            ->expects(self::any())
            ->method('find')
            ->with($modelId)
            ->willReturn(null);

        $result = $this->service->getModelName($modelId);

        $this->assertNull($result);
    }

    public function testSetDefaultProviderCreatesNewConfig(): void
    {
        $userId = 5;
        $capability = 'chat';
        $provider = 'claude';

        $this->configRepository
            ->expects($this->once())
            ->method('findByOwnerGroupAndSetting')
            ->with($userId, 'ai', 'default_chat_provider')
            ->willReturn(null);

        $this->configRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function ($config) use ($provider) {
                return $config instanceof Config
                       && $config->getValue() === $provider;
            }));

        $this->cache
            ->expects($this->once())
            ->method('deleteItem')
            ->with("model_config.provider.{$userId}.{$capability}");

        $this->service->setDefaultProvider($userId, $capability, $provider);
    }

    public function testSetDefaultProviderUpdatesExistingConfig(): void
    {
        $userId = 5;
        $capability = 'chat';
        $provider = 'openai';

        $existingConfig = $this->createMock(Config::class);
        $existingConfig
            ->expects($this->once())
            ->method('setValue')
            ->with($provider);

        $this->configRepository
            ->method('findByOwnerGroupAndSetting')
            ->willReturn($existingConfig);

        $this->configRepository
            ->expects($this->once())
            ->method('save');

        $this->service->setDefaultProvider($userId, $capability, $provider);
    }

    public function testGetUserAiConfig(): void
    {
        // Mock cache miss
        $this->cacheItem->method('isHit')->willReturn(false);
        $this->cache->method('getItem')->willReturn($this->cacheItem);

        // Mock provider config
        $chatConfig = $this->createMock(Config::class);
        $chatConfig->method('getValue')->willReturn('openai');

        $visionConfig = $this->createMock(Config::class);
        $visionConfig->method('getValue')->willReturn('anthropic');

        $this->configRepository
            ->method('findByOwnerGroupAndSetting')
            ->willReturnCallback(function ($ownerId, $group, $setting) use ($chatConfig, $visionConfig) {
                if (str_contains($setting, 'chat')) {
                    return $chatConfig;
                }
                if (str_contains($setting, 'vision')) {
                    return $visionConfig;
                }

                return null;
            });

        $result = $this->service->getUserAiConfig(1);

        $this->assertArrayHasKey('chat', $result);
        $this->assertArrayHasKey('vision', $result);
        $this->assertArrayHasKey('embedding', $result);
        $this->assertEquals('openai', $result['chat']['provider']);
    }

    public function testGetUserAiConfigFallsBackWhenPic2TextModelRowIsMissing(): void
    {
        // Cache miss for the chat/embedding getDefaultProvider lookups.
        $this->cacheItem->method('isHit')->willReturn(false);
        $this->cache->method('getItem')->willReturn($this->cacheItem);

        // DEFAULTMODEL.PIC2TEXT points at id 999 — but that row was deleted.
        $picTextConfig = $this->createMock(Config::class);
        $picTextConfig->method('getValue')->willReturn('999');

        $this->configRepository
            ->method('findOneBy')
            ->willReturnCallback(function (array $criteria) use ($picTextConfig) {
                if (($criteria['group'] ?? null) === 'DEFAULTMODEL'
                    && ($criteria['setting'] ?? null) === 'PIC2TEXT') {
                    return $picTextConfig;
                }

                return null;
            });

        // Stale: model row no longer exists → provider lookup returns null.
        $this->modelRepository->expects(self::any())->method('find')->with(999)->willReturn(null);

        // Vision falls through to the capability default chain. There's no
        // BCONFIG row, so it walks through findFallbackProvider() and lands
        // on the first available provider from the registry mock.
        $this->configRepository
            ->method('findByOwnerGroupAndSetting')
            ->willReturn(null);

        // First available provider from setUp() is 'openai'; ensure modelRepository->findByTag
        // returns a model owned by openai so findFallbackProvider returns it.
        $openAiModel = $this->createMock(Model::class);
        $openAiModel->method('getService')->willReturn('openai');
        $this->modelRepository->method('findByTag')->willReturn([$openAiModel]);

        $result = $this->service->getUserAiConfig(1);

        $this->assertSame('openai', $result['vision']['provider']);
        $this->assertNull(
            $result['vision']['model'],
            'Stale PIC2TEXT model id must be nulled out when the referenced row is gone'
        );
    }

    public function testGetEffectiveUserIdForMessageWithWhatsAppUnverifiedUser(): void
    {
        $userId = 1;

        // Mock message with WhatsApp channel
        $message = $this->createMock(\App\Entity\Message::class);
        $message->method('getUserId')->willReturn($userId);
        $message->expects(self::any())->method('getMeta')->with('channel')->willReturn('whatsapp');

        // Mock user without verified phone
        $user = $this->createMock(\App\Entity\User::class);
        $user->method('hasVerifiedPhone')->willReturn(false);

        $this->userRepository
            ->expects($this->once())
            ->method('find')
            ->with($userId)
            ->willReturn($user);

        $result = $this->service->getEffectiveUserIdForMessage($message);

        $this->assertNull($result, 'Unverified WhatsApp users should return null');
    }

    public function testGetEffectiveUserIdForMessageWithWhatsAppVerifiedUser(): void
    {
        $userId = 5;

        // Mock message with WhatsApp channel
        $message = $this->createMock(\App\Entity\Message::class);
        $message->method('getUserId')->willReturn($userId);
        $message->expects(self::any())->method('getMeta')->with('channel')->willReturn('whatsapp');

        // Mock user with verified phone
        $user = $this->createMock(\App\Entity\User::class);
        $user->method('hasVerifiedPhone')->willReturn(true);

        $this->userRepository
            ->expects($this->once())
            ->method('find')
            ->with($userId)
            ->willReturn($user);

        $result = $this->service->getEffectiveUserIdForMessage($message);

        $this->assertEquals($userId, $result, 'Verified WhatsApp users should return their userId');
    }

    public function testGetEffectiveUserIdForMessageWithWebChannel(): void
    {
        $userId = 10;

        // Mock message with web channel (not WhatsApp)
        $message = $this->createMock(\App\Entity\Message::class);
        $message->method('getUserId')->willReturn($userId);
        $message->expects(self::any())->method('getMeta')->with('channel')->willReturn('web');

        // Should not check user repository for non-WhatsApp channels
        $this->userRepository
            ->expects($this->never())
            ->method('find');

        $result = $this->service->getEffectiveUserIdForMessage($message);

        $this->assertEquals($userId, $result, 'Web channel should always return userId');
    }

    public function testGetEffectiveUserIdForMessageWithEmailChannel(): void
    {
        $userId = 15;

        // Mock message with email channel and keyword (smart+keyword@synaplan.net)
        $message = $this->createMock(\App\Entity\Message::class);
        $message->method('getUserId')->willReturn($userId);
        $message->method('getMeta')
            ->willReturnCallback(function ($key) {
                if ('channel' === $key) {
                    return 'email';
                }
                if ('email_keyword' === $key) {
                    return 'keyword'; // Has keyword, so should use sender's userId
                }

                return null;
            });

        // Should not check user repository for email channels
        $this->userRepository
            ->expects($this->never())
            ->method('find');

        $result = $this->service->getEffectiveUserIdForMessage($message);

        $this->assertEquals($userId, $result, 'Email channel with keyword should return sender userId');
    }

    public function testGetEffectiveUserIdForMessageWithEmailChannelNoKeyword(): void
    {
        // Regression test for issue #1176: a keyword-less email from an
        // identified sender (smart@synaplan.net) must use the SENDER'S own
        // user id for model selection — same as web chat — not the legacy
        // hardcoded user ID 2.
        $message = $this->createMock(\App\Entity\Message::class);
        $message->method('getUserId')->willReturn(20);
        $message->method('getMeta')
            ->willReturnCallback(function ($key) {
                if ('channel' === $key) {
                    return 'email';
                }
                if ('email_keyword' === $key) {
                    return null; // No keyword (smart@synaplan.net)
                }

                return null;
            });

        // Should not check user repository for email channels
        $this->userRepository
            ->expects($this->never())
            ->method('find');

        $result = $this->service->getEffectiveUserIdForMessage($message);

        $this->assertEquals(
            20,
            $result,
            'Email channel without keyword must return the sender\'s own userId (issue #1176)'
        );
    }

    public function testGetEffectiveUserIdForMessageWithNullChannel(): void
    {
        $userId = 20;

        // Mock message with null channel (default to non-WhatsApp behavior)
        $message = $this->createMock(\App\Entity\Message::class);
        $message->method('getUserId')->willReturn($userId);
        $message->expects(self::any())->method('getMeta')->with('channel')->willReturn(null);

        // Should not check user repository for null channel
        $this->userRepository
            ->expects($this->never())
            ->method('find');

        $result = $this->service->getEffectiveUserIdForMessage($message);

        $this->assertEquals($userId, $result, 'Null channel should always return userId');
    }

    public function testGetEffectiveUserIdForMessageWithUserIdZero(): void
    {
        // Mock message with userId = 0 (anonymous/system user)
        $message = $this->createMock(\App\Entity\Message::class);
        $message->method('getUserId')->willReturn(0);

        // Should not call getMeta or find when userId is 0
        $message->expects($this->never())->method('getMeta');
        $this->userRepository->expects($this->never())->method('find');

        $result = $this->service->getEffectiveUserIdForMessage($message);

        $this->assertNull($result, 'userId = 0 should return null (anonymous/system user)');
    }

    public function testGetEffectiveUserIdForMessageWithUserNotFound(): void
    {
        $userId = 999;

        // Mock message with WhatsApp channel
        $message = $this->createMock(\App\Entity\Message::class);
        $message->method('getUserId')->willReturn($userId);
        $message->expects(self::any())->method('getMeta')->with('channel')->willReturn('whatsapp');

        // Mock user not found in repository
        $this->userRepository
            ->expects($this->once())
            ->method('find')
            ->with($userId)
            ->willReturn(null);

        $result = $this->service->getEffectiveUserIdForMessage($message);

        $this->assertNull($result, 'User not found in database should return null');
    }

    /**
     * Regression test for issue #973.
     *
     * The "New Memory" UI parse endpoint (UserMemoryController::parseMemory)
     * and the async MemoryExtractionService MUST share the same MEM → CHAT
     * fallback chain. Otherwise admins who configure a cheap MEM model see
     * the UI silently fall back to the (expensive) CHAT default.
     */
    public function testGetMemoryModelConfigPrefersUserMemOverGlobalMemAndChat(): void
    {
        $userId = 42;
        $userMemModelId = 220;

        $userMemConfig = $this->createMock(Config::class);
        $userMemConfig->method('getValue')->willReturn((string) $userMemModelId);

        // findOneBy should be hit exactly once — the very first MEM lookup wins.
        $this->configRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with([
                'ownerId' => $userId,
                'group' => 'DEFAULTMODEL',
                'setting' => 'MEM',
            ])
            ->willReturn($userMemConfig);

        $model = $this->createMock(Model::class);
        $model->method('getService')->willReturn('Groq');
        $model->method('getProviderId')->willReturn('gpt-oss-120b');
        $model->method('getActive')->willReturn(1);

        $this->modelRepository
            ->expects(self::any())
            ->method('find')
            ->with($userMemModelId)
            ->willReturn($model);

        $result = $this->service->getMemoryModelConfig($userId);

        $this->assertSame([
            'model' => 'gpt-oss-120b',
            'provider' => 'groq',
            'model_id' => $userMemModelId,
        ], $result);
    }

    public function testGetMemoryModelConfigFallsThroughGlobalMemUserChatToGlobalChat(): void
    {
        $userId = 7;
        $globalChatModelId = 160;

        $globalChatConfig = $this->createMock(Config::class);
        $globalChatConfig->method('getValue')->willReturn((string) $globalChatModelId);

        // Walk the full chain: user MEM → global MEM → user CHAT → global CHAT.
        // Only the very last lookup returns a config.
        $this->configRepository
            ->expects($this->exactly(4))
            ->method('findOneBy')
            ->willReturnCallback(
                function (array $criteria) use ($userId, $globalChatConfig) {
                    static $calls = 0;
                    ++$calls;

                    $expectedSequence = [
                        ['ownerId' => $userId, 'group' => 'DEFAULTMODEL', 'setting' => 'MEM'],
                        ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'MEM'],
                        ['ownerId' => $userId, 'group' => 'DEFAULTMODEL', 'setting' => 'CHAT'],
                        ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'CHAT'],
                    ];

                    self::assertSame(
                        $expectedSequence[$calls - 1],
                        $criteria,
                        "Fallback chain step {$calls} called with unexpected criteria"
                    );

                    return 4 === $calls ? $globalChatConfig : null;
                }
            );

        $model = $this->createMock(Model::class);
        $model->method('getService')->willReturn('Anthropic');
        $model->method('getProviderId')->willReturn('claude-opus-4-6');

        $this->modelRepository
            ->expects(self::any())
            ->method('find')
            ->with($globalChatModelId)
            ->willReturn($model);

        $result = $this->service->getMemoryModelConfig($userId);

        $this->assertSame([
            'model' => 'claude-opus-4-6',
            'provider' => 'anthropic',
            'model_id' => $globalChatModelId,
        ], $result);
    }

    public function testGetMemoryModelConfigReturnsNullsWhenNothingConfigured(): void
    {
        $this->configRepository
            ->method('findOneBy')
            ->willReturn(null);

        $this->modelRepository
            ->expects($this->never())
            ->method('find');

        $this->assertSame(
            ['model' => null, 'provider' => null, 'model_id' => null],
            $this->service->getMemoryModelConfig(99)
        );
    }

    /**
     * The rolling conversation summarizer must honour an explicit
     * DEFAULTMODEL.SUMMARIZE override before anything else — this is how an
     * operator points the condensing step at e.g. a GPT-OSS-120B model.
     * (#1320: key is SUMMARIZE end to end — seeder, reader, ChatRunner.).
     */
    public function testGetSummaryModelConfigPrefersExplicitSummaryModel(): void
    {
        $userId = 5;
        $summaryModelId = 300;

        $summaryConfig = $this->createMock(Config::class);
        $summaryConfig->method('getValue')->willReturn((string) $summaryModelId);

        // First lookup (user SUMMARIZE) wins — no fallback lookups happen.
        $this->configRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with([
                'ownerId' => $userId,
                'group' => 'DEFAULTMODEL',
                'setting' => 'SUMMARIZE',
            ])
            ->willReturn($summaryConfig);

        $model = $this->createMock(Model::class);
        $model->method('getService')->willReturn('Groq');
        $model->method('getProviderId')->willReturn('gpt-oss-120b');
        $model->method('getActive')->willReturn(1);

        $this->modelRepository
            ->expects(self::any())
            ->method('find')
            ->with($summaryModelId)
            ->willReturn($model);

        $this->assertSame([
            'model' => 'gpt-oss-120b',
            'provider' => 'groq',
            'model_id' => $summaryModelId,
        ], $this->service->getSummaryModelConfig($userId));
    }

    /**
     * With no SUMMARIZE override the summarizer defaults to the sorting (SORT)
     * model — the cheap/fast model requested for condensing by default.
     */
    public function testGetSummaryModelConfigFallsBackToSortModel(): void
    {
        $userId = 9;
        $sortModelId = 73;

        $sortConfig = $this->createMock(Config::class);
        $sortConfig->method('getValue')->willReturn((string) $sortModelId);

        // Chain: user SUMMARIZE → global SUMMARIZE → user SORT (returns here).
        $this->configRepository
            ->expects($this->exactly(3))
            ->method('findOneBy')
            ->willReturnCallback(function (array $criteria) use ($userId, $sortConfig) {
                static $calls = 0;
                ++$calls;

                $expected = [
                    ['ownerId' => $userId, 'group' => 'DEFAULTMODEL', 'setting' => 'SUMMARIZE'],
                    ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'SUMMARIZE'],
                    ['ownerId' => $userId, 'group' => 'DEFAULTMODEL', 'setting' => 'SORT'],
                ];

                self::assertSame($expected[$calls - 1], $criteria, "Summary fallback step {$calls}");

                return 3 === $calls ? $sortConfig : null;
            });

        $model = $this->createMock(Model::class);
        $model->method('getService')->willReturn('Groq');
        $model->method('getProviderId')->willReturn('qwen/qwen3.6-27b');
        $model->method('getActive')->willReturn(1);

        $this->modelRepository
            ->expects(self::any())
            ->method('find')
            ->with($sortModelId)
            ->willReturn($model);

        $this->assertSame([
            'model' => 'qwen/qwen3.6-27b',
            'provider' => 'groq',
            'model_id' => $sortModelId,
        ], $this->service->getSummaryModelConfig($userId));
    }

    public function testGetSummaryModelConfigReturnsNullsWhenNothingConfigured(): void
    {
        $this->configRepository
            ->method('findOneBy')
            ->willReturn(null);

        $this->modelRepository
            ->expects($this->never())
            ->method('find');

        $this->assertSame(
            ['model' => null, 'provider' => null, 'model_id' => null],
            $this->service->getSummaryModelConfig(3)
        );
    }

    public function testGetEffectiveUserIdForMessageWithWebChannelUnverifiedUser(): void
    {
        $userId = 25;

        // Mock message with web channel
        $message = $this->createMock(\App\Entity\Message::class);
        $message->method('getUserId')->willReturn($userId);
        $message->expects(self::any())->method('getMeta')->with('channel')->willReturn('web');

        // User verification should not be checked for web channel
        $this->userRepository
            ->expects($this->never())
            ->method('find');

        $result = $this->service->getEffectiveUserIdForMessage($message);

        $this->assertEquals(
            $userId,
            $result,
            'Web channel should return userId regardless of phone verification status'
        );
    }

    public function testInitializeNewUserDefaultsDoesNotWritePerUserRows(): void
    {
        $this->configRepository->expects($this->never())->method('findBy');
        $this->configRepository->expects($this->never())->method('setValue');
        $this->configRepository->expects($this->never())->method('removeAll');

        $this->service->initializeNewUserDefaults(42);
    }

    public function testResetUserDefaultsWritesOnlyUsableRecommendedModels(): void
    {
        $recommended = \App\Seed\DefaultModelConfigSeeder::getRecommendedDefaults();
        $servicesById = [];
        $keyToService = [
            'anthropic:claude-sonnet-5:chat' => 'Anthropic',
            'groq:openai/gpt-oss-120b:chat' => 'Groq',
            'groq:openai/gpt-oss-120b:mem' => 'Groq',
            'google:gemini-3.1-flash-image-preview:text2pic' => 'Google',
            'google:veo-3.1-generate-preview:text2vid' => 'Google',
            'higgsfield:higgsfield-ai/dop/standard:text2vid' => 'Higgsfield',
            'google:gemini-2.5-flash-preview-tts:text2sound' => 'Google',
            'groq:qwen/qwen3.6-27b:pic2text' => 'Groq',
            'groq:whisper-large-v3:sound2text' => 'Groq',
            'ollama:bge-m3:vectorize' => 'Ollama',
        ];
        foreach ($keyToService as $key => $service) {
            $bid = \App\Model\ModelCatalog::findBidByKey($key);
            $this->assertNotNull($bid, "catalog key $key must resolve");
            $servicesById[$bid] = $service;
        }

        $this->givenModels($servicesById);
        $this->givenUsableProviders(['groq']);
        $this->modelRepository->method('findByTag')->willReturn([]);

        $this->configRepository->method('findBy')->willReturn([]);
        $this->configRepository->expects($this->once())->method('removeAll')->with([]);

        $written = [];
        $this->configRepository
            ->expects($this->atLeastOnce())
            ->method('setValue')
            ->willReturnCallback(function (int $ownerId, string $group, string $setting, string $value) use (&$written): Config {
                $this->assertSame(7, $ownerId);
                $this->assertSame('DEFAULTMODEL', $group);
                $written[$setting] = (int) $value;

                return $this->createMock(Config::class);
            });

        $result = $this->service->resetUserDefaults(7);

        $this->assertSame($written, $result['defaults']);
        $this->assertArrayNotHasKey('VECTORIZE', $written);
        $this->assertArrayNotHasKey('CHAT', $written, 'Anthropic CHAT must not be frozen when the provider has no key');
        $this->assertArrayNotHasKey('TEXT2PIC', $written, 'Google image models must not be written without a key');
        $this->assertArrayHasKey('SORT', $written);
        $this->assertSame($recommended['SORT'], $written['SORT']);
        $this->assertArrayHasKey('SOUND2TEXT', $written);
        $this->assertSame($recommended['SOUND2TEXT'], $written['SOUND2TEXT']);
        foreach ($written as $capability => $modelId) {
            $this->assertSame('Groq', $servicesById[$modelId], "$capability must resolve to a Groq model");
        }
    }

    /**
     * isModelUsable() treats an empty usable list as "cannot tell". On a
     * real install the registry is populated and [] means every provider
     * lacks credentials — writing the seed catalog would re-freeze dead
     * Claude/Gemini rows. Clear overrides and write nothing instead.
     */
    public function testResetUserDefaultsWritesNothingWhenRegisteredProvidersAreAllUnavailable(): void
    {
        $existing = [$this->createMock(Config::class)];
        $this->configRepository->method('findBy')->willReturn($existing);
        $this->configRepository->expects($this->once())->method('removeAll')->with($existing);
        $this->configRepository->expects($this->never())->method('setValue');

        $this->givenUsableProviders([], ['anthropic', 'groq', 'google', 'openai']);

        $result = $this->service->resetUserDefaults(7);

        $this->assertSame(1, $result['removed']);
        $this->assertSame(0, $result['written']);
        $this->assertSame([], $result['defaults']);
    }
}
