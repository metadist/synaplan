<?php

declare(strict_types=1);

namespace App\Service\Message\Chat;

use App\AI\Stream\StreamChunk;

/**
 * Holds the first characters of a streamed answer back until they are known
 * not to be a {@see FreshInfoRequest} marker.
 *
 * Reasoning, finish and tool-call chunks pass through at once; only answer
 * text waits, and only while it is still a prefix of the marker — for a
 * normal answer that is the first chunk. A confirmed marker is swallowed
 * whole, so the person never sees it, and the search phrase is read from
 * it when the stream ends.
 */
final class FreshInfoGuard
{
    private string $held = '';

    /** Null while undecided. */
    private ?bool $isRequest = null;

    private bool $typedChunks = false;

    /** @var callable(string|array<string, mixed>, array<string, mixed>): void */
    private $forward;

    /**
     * @param callable(string|array<string, mixed>, array<string, mixed>): void $forward
     */
    public function __construct(callable $forward)
    {
        $this->forward = $forward;
    }

    /**
     * @param string|array<string, mixed> $chunk
     * @param array<string, mixed>        $metadata
     */
    public function __invoke(string|array $chunk, array $metadata = []): void
    {
        if (!self::isAnswerText($chunk)) {
            ($this->forward)($chunk, $metadata);

            return;
        }
        if (false === $this->isRequest) {
            ($this->forward)($chunk, $metadata);

            return;
        }

        $this->typedChunks = $this->typedChunks || is_array($chunk);
        $this->held .= StreamChunk::visibleText($chunk);
        if (true === $this->isRequest) {
            return;
        }
        if (str_starts_with(ltrim($this->held), FreshInfoRequest::MARKER_OPEN)) {
            $this->isRequest = true;

            return;
        }
        if (FreshInfoRequest::couldBecomeMarker($this->held)) {
            return;
        }
        $this->release($metadata);
    }

    /** Text still held when the stream ended was an answer after all. */
    public function finish(): void
    {
        if (null === $this->isRequest && '' !== $this->held) {
            $this->release([]);
        }
        $this->isRequest ??= false;
    }

    /** The search phrase of a confirmed request, or null for an ordinary answer. */
    public function requestedQuery(): ?string
    {
        return true === $this->isRequest ? FreshInfoRequest::parse($this->held) : null;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function release(array $metadata): void
    {
        $this->isRequest = false;
        $text = $this->held;
        $this->held = '';
        if ('' === $text) {
            return;
        }
        ($this->forward)($this->typedChunks ? ['type' => 'content', 'content' => $text] : $text, $metadata);
    }

    /**
     * @param string|array<string, mixed> $chunk
     */
    private static function isAnswerText(string|array $chunk): bool
    {
        if (is_string($chunk)) {
            return true;
        }

        return 'content' === ($chunk['type'] ?? 'content') && isset($chunk['content']);
    }
}
