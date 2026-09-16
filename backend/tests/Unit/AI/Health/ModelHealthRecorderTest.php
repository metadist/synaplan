<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Health;

use App\AI\Exception\ProviderException;
use App\AI\Health\FailureClassifier;
use App\AI\Health\FailureKind;
use App\AI\Health\ModelHealthConfig;
use App\AI\Health\ModelHealthRecorder;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Service\Exception\StreamCancelledException;
use App\Service\Usage\UsageFailureLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ModelHealthRecorderTest extends TestCase
{
    private const MODEL_ID = 42;

    private ModelHealthRecorder $recorder;

    protected function setUp(): void
    {
        $this->recorder = $this->makeRecorder($this->createStub(UsageFailureLogger::class));
    }

    private function makeRecorder(UsageFailureLogger $failureLogger): ModelHealthRecorder
    {
        $configRepository = $this->createStub(ConfigRepository::class);
        $configRepository->method('getValue')->willReturn(null);

        $models = $this->createStub(ModelRepository::class);
        $models->method('findIdByServiceProviderIdAndTag')->willReturn(self::MODEL_ID);

        return new ModelHealthRecorder(
            new ArrayAdapter(),
            new ModelHealthConfig($configRepository),
            new FailureClassifier(),
            $models,
            $failureLogger,
            new NullLogger(),
        );
    }

    public function testCountsSuccessesAndFailuresInTheSameWindow(): void
    {
        $this->recorder->recordSuccess('chat', 'openai', 'gpt-5');
        $this->recorder->recordSuccess('chat', 'openai', 'gpt-5');
        $this->recorder->recordFailure('chat', 'openai', 'gpt-5', new ProviderException('boom', 'openai', null, 500));

        $snapshot = $this->recorder->snapshot(self::MODEL_ID);

        self::assertSame(2, $snapshot->successes);
        self::assertSame(1, $snapshot->failures);
        self::assertSame(3, $snapshot->total());
        self::assertSame(33, $snapshot->errorRatePercent());
        self::assertSame(FailureKind::Transient, $snapshot->lastKind);
    }

    /**
     * A cancelled stream is the user pressing stop. Counting it would make a
     * perfectly healthy model look broken on a page full of impatient users.
     */
    public function testCancelledCallsAreNotCounted(): void
    {
        $kind = $this->recorder->recordFailure('chat', 'openai', 'gpt-5', new StreamCancelledException('stopped'));

        self::assertSame(FailureKind::Cancelled, $kind);
        self::assertTrue($this->recorder->snapshot(self::MODEL_ID)->isEmpty());
    }

    public function testUserErrorsAreNotCountedAgainstTheModel(): void
    {
        $this->recorder->recordFailure('chat', 'google', 'gemini-3-pro', ProviderException::contentBlocked('google', 'SAFETY'));

        self::assertTrue($this->recorder->snapshot(self::MODEL_ID)->isEmpty());
    }

    /**
     * A credential problem belongs to the provider. Charging it to whichever
     * model happened to be called first would frame an innocent model.
     */
    public function testCredentialFailuresAreNotCountedAgainstTheModel(): void
    {
        $kind = $this->recorder->recordFailure(
            'chat',
            'anthropic',
            'claude-4',
            ProviderException::missingApiKey('anthropic', 'ANTHROPIC_API_KEY')
        );

        self::assertSame(FailureKind::Credential, $kind);
        self::assertTrue($this->recorder->snapshot(self::MODEL_ID)->isEmpty());
    }

    public function testASuccessClearsTheStoredErrorMessage(): void
    {
        $this->recorder->recordFailure('chat', 'openai', 'gpt-5', new ProviderException('upstream exploded', 'openai', null, 500));
        self::assertNotNull($this->recorder->snapshot(self::MODEL_ID)->lastKind);

        $this->recorder->recordSuccess('chat', 'openai', 'gpt-5');

        $snapshot = $this->recorder->snapshot(self::MODEL_ID);
        self::assertNull($snapshot->lastKind);
        self::assertNull($snapshot->lastMessage);
        self::assertSame(1, $snapshot->failures);
    }

    public function testFailuresAreWrittenToTheUsageLogWhenAUserIsKnown(): void
    {
        $failureLogger = $this->createMock(UsageFailureLogger::class);
        $failureLogger->expects(self::once())
            ->method('record')
            ->with(
                7,
                'chat',
                'openai',
                'gpt-5',
                self::MODEL_ID,
                FailureKind::Transient->value,
                self::stringContains('upstream exploded'),
            );

        $this->makeRecorder($failureLogger)->recordFailure(
            'chat',
            'openai',
            'gpt-5',
            new ProviderException('upstream exploded', 'openai', null, 500),
            7,
        );
    }

    public function testAnonymousFailuresSkipTheUsageLog(): void
    {
        $failureLogger = $this->createMock(UsageFailureLogger::class);
        $failureLogger->expects(self::never())->method('record');

        $this->makeRecorder($failureLogger)
            ->recordFailure('chat', 'openai', 'gpt-5', new ProviderException('boom', 'openai', null, 500));
    }

    public function testUnknownCapabilityIsIgnored(): void
    {
        $this->recorder->recordSuccess('telepathy', 'openai', 'gpt-5');

        self::assertNull($this->recorder->resolveModelId('telepathy', 'openai', 'gpt-5'));
        self::assertTrue($this->recorder->snapshot(self::MODEL_ID)->isEmpty());
    }

    public function testResetClearsTheWindow(): void
    {
        $this->recorder->recordSuccess('chat', 'openai', 'gpt-5');
        $this->recorder->reset(self::MODEL_ID);

        self::assertTrue($this->recorder->snapshot(self::MODEL_ID)->isEmpty());
    }
}
