<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

final readonly class ResearchRunRef
{
    public string $question;

    public function __construct(
        public string $id,
        public int $userId,
        public ResearchDepth $depth,
        string $question,
        public ?int $chatId,
        public \DateTimeImmutable $createdAt,
    ) {
        ResearchIds::assert($id);
        if ($userId < 1) {
            throw new \InvalidArgumentException('A research run needs a person.');
        }
        $question = trim($question);
        if ('' === $question || mb_strlen($question) > 4000) {
            throw new \InvalidArgumentException('A research question needs between 1 and 4000 characters.');
        }
        $this->question = $question;
        if (null !== $chatId && $chatId < 1) {
            throw new \InvalidArgumentException('A research run needs the chat it belongs to.');
        }
    }

    public static function start(
        int $userId,
        ResearchDepth $depth,
        string $question,
        ?int $chatId = null,
        ?\DateTimeImmutable $createdAt = null,
    ): self {
        return new self(ResearchIds::newId(), $userId, $depth, $question, $chatId, $createdAt ?? new \DateTimeImmutable());
    }
}
