<?php

declare(strict_types=1);

namespace App\AI\Import;

/**
 * Result of an opt-in capability probe: did the model answer a tiny chat and a
 * tiny embeddings request? Used to refine the name-based tag guess before the
 * admin applies the import.
 */
final readonly class ProbeResult
{
    public const OK = 'ok';
    public const FAIL = 'fail';
    public const SKIPPED = 'skipped';

    public function __construct(
        public string $chat,
        public string $embeddings,
        public int $ms,
    ) {
    }

    public static function skipped(): self
    {
        return new self(self::SKIPPED, self::SKIPPED, 0);
    }

    /**
     * Refine a name-based tag guess with what the endpoint actually answered:
     * a working embeddings call adds `vectorize`, a failing chat call removes
     * `chat`. Never returns an empty list — if the probe would remove every
     * tag, the original guess is kept (the model clearly exists).
     *
     * @param list<string> $guessed
     *
     * @return list<string>
     */
    public function overrideTags(array $guessed): array
    {
        $tags = $guessed;

        if (self::OK === $this->embeddings && !in_array('vectorize', $tags, true)) {
            $tags[] = 'vectorize';
        }
        if (self::FAIL === $this->chat) {
            $tags = array_values(array_filter($tags, static fn (string $t): bool => 'chat' !== $t));
        }

        return [] === $tags ? $guessed : $tags;
    }

    /**
     * @return array{chat: string, embeddings: string, ms: int}
     */
    public function toArray(): array
    {
        return ['chat' => $this->chat, 'embeddings' => $this->embeddings, 'ms' => $this->ms];
    }
}
