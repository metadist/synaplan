<?php

declare(strict_types=1);

namespace App\Monolog;

use App\Observability\EventRingStore;
use App\Observability\RequestIdGenerator;
use Monolog\Handler\AbstractHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Feeds warning-and-above log records into the redacted {@see EventRingStore}.
 *
 * Runs independently of the stderr handler's level: even when production logs
 * only errors to stderr, the ring still captures warnings, so "a fallback fired
 * 400 times" stays visible. Enriches each event with the current route, method
 * and correlation id when a request is in scope (absent on worker/CLI runs).
 *
 * All free-text goes through the store's scrubber, and only the allow-listed
 * structured fields are ever persisted. The Monolog context is NEVER copied
 * wholesale — six keys are read by name (`provider`, `model`, `worker`,
 * `user_id`, `status_code`, `duration_ms`, plus `error` as the reason text),
 * because contexts across the app also carry `email`, `to` and free-form
 * payloads that must never reach the AI-facing feed.
 *
 * Extends {@see AbstractHandler} rather than `AbstractProcessingHandler`: the
 * latter renders every record through a `LineFormatter` before calling
 * `write()`, and this handler serialises the event itself, so that work would
 * be thrown away on every single warning.
 */
final class EventRingHandler extends AbstractHandler
{
    /** How long to stop writing after Redis refused an event. */
    private const MUTE_SECONDS = 60.0;

    /**
     * Reentrancy guard. Writing an event can itself emit a `warning+` log — the
     * shared {@see \App\Service\Infrastructure\RedisService} logs "Redis command
     * failed" when the ring's own Redis call fails. That warning would route
     * straight back into this handler and, with Redis unreachable, recurse until
     * the stack overflows — precisely during an outage, when logging must stay
     * cheap and safe. The flag makes the write self-suppressing.
     */
    private bool $handling = false;

    /**
     * Circuit breaker. The guard above stops the recursion but not the cost: a
     * Predis command against a dead Redis blocks for the connection timeout,
     * and it reconnects per command, so every logged warning would add seconds
     * of latency on the error path. After a refused write we stay quiet for a
     * minute instead.
     */
    private float $mutedUntil = 0.0;

    public function __construct(
        private readonly EventRingStore $store,
        private readonly RequestStack $requestStack,
    ) {
        parent::__construct(Level::Warning, true);
    }

    public function handle(LogRecord $record): bool
    {
        if (!$this->isHandling($record) || $this->handling) {
            return false;
        }

        $now = microtime(true);
        if ($now < $this->mutedUntil) {
            return false;
        }

        $this->handling = true;
        try {
            $this->mutedUntil = $this->record($record) ? 0.0 : $now + self::MUTE_SECONDS;
        } finally {
            $this->handling = false;
        }

        return false;
    }

    private function record(LogRecord $record): bool
    {
        $request = $this->requestStack->getMainRequest();

        $route = $request?->attributes->get('_route');
        $requestId = $request?->attributes->get(RequestIdGenerator::ATTRIBUTE);
        if (!\is_string($requestId)) {
            $extraId = $record->extra['request_id'] ?? null;
            $requestId = \is_string($extraId) ? $extraId : null;
        }

        $exception = $record->context['exception'] ?? null;
        $exceptionClass = null;
        $exceptionMessage = null;
        $stack = [];
        if ($exception instanceof \Throwable) {
            $exceptionClass = $exception::class;
            $exceptionMessage = $exception->getMessage();
            $stack = $this->stackFrames($exception);
        } else {
            // By far the most common shape in this codebase is
            // `$logger->error('X failed', ['error' => $e->getMessage()])` with no
            // exception object. Without this the event would carry the bare
            // template and no reason at all.
            $exceptionMessage = $this->contextString($record, 'error');
        }

        // Which cluster node produced the event — the first thing you want to
        // know on a multi-server deployment. Non-PII, cheap.
        $host = gethostname();

        return $this->store->record([
            'ts' => $record->datetime->getTimestamp(),
            'level' => strtolower($record->level->getName()),
            'channel' => $record->channel,
            'event' => null !== $exceptionClass ? 'exception' : 'log',
            'message' => $record->message,
            'exception_class' => $exceptionClass,
            'exception_message' => $exceptionMessage,
            'stack' => $stack,
            'request_id' => $requestId,
            'host' => false !== $host ? $host : null,
            'route' => \is_string($route) ? $route : null,
            'method' => $request?->getMethod(),
            'status_code' => $this->contextInt($record, 'status_code'),
            'user_id' => $this->contextInt($record, 'user_id'),
            'provider' => $this->contextString($record, 'provider'),
            'model' => $this->contextString($record, 'model'),
            'worker' => $this->contextString($record, 'worker'),
            'duration_ms' => $this->contextInt($record, 'duration_ms'),
        ]);
    }

    private function contextString(LogRecord $record, string $key): ?string
    {
        $value = $record->context[$key] ?? null;

        return \is_string($value) ? $value : null;
    }

    private function contextInt(LogRecord $record, string $key): ?int
    {
        $value = $record->context[$key] ?? null;
        if (\is_int($value)) {
            return $value;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Compact `file:line` frames, no arguments — enough to locate the failure,
     * nothing that could carry a runtime value.
     *
     * @return list<string>
     */
    private function stackFrames(\Throwable $exception): array
    {
        $frames = ["{$exception->getFile()}:{$exception->getLine()}"];
        foreach ($exception->getTrace() as $frame) {
            if (isset($frame['file'], $frame['line'])) {
                $frames[] = "{$frame['file']}:{$frame['line']}";
            }
        }

        return $frames;
    }
}
