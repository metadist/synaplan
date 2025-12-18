<?php

namespace App\Tests\Unit;

use App\Entity\Config;
use App\Entity\Model;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Repository\UserRepository;
use App\Service\ModelConfigService;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

class ModelConfigServiceTest extends TestCase
{
    private ConfigRepository $configRepository;
    private ModelRepository $modelRepository;
    private UserRepository $userRepository;
    private CacheItemPoolInterface $cache;
    private ModelConfigService $service;
    private CacheItemInterface $cacheItem;

    protected function setUp(): void
    {
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->modelRepository = $this->createMock(ModelRepository::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->cache = $this->createMock(CacheItemPoolInterface::class);
        $this->cacheItem = $this->createMock(CacheItemInterface::class);

        $this->service = new ModelConfigService(
            $this->configRepository,
            $this->modelRepository,
            $this->userRepository,
            $this->cache
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

        // Mock message with email channel
        $message = $this->createMock(\App\Entity\Message::class);
        $message->method('getUserId')->willReturn($userId);
        $message->method('getMeta')->with('channel')->willReturn('email');

        // Should not check user repository for non-WhatsApp channels
        $this->userRepository
            ->expects($this->never())
            ->method('find');

        $result = $this->service->getEffectiveUserIdForMessage($message);

        $this->assertEquals($userId, $result, 'Email channel should always return userId');
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
