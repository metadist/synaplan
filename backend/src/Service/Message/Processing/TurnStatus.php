<?php

declare(strict_types=1);

namespace App\Service\Message\Processing;

use App\Service\Message\ProgressCallback;
use Psr\Log\LoggerInterface;

/**
 * Where one turn's progress goes: the caller's status callback (the web
 * stream forwards each status as an SSE event of the same name) and one log
 * line per status.
 */
final readonly class TurnStatus
{
    private ?\Closure $callback;

    public function __construct(?callable $callback, private LoggerInterface $logger)
    {
        $this->callback = null === $callback ? null : $callback(...);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function report(string $status, string $message, array $metadata = []): void
    {
        ProgressCallback::notify($this->callback, $status, $message, $metadata);

        $this->logger->info('MessageProcessor status', [
            'status' => $status,
            'message' => $message,
            'metadata' => $metadata,
        ]);
    }

    /**
     * The same reporting as a plain callable, for collaborators that take one.
     *
     * @return \Closure(string, string, array<string, mixed>): void
     */
    public function asCallable(): \Closure
    {
        return fn (string $status, string $text, array $meta) => $this->report($status, $text, $meta);
    }
}
