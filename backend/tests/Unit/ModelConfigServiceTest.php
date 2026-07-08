<?php

namespace App\Tests\Unit;

use App\AI\Service\ProviderRegistry;
use App\Entity\Config;
use App\Entity\Model;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Repository\UserRepository;
use App\Service\ModelConfigService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

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

        $this->service = new ModelConfigService(
            $this->configRepository,
            $this->modelRepository,
            $this->userRepository,
            $this->cache,
            $this->providerRegistry
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
        $this->modelRepository->method('find')->with(999)->willReturn(null);

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
        $message->method('getMeta')->with('channel')->willReturn('whatsapp');

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
        $message->method('getMeta')->with('channel')->willReturn('whatsapp');

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
        $message->method('getMeta')->with('channel')->willReturn('web');

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
        $message->method('getMeta')->with('channel')->willReturn(null);

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
        $message->method('getMeta')->with('channel')->willReturn('whatsapp');

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

        $this->modelRepository
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
     * DEFAULTMODEL.SUMMARY override before anything else — this is how an
     * operator points the condensing step at e.g. a GPT-OSS-120B model.
     */
    public function testGetSummaryModelConfigPrefersExplicitSummaryModel(): void
    {
        $userId = 5;
        $summaryModelId = 300;

        $summaryConfig = $this->createMock(Config::class);
        $summaryConfig->method('getValue')->willReturn((string) $summaryModelId);

        // First lookup (user SUMMARY) wins — no fallback lookups happen.
        $this->configRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with([
                'ownerId' => $userId,
                'group' => 'DEFAULTMODEL',
                'setting' => 'SUMMARY',
            ])
            ->willReturn($summaryConfig);

        $model = $this->createMock(Model::class);
        $model->method('getService')->willReturn('Groq');
        $model->method('getProviderId')->willReturn('gpt-oss-120b');

        $this->modelRepository
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
     * With no SUMMARY override the summarizer defaults to the sorting (SORT)
     * model — the cheap/fast model requested for condensing by default.
     */
    public function testGetSummaryModelConfigFallsBackToSortModel(): void
    {
        $userId = 9;
        $sortModelId = 73;

        $sortConfig = $this->createMock(Config::class);
        $sortConfig->method('getValue')->willReturn((string) $sortModelId);

        // Chain: user SUMMARY → global SUMMARY → user SORT (returns here).
        $this->configRepository
            ->expects($this->exactly(3))
            ->method('findOneBy')
            ->willReturnCallback(function (array $criteria) use ($userId, $sortConfig) {
                static $calls = 0;
                ++$calls;

                $expected = [
                    ['ownerId' => $userId, 'group' => 'DEFAULTMODEL', 'setting' => 'SUMMARY'],
                    ['ownerId' => 0, 'group' => 'DEFAULTMODEL', 'setting' => 'SUMMARY'],
                    ['ownerId' => $userId, 'group' => 'DEFAULTMODEL', 'setting' => 'SORT'],
                ];

                self::assertSame($expected[$calls - 1], $criteria, "Summary fallback step {$calls}");

                return 3 === $calls ? $sortConfig : null;
            });

        $model = $this->createMock(Model::class);
        $model->method('getService')->willReturn('Groq');
        $model->method('getProviderId')->willReturn('llama-3.3-70b');

        $this->modelRepository
            ->method('find')
            ->with($sortModelId)
            ->willReturn($model);

        $this->assertSame([
            'model' => 'llama-3.3-70b',
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
        $message->method('getMeta')->with('channel')->willReturn('web');

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
}
