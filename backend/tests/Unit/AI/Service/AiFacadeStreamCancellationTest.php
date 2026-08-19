<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Service;

use App\AI\Credential\HiggsfieldCredentialResolver;
use App\AI\Exception\ProviderException;
use App\AI\Health\FailureKind;
use App\AI\Health\ModelHealthRecorder;
use App\AI\Interface\ChatProviderInterface;
use App\AI\Service\AiFacade;
use App\AI\Service\ProviderRegistry;
use App\Service\CircuitBreaker;
use App\Service\DiscordNotificationService;
use App\Service\Exception\StreamCancelledException;
use App\Service\File\UserUploadPathBuilder;
use App\Service\InternalEmailService;
use App\Service\ModelConfigService;
use App\Service\Usage\TranscriptionUsageRecorder;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * A Stop click travels up from the stream callback as a
 * {@see StreamCancelledException}. Wrapping it in a ProviderException made the
 * turn indistinguishable from a provider outage, so the caller rendered an
 * error message on top of the already persisted cancellation.
 */
class AiFacadeStreamCancellationTest extends TestCase
{
    public function testChatStreamRethrowsUserCancellationUnwrapped(): void
    {
        $provider = $this->createMock(ChatProviderInterface::class);
        $provider->method('getName')->willReturn('test');
        $provider->method('chatStream')->willThrowException(
            new StreamCancelledException('Stream cancelled by user')
        );

        $registry = $this->createMock(ProviderRegistry::class);
        $registry->method('getChatProvider')->willReturn($provider);

        $circuitBreaker = $this->createMock(CircuitBreaker::class);
        $circuitBreaker->method('execute')->willReturnCallback(static fn (callable $callback) => $callback());

        // recordFailure() returns an enum, which PHPUnit cannot invent a
        // default for, so the stub has to name one explicitly.
        $health = $this->createMock(ModelHealthRecorder::class);
        $health->method('recordFailure')->willReturn(FailureKind::Cancelled);

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
            $health,
            '/tmp'
        );

        try {
            $facade->chatStream([['role' => 'user', 'content' => 'hi']], static function (): void {}, 1);
            $this->fail('The cancellation must reach the caller');
        } catch (ProviderException $e) {
            $this->fail('A user cancel must not be reported as a provider failure: '.$e->getMessage());
        } catch (StreamCancelledException $e) {
            $this->assertSame('Stream cancelled by user', $e->getMessage());
        }
    }
}
