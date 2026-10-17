<?php

namespace App\Service\Message\Classification;

use App\Service\SelfAware\SelfAwareConfig;

/**
 * A meta-question about the product. Every layer that skips the AI sorter
 * (the fast path, the embedding router, the deferral) steps aside for it:
 * only the sorter can pick the `synaplan` topic, whose prompt carries the
 * product knowledge.
 */
final readonly class SelfAwareQuestion
{
    /**
     * Meta-questions about this product (EN/DE/ES/FR/TR). A match only
     * defers to the AI sorter — it never routes by itself.
     *
     * Languages: can you / what can you (en), kannst du / was kannst (de),
     * puedes / qué puedes (es), peux-tu / que peux (fr), yapabilir misin /
     * ne yapabilirsin (tr), plus the word "synaplan".
     */
    private const PATTERN = '/(?:can you|what can you|kannst du|was kannst|puedes|qu[eé] puedes|peux-tu|que peux|yapabilir misin|ne yapabilirsin|synaplan)/iu';

    public function __construct(
        private ?SelfAwareConfig $selfAwareConfig = null,
    ) {
    }

    public function matches(string $text, ?int $userId): bool
    {
        return null !== $this->selfAwareConfig
            && $this->selfAwareConfig->isEnabled($userId)
            && 1 === preg_match(self::PATTERN, $text);
    }
}
