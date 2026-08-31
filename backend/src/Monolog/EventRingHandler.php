<?php

declare(strict_types=1);

namespace App\Monolog;

use App\Observability\EventRingStore;
use App\Observability\RequestIdGenerator;
use Monolog\Handler\AbstractProcessingHandler;
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
 * structured fields are ever persisted — no log message reaches the ring raw.
 */
final class EventRingHandler extends AbstractProcessingHandler
{
    /**
     * Reentrancy guard. Writing an event can itself emit a `warning+` log — the
     * shared {@see \App\Service\Infrastructure\RedisService} logs "Redis command
     * failed" when the ring's own Redis call fails. That warning would route
     * straight back into this handler and, with Redis unreachable, recurse until
     * the stack overflows — precisely during an outage, when logging must stay
     * cheap and safe. The flag makes the write self-suppressing.
     */
    private bool $handling = false;

    public function __construct(
        private readonly EventRingStore $store,
        private readonly RequestStack $requestStack,
    ) {
        parent::__construct(Level::Warning, true);
    }

    protected function write(LogRecord $record): void
    {
        if ($this->handling) {
            return;
        }

        $this->handling = true;
        try {
            $this->record($record);
        } finally {
            $this->handling = false;
        }
    }

    private function record(LogRecord $record): void
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
        }

        // Which cluster node produced the event — the first thing you want to
        // know on a multi-server deployment. Non-PII, cheap.
        $host = gethostname();

        $this->store->record([
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
        ]);
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
