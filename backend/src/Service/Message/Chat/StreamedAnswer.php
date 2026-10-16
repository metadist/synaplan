<?php

declare(strict_types=1);

namespace App\Service\Message\Chat;

use App\Service\Infrastructure\PerfTimer;

/**
 * Watches a streamed answer on its way to the client: the visible text, the
 * first visible token (marked as `provider_ttft`, the wait the person
 * perceives between send and the first character) and the finish reason.
 * Every chunk is forwarded unchanged.
 */
final class StreamedAnswer
{
    public string $text = '';

    public bool $sawVisibleToken = false;

    public ?string $finishReason = null;

    /** @var callable(string|array<string, mixed>, array<string, mixed>): void */
    private $forward;

    /**
     * @param callable(string|array<string, mixed>, array<string, mixed>): void $forward
     */
    public function __construct(callable $forward, private readonly PerfTimer $perfTimer)
    {
        $this->forward = $forward;
    }

    /**
     * @param string|array<string, mixed> $chunk    plain text from older providers, typed chunks from newer ones
     * @param array<string, mixed>        $metadata
     */
    public function __invoke(string|array $chunk, array $metadata = []): void
    {
        if (is_array($chunk) && 'finish' === ($chunk['type'] ?? null) && is_string($chunk['finish_reason'] ?? null) && '' !== $chunk['finish_reason']) {
            $this->finishReason = $chunk['finish_reason'];
        }

        if (!$this->sawVisibleToken && self::isVisible($chunk)) {
            $this->perfTimer->mark('provider_ttft');
            $this->sawVisibleToken = true;
        }

        if (is_array($chunk)) {
            if (isset($chunk['type']) && 'content' === $chunk['type'] && isset($chunk['content'])) {
                $this->text .= $chunk['content'];
            }
        } else {
            $this->text .= $chunk;
        }

        ($this->forward)($chunk, $metadata);
    }

    /**
     * @param string|array<string, mixed> $chunk
     */
    private static function isVisible(string|array $chunk): bool
    {
        if (!is_array($chunk)) {
            return '' !== trim($chunk);
        }

        return in_array($chunk['type'] ?? null, ['content', 'reasoning'], true)
            && '' !== trim((string) ($chunk['content'] ?? ''));
    }
}
