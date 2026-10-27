<?php

declare(strict_types=1);

namespace App\Service\Message\FileAnalysis;

use App\AI\Service\AiFacade;
use App\Service\Usage\FacadeMeteredChat;

/**
 * How a file-analysis answer leaves the handler: returned as `content`
 * (buffered) or pushed to the stream callback (streamed). Everything else
 * about an analysis is the same on both pipelines.
 */
final readonly class AnalysisReply
{
    private function __construct(private ?\Closure $stream)
    {
    }

    public static function buffered(): self
    {
        return new self(null);
    }

    public static function streamed(callable $streamCallback): self
    {
        return new self($streamCallback(...));
    }

    /**
     * One model call: `chat()` when buffered, `chatStream()` when streamed.
     *
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, mixed>                       $options
     *
     * @return array<string, mixed>
     */
    public function chat(AiFacade $aiFacade, array $messages, int $userId, array $options): array
    {
        return null === $this->stream
            ? $aiFacade->chat($messages, $userId, $options)
            : $aiFacade->chatStream($messages, $this->stream, $userId, $options);
    }

    /**
     * The result for an answer produced through {@see chat()}; a streamed
     * answer has already reached the client.
     *
     * @param array<string, mixed> $response
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    public function fromModel(array $response, array $metadata): array
    {
        $metadata = FacadeMeteredChat::stamp($metadata, $response);

        return null === $this->stream
            ? ['content' => $response['content'], 'metadata' => $metadata]
            : ['metadata' => $metadata];
    }

    /**
     * The result for text written here (failure copy, combined image
     * descriptions); streamed, it goes out as one chunk.
     *
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    public function deliver(string $content, array $metadata): array
    {
        if (null === $this->stream) {
            return ['content' => $content, 'metadata' => $metadata];
        }

        ($this->stream)($content);

        return ['metadata' => $metadata];
    }
}
