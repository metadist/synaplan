<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Stt;

use App\AI\Service\AiFacade;
use App\Entity\User;
use App\Service\Exception\RateLimitExceededException;
use App\Service\Infrastructure\RedisService;
use App\Service\RateLimitService;
use App\Service\Stt\Exception\SttSessionClosedException;
use App\Service\Stt\Exception\SttSessionNotFoundException;
use App\Service\Stt\SttAudioAssembler;
use App\Service\Stt\SttModelResolver;
use App\Service\Stt\SttSessionService;
use App\Service\Stt\SttSessionStore;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SttSessionServiceTest extends TestCase
{
    private string $dir;
    private SttSessionStore $store;
    private AiFacade&MockObject $aiFacade;
    private RateLimitService&MockObject $rateLimit;
    private SttModelResolver&MockObject $resolver;
    private SttSessionService $service;
    private User&MockObject $user;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/stt-svc-'.bin2hex(random_bytes(4));
        $this->store = new SttSessionStore(
            new RedisService('', 'test', new NullLogger()),
            new NullLogger(),
            $this->dir,
            3600,
        );
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->rateLimit = $this->createMock(RateLimitService::class);
        $this->resolver = $this->createMock(SttModelResolver::class);
        $this->rateLimit->method('checkLimit')->willReturn(['allowed' => true]);
        $this->resolver->method('resolve')->willReturn([
            'provider' => 'whisper',
            'providerModelId' => 'whisper',
            'displayModel' => 'whisper',
            'model_id' => 330,
        ]);

        $this->service = new SttSessionService(
            $this->store,
            new SttAudioAssembler(),
            $this->resolver,
            $this->aiFacade,
            $this->rateLimit,
            new NullLogger(),
        );

        $this->user = $this->createMock(User::class);
        $this->user->method('getId')->willReturn(42);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
    }

    public function testCreateIsolatesClientsOnTheSameApiKey(): void
    {
        $first = $this->service->create($this->user, 1, ['client_id' => '123']);
        $second = $this->service->create($this->user, 1, ['client_id' => '321']);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('123', $first->clientId);
        $this->assertSame('321', $second->clientId);
        $this->assertSame(1, $first->apiKeyId);
        $this->assertSame(1, $second->apiKeyId);

        $listed = $this->service->listOwned(1, '123');
        $this->assertCount(1, $listed);
        $this->assertSame($first->id, $listed[0]->id);
    }

    public function testReuseReturnsExistingOpenSession(): void
    {
        $first = $this->service->create($this->user, 1, ['client_id' => '123']);
        $again = $this->service->create($this->user, 1, ['client_id' => '123', 'reuse' => true]);

        $this->assertSame($first->id, $again->id);
    }

    public function testAppendCommitStreamsTextThroughConfiguredModel(): void
    {
        $session = $this->service->create($this->user, 1, [
            'client_id' => '123',
            'encoding' => 'pcm_s16le',
            'commit_after_bytes' => 1_000_000,
        ]);

        $this->aiFacade->expects($this->once())
            ->method('transcribe')
            ->with(
                $this->callback(static fn (string $path): bool => is_file($path) && str_ends_with($path, '.wav')),
                42,
                $this->callback(static function (array $opts): bool {
                    return 'whisper' === $opts['provider'] && 'whisper' === $opts['model'];
                }),
            )
            ->willReturn([
                'text' => 'hello from the stream',
                'language' => 'en',
                'duration' => 1.5,
            ]);

        $this->service->appendAudio($this->user, 1, $session->id, str_repeat("\x00\x01", 64), false);
        $committed = $this->service->commit($this->user, 1, $session->id);

        $this->assertSame('hello from the stream', $committed->text);
        $this->assertCount(1, $committed->segments);
        $this->assertSame(0, $committed->pendingBytes);
        $this->assertSame('123', $committed->clientId);
        $this->assertSame(1, $committed->apiKeyId);
    }

    public function testAutoCommitWhenBufferThresholdReached(): void
    {
        $session = $this->service->create($this->user, 1, [
            'client_id' => '321',
            'commit_after_bytes' => 8000,
        ]);

        $this->aiFacade->expects($this->once())
            ->method('transcribe')
            ->willReturn(['text' => 'auto', 'language' => 'en', 'duration' => 0.4]);

        $result = $this->service->appendAudio(
            $this->user,
            1,
            $session->id,
            str_repeat('a', 9000),
            false,
        );

        $this->assertTrue($result['committed']);
        $this->assertSame('auto', $result['session']->text);
    }

    public function testOtherApiKeyCannotSeeSession(): void
    {
        $session = $this->service->create($this->user, 1, ['client_id' => '123']);

        $this->expectException(SttSessionNotFoundException::class);
        $this->service->getOwned($session->id, 99);
    }

    public function testClosedSessionRejectsAudio(): void
    {
        $session = $this->service->create($this->user, 1, ['client_id' => '123']);
        $this->service->close(1, $session->id);

        $this->expectException(SttSessionClosedException::class);
        $this->service->appendAudio($this->user, 1, $session->id, 'xxxx');
    }

    public function testRateLimitIsEnforcedOnCommit(): void
    {
        $this->rateLimit = $this->createMock(RateLimitService::class);
        $this->rateLimit->method('checkLimit')->willReturn([
            'allowed' => false,
            'used' => 10,
            'limit' => 10,
        ]);
        $this->service = new SttSessionService(
            $this->store,
            new SttAudioAssembler(),
            $this->resolver,
            $this->aiFacade,
            $this->rateLimit,
            new NullLogger(),
        );

        $session = $this->service->create($this->user, 1, ['client_id' => '123']);
        $this->service->appendAudio($this->user, 1, $session->id, 'abcd', false);

        $this->expectException(RateLimitExceededException::class);
        $this->service->commit($this->user, 1, $session->id);
    }

    public function testTranscribeFileReturnsOpenAiShapeWithClientId(): void
    {
        $path = $this->dir.'/one-shot.wav';
        mkdir($this->dir, 0775, true);
        file_put_contents($path, 'RIFF');

        $this->aiFacade->method('transcribe')->willReturn([
            'text' => 'one shot',
            'language' => 'de',
            'duration' => 2.0,
            'segments' => [],
        ]);

        $result = $this->service->transcribeFile($this->user, 1, $path, [
            'client_id' => '123',
            'model' => 'whisper',
        ]);

        $this->assertSame('one shot', $result['text']);
        $this->assertSame('123', $result['client_id']);
        $this->assertSame(1, $result['api_key_id']);
        $this->assertSame(42, $result['user_id']);
        $this->assertSame('whisper', $result['model']);
        $this->assertStringStartsWith('transcribe_', (string) $result['id']);
    }

    public function testInvalidClientIdRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->create($this->user, 1, ['client_id' => 'bad id with spaces']);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            $file->isDir() ? rmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
