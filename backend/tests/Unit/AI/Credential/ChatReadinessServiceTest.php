<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Credential;

use App\AI\Credential\ChatReadinessService;
use App\AI\Credential\ProviderDefaultsService;
use App\AI\Service\ProviderRegistry;
use App\Entity\Config;
use App\Entity\Model;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Repository\UserRepository;
use App\Service\ModelConfigService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Unit tests for {@see ChatReadinessService::isChatReady()}.
 *
 * Regression coverage for the live false positive where every authenticated
 * user saw the "no AI provider connected" banner although their chat worked:
 * readiness only checked the GLOBAL default chat model's provider, while the
 * chat pipeline resolves the model per user (per-user override first).
 *
 * ModelConfigService is final (cannot be mocked), so a real instance is built
 * on mocked repositories; ProviderDefaultsService is final too and never
 * reached by these paths, so a real instance on mocks is safe.
 */
class ChatReadinessServiceTest extends TestCase
{
    private const USER_ID = 42;
    private const USER_MODEL_BID = 7;
    private const GLOBAL_MODEL_BID = 9;

    private ProviderRegistry&MockObject $providerRegistry;
    private ConfigRepository&MockObject $configRepository;
    private ModelRepository&MockObject $modelRepository;

    protected function setUp(): void
    {
        $this->providerRegistry = $this->createMock(ProviderRegistry::class);
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->modelRepository = $this->createMock(ModelRepository::class);
    }

    private function service(): ChatReadinessService
    {
        $modelConfigService = new ModelConfigService(
            $this->configRepository,
            $this->modelRepository,
            $this->createMock(UserRepository::class),
            new ArrayAdapter(),
            $this->providerRegistry,
            'test',
        );

        return new ChatReadinessService(
            $this->providerRegistry,
            new ProviderDefaultsService($this->configRepository, new ArrayAdapter(), new NullLogger()),
            $modelConfigService,
            $this->modelRepository,
            new ArrayAdapter(),
            new NullLogger(),
        );
    }

    /**
     * @param array<int, string> $bindings ownerId => provider service of the bound CHAT model
     */
    private function givenDefaultChatBindings(array $bindings): void
    {
        $bidByOwner = [
            self::USER_ID => self::USER_MODEL_BID,
            0 => self::GLOBAL_MODEL_BID,
        ];

        $this->configRepository->method('findOneBy')->willReturnCallback(
            function (array $criteria) use ($bindings, $bidByOwner): ?Config {
                $ownerId = $criteria['ownerId'] ?? null;
                if ('DEFAULTMODEL' !== ($criteria['group'] ?? null) || 'CHAT' !== ($criteria['setting'] ?? null)) {
                    return null;
                }
                if (!isset($bindings[$ownerId])) {
                    return null;
                }

                $config = new Config();
                $config->setOwnerId((int) $ownerId);
                $config->setGroup('DEFAULTMODEL');
                $config->setSetting('CHAT');
                $config->setValue((string) $bidByOwner[$ownerId]);

                return $config;
            }
        );

        $modelsByBid = [];
        foreach ($bindings as $ownerId => $serviceName) {
            $model = new Model();
            $model->setService($serviceName);
            $modelsByBid[$bidByOwner[$ownerId]] = $model;
        }

        $this->modelRepository->method('find')->willReturnCallback(
            static fn ($bid): ?Model => $modelsByBid[(int) $bid] ?? null
        );
    }

    public function testUserWithWorkingOverrideIsReadyEvenThoughGlobalDefaultIsBroken(): void
    {
        $this->givenDefaultChatBindings([
            self::USER_ID => 'Anthropic',
            0 => 'Ollama',
        ]);

        $availability = ['anthropic' => true, 'ollama' => false];

        $this->assertTrue(
            $this->service()->isChatReady($availability, null, self::USER_ID),
            'A user whose own default chat model has a usable provider must be ready, regardless of the global default.'
        );
    }

    public function testGlobalDefaultOnBrokenProviderIsNotReadyWithoutUserOverride(): void
    {
        $this->givenDefaultChatBindings([
            0 => 'Ollama',
        ]);

        $availability = ['anthropic' => true, 'ollama' => false];

        $this->assertFalse(
            $this->service()->isChatReady($availability, null, self::USER_ID),
            'Without a per-user override the global default applies — its provider is down, so chat is not ready.'
        );
        $this->assertFalse(
            $this->service()->isChatReady($availability),
            'The install-level (global) readiness must also report not ready.'
        );
    }

    public function testGlobalDefaultWithUsableProviderIsReady(): void
    {
        $this->givenDefaultChatBindings([
            0 => 'Groq',
        ]);

        $this->assertTrue($this->service()->isChatReady(['groq' => true], null, self::USER_ID));
    }

    public function testFallsBackToAnyChatProviderWhenBindingDoesNotResolve(): void
    {
        $this->givenDefaultChatBindings([]);
        $this->providerRegistry->expects($this->once())
            ->method('getAvailableProviders')
            ->with('chat', false)
            ->willReturn(['Groq']);

        $this->assertTrue($this->service()->isChatReady(['groq' => true]));
    }

    public function testNotReadyWhenNothingIsSetAndNoProviderIsUsable(): void
    {
        $this->givenDefaultChatBindings([]);
        $this->providerRegistry->expects($this->once())
            ->method('getAvailableProviders')
            ->with('chat', false)
            ->willReturn([]);

        $this->assertFalse($this->service()->isChatReady([]));
    }
}
