<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Pipeline;

use App\AI\Exception\ProviderException;
use App\AI\Pipeline\AiCall;
use App\AI\Pipeline\AiCallContext;
use App\AI\Pipeline\AiCallHookInterface;
use App\AI\Pipeline\AiCallOptions;
use App\AI\Pipeline\AiCallOutcome;
use App\AI\Pipeline\AiCallPayload;
use App\AI\Pipeline\AiCallPipeline;
use App\AI\Pipeline\AiCallRefusal;
use App\AI\Pipeline\AiCallResult;
use App\AI\Pipeline\AiCallSource;
use App\AI\Pipeline\AiCapability;
use App\AI\Pipeline\AsAiCallHook;
use App\AI\Pipeline\Hook\CircuitBreakerHook;
use App\AI\Pipeline\Hook\ModelHealthHook;
use App\AI\Pipeline\Hook\RequestContextHook;
use App\AI\Pipeline\HookFailure;
use App\AI\Pipeline\StreamingAiCallHookInterface;
use App\AI\Service\AiFacade;
use App\AI\Stream\StreamChunk;
use App\Service\Exception\StreamCancelledException;
use App\Service\Infrastructure\CircuitBreaker;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Contract tests for {@see AiCallPipeline} and its tagged {@see AiCallHookInterface}
 * hooks (`03_contracts.md` §1.2–§1.4, S1 AU10).
 */
final class HookContractTest extends \PHPUnit\Framework\TestCase
{
    public function testDefaultHooksRunInDescendingPriorityOrderInBothPhases(): void
    {
        $classes = $this->discoverHookClasses();
        self::assertNotEmpty($classes);

        $byClass = [];
        foreach ($classes as $class) {
            $ref = new \ReflectionClass($class);
            $attrs = $ref->getAttributes(AsAiCallHook::class);
            self::assertNotEmpty($attrs, sprintf('Hook %s must declare #[AsAiCallHook].', $class));
            $byClass[$class] = $attrs[0]->newInstance();
        }

        self::assertSame(900, $byClass[RequestContextHook::class]->priority);
        self::assertSame(700, $byClass[CircuitBreakerHook::class]->priority);
        self::assertSame(600, $byClass[ModelHealthHook::class]->priority);
        self::assertGreaterThan($byClass[CircuitBreakerHook::class]->priority, $byClass[RequestContextHook::class]->priority);
        self::assertGreaterThan($byClass[ModelHealthHook::class]->priority, $byClass[CircuitBreakerHook::class]->priority);
    }

    public function testNonBlockingHooksDoNotInjectForbiddenServices(): void
    {
        $forbiddenTypes = [
            HttpClientInterface::class,
            AiFacade::class,
            EntityManagerInterface::class,
        ];

        foreach ($this->discoverHookClasses() as $class) {
            $ref = new \ReflectionClass($class);
            $attrs = $ref->getAttributes(AsAiCallHook::class);
            self::assertNotEmpty($attrs, sprintf('Hook %s must declare #[AsAiCallHook].', $class));
            /** @var AsAiCallHook $attr */
            $attr = $attrs[0]->newInstance();
            if ($attr->blocking) {
                continue;
            }

            $ctor = $ref->getConstructor();
            if (null === $ctor) {
                continue;
            }

            foreach ($ctor->getParameters() as $param) {
                $type = $param->getType();
                if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }
                $typeName = $type->getName();
                foreach ($forbiddenTypes as $forbidden) {
                    self::assertNotSame(
                        $forbidden,
                        $typeName,
                        sprintf('Non-blocking hook %s may not inject %s.', $class, $forbidden),
                    );
                }
                self::assertFalse(
                    str_starts_with($typeName, 'App\\Repository\\'),
                    sprintf('Non-blocking hook %s may not inject repository %s.', $class, $typeName),
                );
            }
        }
    }

    public function testBeforeAndAfterHooksRunInDescendingPriorityAndAfterHooksRunOnFailure(): void
    {
        $trace = [];
        $record = static function (string $entry) use (&$trace): void {
            $trace[] = $entry;
        };
        $hookLow = new #[AsAiCallHook(priority: 100)] class($record) implements AiCallHookInterface {
            /** @param \Closure(string): void $record */
            public function __construct(private readonly \Closure $record)
            {
            }

            public function before(AiCall $call): AiCall
            {
                ($this->record)('before:100');

                return $call;
            }

            public function after(AiCall $call, AiCallResult $result): void
            {
                ($this->record)('after:100:'.$result->outcome->value);
            }
        };
        $hookHigh = new #[AsAiCallHook(priority: 900)] class($record) implements AiCallHookInterface {
            /** @param \Closure(string): void $record */
            public function __construct(private readonly \Closure $record)
            {
            }

            public function before(AiCall $call): AiCall
            {
                ($this->record)('before:900');

                return $call;
            }

            public function after(AiCall $call, AiCallResult $result): void
            {
                ($this->record)('after:900:'.$result->outcome->value);
            }
        };

        $pipeline = new AiCallPipeline([$hookLow, $hookHigh]);
        $result = $pipeline->run($this->sampleCall(), static fn (): array => ['content' => 'ok', 'usage' => ['prompt_tokens' => 2, 'completion_tokens' => 3]]);

        self::assertSame(AiCallOutcome::Ok, $result->outcome);
        self::assertSame(5, $result->usage?->totalTokens);
        self::assertSame(['before:900', 'before:100', 'after:900:ok', 'after:100:ok'], $trace);

        $trace = [];
        try {
            $pipeline->run($this->sampleCall(), static function (): never {
                throw new ProviderException('Upstream 500', 'openai');
            });
            self::fail('Expected ProviderException was not thrown.');
        } catch (ProviderException) {
        }

        self::assertSame(['before:900', 'before:100', 'after:900:failed', 'after:100:failed'], $trace);
    }

    public function testRefusalInBeforeShortCircuitsTransportAndRunsAfterHooksWithRefusedOutcome(): void
    {
        $trace = [];
        $record = static function (string $entry) use (&$trace): void {
            $trace[] = $entry;
        };
        $transportCalled = false;

        $refusingHook = new #[AsAiCallHook(priority: 800)] class($record) implements AiCallHookInterface {
            /** @param \Closure(string): void $record */
            public function __construct(private readonly \Closure $record)
            {
            }

            public function before(AiCall $call): AiCallRefusal
            {
                ($this->record)('before:800:refuse');

                return AiCallRefusal::rateLimit($call->provider, 'Quota exhausted');
            }

            public function after(AiCall $call, AiCallResult $result): void
            {
                ($this->record)('after:800:'.$result->outcome->value);
            }
        };
        $laterHook = new #[AsAiCallHook(priority: 200)] class($record) implements AiCallHookInterface {
            /** @param \Closure(string): void $record */
            public function __construct(private readonly \Closure $record)
            {
            }

            public function before(AiCall $call): AiCall
            {
                ($this->record)('before:200');

                return $call;
            }

            public function after(AiCall $call, AiCallResult $result): void
            {
                ($this->record)('after:200:'.$result->outcome->value);
            }
        };

        $pipeline = new AiCallPipeline([$laterHook, $refusingHook]);

        try {
            $pipeline->run($this->sampleCall(), static function () use (&$transportCalled): array {
                $transportCalled = true;

                return [];
            });
            self::fail('Expected refusal exception was not thrown.');
        } catch (ProviderException $e) {
            self::assertSame('Quota exhausted', $e->getMessage());
        }

        self::assertFalse($transportCalled);
        self::assertSame(['before:800:refuse', 'after:800:refused', 'after:200:refused'], $trace);
    }

    public function testOverBudgetBeforeHookAppliesOnErrorAndAfterHookOverrunOnlyLogs(): void
    {
        $nowNs = 1_000_000_000;
        $clock = static function () use (&$nowNs): int {
            return $nowNs;
        };

        $logs = [];
        $logger = new class(function (string $msg, array $ctx) use (&$logs): void {
            $logs[] = ['msg' => $msg, 'ctx' => $ctx];
        }) extends AbstractLogger {
            /** @param \Closure(string, array<string, mixed>): void $record */
            public function __construct(private readonly \Closure $record)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                ($this->record)((string) $message, $context);
            }
        };

        $slowOpenHook = new #[AsAiCallHook(priority: 500, blocking: true, budgetMs: 5, onError: HookFailure::Open)] class($nowNs) implements AiCallHookInterface {
            public ?float $observedDeadlineSec = null;

            public function __construct(private int &$nowNs)
            {
            }

            public function before(AiCall $call): AiCall
            {
                $this->observedDeadlineSec = $call->options->deadline?->remainingSeconds();
                $this->nowNs += 10_000_000; // +10 ms (> 5 ms budget)

                return $call;
            }

            public function after(AiCall $call, AiCallResult $result): void
            {
                $this->nowNs += 8_000_000; // +8 ms (> 5 ms budget)
            }
        };

        $pipelineOpen = new AiCallPipeline([$slowOpenHook], $logger, null, $clock);
        $result = $pipelineOpen->run($this->sampleCall(), static function () use (&$nowNs): array {
            $nowNs += 20_000_000;

            return ['content' => 'allowed'];
        });

        self::assertSame(AiCallOutcome::Ok, $result->outcome);
        self::assertNotNull($slowOpenHook->observedDeadlineSec);
        self::assertCount(2, $logs);
        self::assertSame('before', $logs[0]['ctx']['phase']);
        self::assertSame('after', $logs[1]['ctx']['phase']);

        $logs = [];
        $slowClosedBefore = new #[AsAiCallHook(priority: 500, blocking: true, budgetMs: 5, onError: HookFailure::Closed)] class($nowNs) implements AiCallHookInterface {
            public function __construct(private int &$nowNs)
            {
            }

            public function before(AiCall $call): AiCall
            {
                $this->nowNs += 10_000_000; // +10 ms (> 5 ms budget)

                return $call;
            }

            public function after(AiCall $call, AiCallResult $result): void
            {
            }
        };

        $pipelineClosedBefore = new AiCallPipeline([$slowClosedBefore], $logger, null, $clock);
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('AI request refused by policy.');
        $pipelineClosedBefore->run($this->sampleCall(), static fn (): array => ['content' => 'never reached']);
    }

    public function testClosedHookOverBudgetInAfterDoesNotRefuseCompletedCall(): void
    {
        $nowNs = 1_000_000_000;
        $clock = static function () use (&$nowNs): int {
            return $nowNs;
        };

        $slowClosedAfter = new #[AsAiCallHook(priority: 500, blocking: true, budgetMs: 5, onError: HookFailure::Closed)] class($nowNs) implements AiCallHookInterface {
            public function __construct(private int &$nowNs)
            {
            }

            public function before(AiCall $call): AiCall
            {
                return $call;
            }

            public function after(AiCall $call, AiCallResult $result): void
            {
                $this->nowNs += 12_000_000; // +12 ms (> 5 ms budget)
            }
        };

        $pipeline = new AiCallPipeline([$slowClosedAfter], clockNs: $clock);
        $result = $pipeline->run($this->sampleCall(), static fn (): array => ['content' => 'completed']);

        self::assertSame(AiCallOutcome::Ok, $result->outcome);
        self::assertSame(['content' => 'completed'], $result->value);
    }

    public function testStreamingPipelineCapturesTtftNormalizesChunksAndHandlesCancellation(): void
    {
        $nowNs = 1_000_000_000;
        $clock = static function () use (&$nowNs): int {
            return $nowNs;
        };

        $seenChunks = [];
        $afterOutcomes = [];
        $onChunkRecord = static function (string $c) use (&$seenChunks): void {
            $seenChunks[] = $c;
        };
        $onAfterRecord = static function (string $o) use (&$afterOutcomes): void {
            $afterOutcomes[] = $o;
        };
        $streamHook = new #[AsAiCallHook(priority: 400)] class($onChunkRecord, $onAfterRecord) implements StreamingAiCallHookInterface {
            /**
             * @param \Closure(string): void $onChunkRecord
             * @param \Closure(string): void $onAfterRecord
             */
            public function __construct(
                private readonly \Closure $onChunkRecord,
                private readonly \Closure $onAfterRecord,
            ) {
            }

            public function before(AiCall $call): AiCall
            {
                return $call;
            }

            public function onChunk(AiCall $call, StreamChunk $chunk): void
            {
                ($this->onChunkRecord)($chunk->type.':'.$chunk->content);
            }

            public function after(AiCall $call, AiCallResult $result): void
            {
                ($this->onAfterRecord)($result->outcome->value);
            }
        };

        $pipeline = new AiCallPipeline([$streamHook], clockNs: $clock);
        $forwarded = [];
        $result = $pipeline->stream(
            $this->sampleCall(AiCapability::ChatStream),
            static function (AiCall $call, callable $onChunk) use (&$nowNs): array {
                $nowNs += 5_000_000;
                $onChunk(['type' => 'reasoning', 'content' => 'thinking']);
                $nowNs += 15_000_000; // first visible token at 20 ms
                $onChunk('Hello ');
                $nowNs += 10_000_000;
                $onChunk(['type' => 'content', 'content' => 'world']);

                return ['usage' => ['prompt_tokens' => 4, 'completion_tokens' => 2]];
            },
            static function (mixed $chunk) use (&$forwarded): void {
                $forwarded[] = $chunk;
            },
        );

        self::assertSame(AiCallOutcome::Ok, $result->outcome);
        self::assertSame(20.0, $result->timings->ttftMs);
        self::assertSame(30.0, $result->timings->providerMs);
        self::assertSame(['reasoning:thinking', 'content:Hello ', 'content:world'], $seenChunks);
        self::assertCount(3, $forwarded);

        try {
            $pipeline->stream(
                $this->sampleCall(AiCapability::ChatStream),
                static function (AiCall $call, callable $onChunk): array {
                    $onChunk('partial');
                    throw new StreamCancelledException('User stopped');
                },
                static function (): void {
                },
            );
            self::fail('Expected StreamCancelledException was not thrown.');
        } catch (StreamCancelledException) {
        }

        self::assertSame(['ok', 'cancelled'], $afterOutcomes);
    }

    public function testCircuitBreakerHookAccumulatesConsecutiveFailuresUntilOpen(): void
    {
        $breaker = new CircuitBreaker(new ArrayAdapter(), new NullLogger(), failureThreshold: 2);
        $pipeline = new AiCallPipeline([new CircuitBreakerHook($breaker)]);

        for ($i = 0; $i < 2; ++$i) {
            try {
                $pipeline->run($this->sampleCall(), static function (): never {
                    throw new ProviderException('Provider HTTP 500', 'openai', null, 500);
                });
                self::fail('Expected provider failure to throw.');
            } catch (ProviderException) {
            }
        }

        self::assertSame('open', $breaker->getCircuitStatus('ai_provider_openai')['state']);

        $transportCalled = false;
        try {
            $pipeline->run($this->sampleCall(), static function () use (&$transportCalled): array {
                $transportCalled = true;

                return [];
            });
            self::fail('Expected open circuit breaker to refuse the call.');
        } catch (ProviderException $e) {
            self::assertStringContainsString('circuit breaker is OPEN', $e->getMessage());
        }
        self::assertFalse($transportCalled);
    }

    private function sampleCall(AiCapability $capability = AiCapability::Chat): AiCall
    {
        return new AiCall(
            capability: $capability,
            provider: 'openai',
            model: 'gpt-4o',
            context: new AiCallContext(userId: 7, source: AiCallSource::ChatWeb),
            payload: AiCallPayload::chat([['role' => 'user', 'content' => 'Hello']]),
            options: new AiCallOptions(),
        );
    }

    /**
     * @return list<class-string<AiCallHookInterface>>
     */
    private function discoverHookClasses(): array
    {
        $dir = \dirname(__DIR__, 4).'/src/AI/Pipeline/Hook';
        $classes = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        /** @var \SplFileInfo $file */
        foreach ($it as $file) {
            if (!$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }
            $class = 'App\\AI\\Pipeline\\Hook\\'.$file->getBasename('.php');
            if (class_exists($class) && is_subclass_of($class, AiCallHookInterface::class)) {
                $classes[] = $class;
            }
        }
        sort($classes);

        return $classes;
    }
}
