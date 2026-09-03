<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Service;

use App\AI\Credential\HiggsfieldCredentialResolver;
use App\AI\Health\ModelHealthRecorder;
use App\AI\Provider\TestProvider;
use App\AI\Service\AiFacade;
use App\AI\Service\ProviderRegistry;
use App\Service\CircuitBreaker;
use App\Service\DiscordNotificationService;
use App\Service\File\UserUploadPathBuilder;
use App\Service\InternalEmailService;
use App\Service\ModelConfigService;
use App\Service\Usage\TranscriptionUsageRecorder;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * The facade must not strip tool_calls / finish_reason from a provider answer.
 */
final class AiFacadeChatToolsTest extends TestCase
{
    public function testChatForwardsToolCalls(): void
    {
        $provider = new TestProvider();
        $registry = $this->createMock(ProviderRegistry::class);
        $registry->method('getChatProvider')->willReturn($provider);

        $circuitBreaker = $this->createMock(CircuitBreaker::class);
        $circuitBreaker->method('execute')->willReturnCallback(static fn (callable $callback) => $callback());

        $facade = new AiFacade(
            $registry,
            $this->createMock(ModelConfigService::class),
            $circuitBreaker,
            new NullLogger(),
            $this->createMock(UserUploadPathBuilder::class),
            $this->createMock(DiscordNotificationService::class),
            $this->createMock(InternalEmailService::class),
            $this->createMock(CacheInterface::class),
            $this->createMock(CacheItemPoolInterface::class),
            $this->createMock(HiggsfieldCredentialResolver::class),
            $this->createMock(TranscriptionUsageRecorder::class),
            $this->createMock(ModelHealthRecorder::class),
            '/tmp',
        );

        $result = $facade->chat(
            [['role' => 'user', 'content' => 'TOOLTEST:get_weather:{"city":"Berlin"}']],
            1,
            [
                'provider' => 'test',
                'model' => 'test-model',
                'tools' => [[
                    'type' => 'function',
                    'function' => ['name' => 'get_weather'],
                ]],
            ],
        );

        self::assertSame('tool_calls', $result['finish_reason']);
        self::assertIsArray($result['tool_calls']);
        self::assertSame('get_weather', $result['tool_calls'][0]['function']['name']);
        self::assertSame('{"city":"Berlin"}', $result['tool_calls'][0]['function']['arguments']);
    }
}
