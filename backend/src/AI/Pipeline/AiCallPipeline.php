<?php

declare(strict_types=1);

namespace App\AI\Pipeline;

use App\AI\Exception\StructuredOutputViolationException;
use App\AI\Stream\StreamChunk;
use App\Observability\EventRingStore;
use App\Service\Exception\StreamCancelledException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Single execution pipeline for every {@see AiCall} (`03_contracts.md` §1).
 *
 * Runs tagged {@see AiCallHookInterface} services in descending priority order
 * before and after the provider transport, measures each phase in
 * {@see AiCallTimings}, enforces cooperative hook budgets (`03_contracts.md`
 * §1.4), and keeps hook exceptions contained according to {@see HookFailure}.
 */
final readonly class AiCallPipeline
{
    /** @var list<array{hook: AiCallHookInterface, attr: AsAiCallHook}> */
    private array $hooks;

    /** @var \Closure(): int */
    private \Closure $clockNs;

    /**
     * @param iterable<AiCallHookInterface> $hooks
     * @param (\Closure(): int)|null        $clockNs
     * @param (\Closure(string): bool)|null $flagReader
     */
    public function __construct(
        #[AutowireIterator('app.ai.call_hook')]
        iterable $hooks = [],
        private LoggerInterface $logger = new NullLogger(),
        private ?EventRingStore $eventRing = null,
        ?\Closure $clockNs = null,
        private ?\Closure $flagReader = null,
    ) {
        $this->clockNs = $clockNs ?? static fn (): int => hrtime(true);
        $this->hooks = self::sortHooks($hooks);
    }

    /**
     * Ordered hooks registered on this pipeline (highest priority first).
     *
     * @return list<array{hook: AiCallHookInterface, attr: AsAiCallHook}>
     */
    public function registeredHooks(): array
    {
        return $this->hooks;
    }

    /**
     * Execute a non-streaming {@see AiCall}.
     *
     * @param callable(AiCall): mixed                $transport
     * @param (callable(mixed): (AiUsage|null))|null $usageExtractor
     */
    public function run(AiCall $call, callable $transport, ?callable $usageExtractor = null): AiCallResult
    {
        [$call, $refusal, $hooksBeforeMs] = $this->runBefore($call);
        if (null !== $refusal) {
            $result = new AiCallResult(
                outcome: AiCallOutcome::Refused,
                value: null,
                usage: null,
                timings: new AiCallTimings(hooksBeforeMs: $hooksBeforeMs),
                failureClass: $refusal->toThrowable()::class,
                exception: $refusal->toThrowable(),
            );
            $result = $this->runAfter($call, $result);

            throw $refusal->toThrowable();
        }

        $providerStart = ($this->clockNs)();
        try {
            $value = $transport($call);
            $providerMs = (($this->clockNs)() - $providerStart) / 1_000_000.0;
        } catch (StreamCancelledException $cancelled) {
            $providerMs = (($this->clockNs)() - $providerStart) / 1_000_000.0;
            $result = new AiCallResult(
                outcome: AiCallOutcome::Cancelled,
                value: null,
                usage: null,
                timings: new AiCallTimings(hooksBeforeMs: $hooksBeforeMs, providerMs: $providerMs),
                failureClass: $cancelled::class,
                exception: $cancelled,
            );
            $this->runAfter($call, $result);

            throw $cancelled;
        } catch (StructuredOutputViolationException $violation) {
            $providerMs = (($this->clockNs)() - $providerStart) / 1_000_000.0;
            $result = new AiCallResult(
                outcome: AiCallOutcome::Failed,
                value: null,
                usage: null,
                timings: new AiCallTimings(hooksBeforeMs: $hooksBeforeMs, providerMs: $providerMs),
                failureClass: $violation::class,
                exception: $violation,
            );
            $this->runAfter($call, $result);

            throw $violation;
        } catch (\Throwable $error) {
            $providerMs = (($this->clockNs)() - $providerStart) / 1_000_000.0;
            $result = new AiCallResult(
                outcome: AiCallOutcome::Failed,
                value: null,
                usage: null,
                timings: new AiCallTimings(hooksBeforeMs: $hooksBeforeMs, providerMs: $providerMs),
                failureClass: $error::class,
                exception: $error,
            );
            $this->runAfter($call, $result);

            throw $error;
        }

        $usage = null !== $usageExtractor ? $usageExtractor($value) : self::defaultUsage($value);
        $result = new AiCallResult(
            outcome: AiCallOutcome::Ok,
            value: $value,
            usage: $usage,
            timings: new AiCallTimings(hooksBeforeMs: $hooksBeforeMs, providerMs: $providerMs),
        );

        return $this->runAfter($call, $result);
    }

    /**
     * Execute a streaming {@see AiCall} (`03_contracts.md` §1.7.3).
     *
     * @param callable(AiCall, callable(mixed): void): mixed $transport
     * @param callable(mixed): void                          $onChunk
     * @param (callable(mixed): (AiUsage|null))|null         $usageExtractor
     */
    public function stream(
        AiCall $call,
        callable $transport,
        callable $onChunk,
        ?callable $usageExtractor = null,
    ): AiCallResult {
        [$call, $refusal, $hooksBeforeMs] = $this->runBefore($call);
        if (null !== $refusal) {
            $result = new AiCallResult(
                outcome: AiCallOutcome::Refused,
                value: null,
                usage: null,
                timings: new AiCallTimings(hooksBeforeMs: $hooksBeforeMs),
                failureClass: $refusal->toThrowable()::class,
                exception: $refusal->toThrowable(),
            );
            $this->runAfter($call, $result);

            throw $refusal->toThrowable();
        }

        $providerStart = ($this->clockNs)();
        $ttftMs = null;

        $wrappedChunk = function (mixed $rawChunk) use (&$call, $onChunk, $providerStart, &$ttftMs): void {
            $this->observeStreamChunk($call, $rawChunk, $providerStart, $ttftMs);
            $onChunk($rawChunk);
        };

        try {
            $value = $transport($call, $wrappedChunk);
            $providerMs = (($this->clockNs)() - $providerStart) / 1_000_000.0;
        } catch (StreamCancelledException $cancelled) {
            $providerMs = (($this->clockNs)() - $providerStart) / 1_000_000.0;
            $result = new AiCallResult(
                outcome: AiCallOutcome::Cancelled,
                value: null,
                usage: null,
                timings: new AiCallTimings(hooksBeforeMs: $hooksBeforeMs, ttftMs: $ttftMs, providerMs: $providerMs),
                failureClass: $cancelled::class,
                exception: $cancelled,
            );
            $this->runAfter($call, $result);

            throw $cancelled;
        } catch (\Throwable $error) {
            $providerMs = (($this->clockNs)() - $providerStart) / 1_000_000.0;
            $result = new AiCallResult(
                outcome: AiCallOutcome::Failed,
                value: null,
                usage: null,
                timings: new AiCallTimings(hooksBeforeMs: $hooksBeforeMs, ttftMs: $ttftMs, providerMs: $providerMs),
                failureClass: $error::class,
                exception: $error,
            );
            $this->runAfter($call, $result);

            throw $error;
        }

        $usage = null !== $usageExtractor ? $usageExtractor($value) : self::defaultUsage($value);
        $result = new AiCallResult(
            outcome: AiCallOutcome::Ok,
            value: $value,
            usage: $usage,
            timings: new AiCallTimings(hooksBeforeMs: $hooksBeforeMs, ttftMs: $ttftMs, providerMs: $providerMs),
        );

        return $this->runAfter($call, $result);
    }

    /**
     * Wrap a lazy `\Generator` stream (`synthesizeStream`) in the pipeline
     * lifecycle: `before()` hooks and `$transport($call)` run eagerly when
     * `streamGenerator()` is called (so provider setup failures throw before
     * HTTP 200 stream headers are sent), while chunks yield lazily inside the
     * returned generator.
     *
     * @param callable(AiCall): \Generator           $transport
     * @param (callable(mixed): (AiUsage|null))|null $usageExtractor
     */
    public function streamGenerator(AiCall $call, callable $transport, ?callable $usageExtractor = null): \Generator
    {
        [$call, $refusal, $hooksBeforeMs] = $this->runBefore($call);
        if (null !== $refusal) {
            $result = new AiCallResult(
                outcome: AiCallOutcome::Refused,
                value: null,
                usage: null,
                timings: new AiCallTimings(hooksBeforeMs: $hooksBeforeMs),
                failureClass: $refusal->toThrowable()::class,
                exception: $refusal->toThrowable(),
            );
            $this->runAfter($call, $result);

            throw $refusal->toThrowable();
        }

        $providerStart = ($this->clockNs)();
        try {
            $generator = $transport($call);
        } catch (StreamCancelledException $cancelled) {
            $providerMs = (($this->clockNs)() - $providerStart) / 1_000_000.0;
            $this->runAfter($call, new AiCallResult(
                outcome: AiCallOutcome::Cancelled,
                value: null,
                usage: null,
                timings: new AiCallTimings(hooksBeforeMs: $hooksBeforeMs, providerMs: $providerMs),
                failureClass: $cancelled::class,
                exception: $cancelled,
            ));

            throw $cancelled;
        } catch (\Throwable $error) {
            $providerMs = (($this->clockNs)() - $providerStart) / 1_000_000.0;
            $this->runAfter($call, new AiCallResult(
                outcome: AiCallOutcome::Failed,
                value: null,
                usage: null,
                timings: new AiCallTimings(hooksBeforeMs: $hooksBeforeMs, providerMs: $providerMs),
                failureClass: $error::class,
                exception: $error,
            ));

            throw $error;
        }

        return $this->iterateStreamGenerator($call, $generator, $providerStart, $hooksBeforeMs, $usageExtractor);
    }

    /**
     * @param (callable(mixed): (AiUsage|null))|null $usageExtractor
     */
    private function iterateStreamGenerator(
        AiCall $call,
        \Generator $generator,
        int $providerStart,
        float $hooksBeforeMs,
        ?callable $usageExtractor,
    ): \Generator {
        $ttftMs = null;

        try {
            foreach ($generator as $rawChunk) {
                $this->observeStreamChunk($call, $rawChunk, $providerStart, $ttftMs);
                yield $rawChunk;
            }
            $value = $generator->getReturn();
            $providerMs = (($this->clockNs)() - $providerStart) / 1_000_000.0;
        } catch (StreamCancelledException $cancelled) {
            $providerMs = (($this->clockNs)() - $providerStart) / 1_000_000.0;
            $this->runAfter($call, new AiCallResult(
                outcome: AiCallOutcome::Cancelled,
                value: null,
                usage: null,
                timings: new AiCallTimings(hooksBeforeMs: $hooksBeforeMs, ttftMs: $ttftMs, providerMs: $providerMs),
                failureClass: $cancelled::class,
                exception: $cancelled,
            ));

            throw $cancelled;
        } catch (\Throwable $error) {
            $providerMs = (($this->clockNs)() - $providerStart) / 1_000_000.0;
            $this->runAfter($call, new AiCallResult(
                outcome: AiCallOutcome::Failed,
                value: null,
                usage: null,
                timings: new AiCallTimings(hooksBeforeMs: $hooksBeforeMs, ttftMs: $ttftMs, providerMs: $providerMs),
                failureClass: $error::class,
                exception: $error,
            ));

            throw $error;
        }

        $usage = null !== $usageExtractor ? $usageExtractor($value) : self::defaultUsage($value);
        $this->runAfter($call, new AiCallResult(
            outcome: AiCallOutcome::Ok,
            value: $value,
            usage: $usage,
            timings: new AiCallTimings(hooksBeforeMs: $hooksBeforeMs, ttftMs: $ttftMs, providerMs: $providerMs),
        ));
    }

    private function observeStreamChunk(AiCall $call, mixed $rawChunk, int $providerStart, ?float &$ttftMs): void
    {
        $normalized = StreamChunk::fromProviderChunk($rawChunk);
        if (null === $ttftMs && 'content' === $normalized->type && '' !== $normalized->content) {
            $ttftMs = (($this->clockNs)() - $providerStart) / 1_000_000.0;
        }

        foreach ($this->hooks as ['hook' => $hook, 'attr' => $attr]) {
            if (!$hook instanceof StreamingAiCallHookInterface || !$this->isHookActive($attr)) {
                continue;
            }
            try {
                $hook->onChunk($call, $normalized);
            } catch (\Throwable $e) {
                $this->logger->warning('AI streaming hook onChunk failed', [
                    'hook' => $hook::class,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return array{0: AiCall, 1: ?AiCallRefusal, 2: float}
     */
    private function runBefore(AiCall $call): array
    {
        $totalNs = 0;
        $inheritedDeadline = $call->options->deadline;

        foreach ($this->hooks as ['hook' => $hook, 'attr' => $attr]) {
            if (!$this->isHookActive($attr)) {
                continue;
            }

            $callForHook = $attr->blocking
                ? $call->withOptions($call->options->withDeadline(AiCallDeadline::start($attr->budgetMs, $this->clockNs, $inheritedDeadline)))
                : $call->withOptions($call->options->withDeadline($inheritedDeadline));

            $startNs = ($this->clockNs)();
            try {
                $next = $hook->before($callForHook);
            } catch (\Throwable $e) {
                $elapsedNs = ($this->clockNs)() - $startNs;
                $totalNs += $elapsedNs;
                $this->logHookException($hook, 'before', $e);

                if (HookFailure::Closed === $attr->onError) {
                    return [$call->withOptions($call->options->withDeadline($inheritedDeadline)), AiCallRefusal::policy($call->provider), $totalNs / 1_000_000.0];
                }

                continue;
            }

            $elapsedNs = ($this->clockNs)() - $startNs;
            $totalNs += $elapsedNs;
            $elapsedMs = $elapsedNs / 1_000_000.0;

            if ($elapsedMs > $attr->budgetMs) {
                $this->logHookTimeout($hook, 'before', $attr->budgetMs, $elapsedMs, $call);
                if (HookFailure::Closed === $attr->onError) {
                    return [$call->withOptions($call->options->withDeadline($inheritedDeadline)), AiCallRefusal::policy($call->provider), $totalNs / 1_000_000.0];
                }
            }

            if ($next instanceof AiCallRefusal) {
                return [$call->withOptions($call->options->withDeadline($inheritedDeadline)), $next, $totalNs / 1_000_000.0];
            }

            $call = $next->withOptions($next->options->withDeadline($inheritedDeadline));
        }

        return [$call, null, $totalNs / 1_000_000.0];
    }

    private function runAfter(AiCall $call, AiCallResult $result): AiCallResult
    {
        $totalNs = 0;
        $inheritedDeadline = $call->options->deadline;

        foreach ($this->hooks as ['hook' => $hook, 'attr' => $attr]) {
            if (!$this->isHookActive($attr)) {
                continue;
            }

            $callForHook = $attr->blocking
                ? $call->withOptions($call->options->withDeadline(AiCallDeadline::start($attr->budgetMs, $this->clockNs, $inheritedDeadline)))
                : $call->withOptions($call->options->withDeadline($inheritedDeadline));

            $startNs = ($this->clockNs)();
            try {
                $hook->after($callForHook, $result);
            } catch (\Throwable $e) {
                $this->logHookException($hook, 'after', $e);
            }
            $elapsedNs = ($this->clockNs)() - $startNs;
            $totalNs += $elapsedNs;
            $elapsedMs = $elapsedNs / 1_000_000.0;

            if ($elapsedMs > $attr->budgetMs) {
                $this->logHookTimeout($hook, 'after', $attr->budgetMs, $elapsedMs, $call);
            }
        }

        return $result->withTimings($result->timings->withHooksAfterMs($totalNs / 1_000_000.0));
    }

    private function isHookActive(AsAiCallHook $attr): bool
    {
        if (null === $attr->flag || '' === $attr->flag) {
            return true;
        }

        if (null === $this->flagReader) {
            return false;
        }

        return (bool) ($this->flagReader)($attr->flag);
    }

    private function logHookTimeout(AiCallHookInterface $hook, string $phase, int $budgetMs, float $measuredMs, AiCall $call): void
    {
        $this->logger->warning('AI call hook exceeded budget', [
            'event' => 'ai.hook_timeout',
            'hook' => $hook::class,
            'phase' => $phase,
            'budget_ms' => $budgetMs,
            'measured_ms' => round($measuredMs, 3),
        ]);

        $this->eventRing?->record([
            'level' => 'warning',
            'channel' => 'ai',
            'event' => 'ai.hook_timeout',
            'message' => sprintf('%s::%s exceeded %dms budget (%.2fms)', (new \ReflectionClass($hook))->getShortName(), $phase, $budgetMs, $measuredMs),
            'request_id' => $call->context->requestId,
            'user_id' => $call->context->userId,
            'provider' => $call->provider,
            'model' => $call->model,
            'duration_ms' => (int) round($measuredMs),
        ]);
    }

    private function logHookException(AiCallHookInterface $hook, string $phase, \Throwable $e): void
    {
        $this->logger->warning('AI call hook threw exception', [
            'hook' => $hook::class,
            'phase' => $phase,
            'exception_class' => $e::class,
        ]);
    }

    private static function defaultUsage(mixed $value): ?AiUsage
    {
        if (!\is_array($value)) {
            return null;
        }

        if (\is_array($value['usage'] ?? null)) {
            return AiUsage::fromTokenArray($value['usage']);
        }

        return null;
    }

    /**
     * @param iterable<AiCallHookInterface> $hooks
     *
     * @return list<array{hook: AiCallHookInterface, attr: AsAiCallHook}>
     */
    private static function sortHooks(iterable $hooks): array
    {
        $entries = [];
        foreach ($hooks as $hook) {
            $ref = new \ReflectionClass($hook);
            $attrs = $ref->getAttributes(AsAiCallHook::class);
            $attr = [] !== $attrs
                ? $attrs[0]->newInstance()
                : new AsAiCallHook(priority: 0);
            $entries[] = ['hook' => $hook, 'attr' => $attr];
        }

        usort(
            $entries,
            static fn (array $a, array $b): int => $b['attr']->priority <=> $a['attr']->priority,
        );

        return $entries;
    }
}
