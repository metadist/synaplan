<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Service\Research\Run\Lead\LeadConversation;
use App\Service\Research\Run\Ledger\EvidenceLedger;

/**
 * One run as it is stored. A finished run always carries an outcome, and a
 * running one does not, so a screen never shows a result that has not ended
 * and never ends without a sentence.
 */
final class ResearchRunState
{
    public ?\DateTimeImmutable $expiresAt = null;

    /** The run this one continues, when it is a follow-up. */
    public ?string $parent = null;

    /** question, revise, deeper or refresh. Null when the run was started on its own. */
    public ?string $followUp = null;

    /** What the person asked this follow-up to do. */
    public string $instruction = '';

    /** The parent report, so a revision checks only the sentences that changed. */
    public ?string $priorReport = null;

    /** The start key, so deleting the run also forgets the retry. */
    public ?string $idempotencyKey = null;

    /** The last round found nothing new, so the next decision must change strategy. */
    public bool $strategyHint = false;

    /** Evidence cards at the start of the current round. Not stored. */
    public int $evidenceBeforeRound = 0;

    /** Tool turns of a lead that calls tools. Empty in round mode. */
    public LeadConversation $conversation;

    /** Questions the run is waiting on. Null once they are all answered or skipped. */
    public ?ResearchQuestions $questions = null;

    /** How many questions this run has already asked, counted against the depth's budget. */
    public int $questionsAsked = 0;

    /**
     * Answers already given, kept after the wait ends so the next step can use them.
     *
     * @var list<array{question: string, answer: string}>
     */
    public array $replies = [];

    public function __construct(
        public readonly ResearchRunRef $ref,
        public ResearchStatus $status,
        public EvidenceLedger $ledger,
        public ResearchBudget $budget,
        public int $expectedSeq = 1,
        public int $checkpointSeq = 0,
        public ?ResearchOutcome $outcome = null,
        public ?\DateTimeImmutable $updatedAt = null,
    ) {
        $this->conversation = new LeadConversation();
        $this->assertShape();
    }

    public static function open(ResearchRunRef $ref, ResearchBudget $budget, ?EvidenceLedger $ledger = null): self
    {
        return new self(
            $ref,
            ResearchStatus::Briefing,
            $ledger ?? new EvidenceLedger(),
            $budget,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toStorage(
        ?int $expectedSeq = null,
        ?int $checkpointSeq = null,
        ?\DateTimeImmutable $updatedAt = null,
        ?\DateTimeImmutable $expiresAt = null,
    ): array {
        $updated = $updatedAt ?? $this->updatedAt ?? $this->ref->createdAt;
        $expires = $expiresAt ?? $this->expiresAt;

        return [
            'id' => $this->ref->id,
            'user_id' => $this->ref->userId,
            'depth' => $this->ref->depth->value,
            'question' => $this->ref->question,
            'chat_id' => $this->ref->chatId,
            'created_at' => self::stamp($this->ref->createdAt),
            'updated_at' => self::stamp($updated),
            'expires_at' => null === $expires ? null : self::stamp($expires),
            'status' => $this->status->value,
            'expected_seq' => $expectedSeq ?? $this->expectedSeq,
            'checkpoint_seq' => $checkpointSeq ?? $this->checkpointSeq,
            'outcome' => $this->outcome?->toArray(),
            'ledger' => $this->ledger->toArray(),
            'budget' => $this->budget->export(),
            'parent' => $this->parent,
            'follow_up' => $this->followUp,
            'instruction' => $this->instruction,
            'prior_report' => $this->priorReport,
            'idempotency_key' => $this->idempotencyKey,
            'strategy_hint' => $this->strategyHint,
            'conversation' => $this->conversation->toArray(),
            'questions' => $this->questions?->toArray(),
            'questions_asked' => $this->questionsAsked,
            'replies' => $this->replies,
        ];
    }

    public function encode(
        ?int $expectedSeq = null,
        ?int $checkpointSeq = null,
        ?\DateTimeImmutable $updatedAt = null,
        ?\DateTimeImmutable $expiresAt = null,
    ): string {
        return json_encode(
            $this->toStorage($expectedSeq, $checkpointSeq, $updatedAt, $expiresAt),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    public static function decode(string $json): ?self
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }

        try {
            return self::fromStorage($data);
        } catch (\InvalidArgumentException|\ValueError|\DateMalformedStringException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromStorage(array $data): self
    {
        $depth = ResearchDepth::from((string) ($data['depth'] ?? ''));
        $ledger = EvidenceLedger::fromArray(is_array($data['ledger'] ?? null) ? $data['ledger'] : []);
        $budgetData = $data['budget'] ?? null;
        if (!is_array($budgetData)) {
            throw new \InvalidArgumentException('A research budget is incomplete.');
        }
        $outcome = null;
        if (isset($data['outcome']) && is_array($data['outcome'])) {
            $outcome = ResearchOutcome::fromArray($data['outcome']);
        }
        $chatId = $data['chat_id'] ?? null;
        $state = new self(
            new ResearchRunRef(
                (string) ($data['id'] ?? ''),
                (int) ($data['user_id'] ?? 0),
                $depth,
                (string) ($data['question'] ?? ''),
                is_numeric($chatId) ? (int) $chatId : null,
                new \DateTimeImmutable((string) ($data['created_at'] ?? '')),
            ),
            ResearchStatus::from((string) ($data['status'] ?? '')),
            $ledger,
            ResearchBudget::fromExport($depth, $budgetData),
            (int) ($data['expected_seq'] ?? 0),
            (int) ($data['checkpoint_seq'] ?? -1),
            $outcome,
            new \DateTimeImmutable((string) ($data['updated_at'] ?? '')),
        );
        $expiresAt = $data['expires_at'] ?? null;
        if (is_string($expiresAt) && '' !== $expiresAt) {
            $state->expiresAt = new \DateTimeImmutable($expiresAt);
        }
        $parent = $data['parent'] ?? null;
        $state->parent = is_string($parent) && ResearchIds::isValid($parent) ? $parent : null;
        $followUp = $data['follow_up'] ?? null;
        $state->followUp = is_string($followUp) && in_array($followUp, ResearchFollowUp::KINDS, true) ? $followUp : null;
        $instruction = $data['instruction'] ?? '';
        $state->instruction = is_string($instruction) ? $instruction : '';
        $prior = $data['prior_report'] ?? null;
        $state->priorReport = is_string($prior) && '' !== trim($prior) ? $prior : null;
        $idempotency = $data['idempotency_key'] ?? null;
        $state->idempotencyKey = is_string($idempotency) && '' !== $idempotency ? $idempotency : null;
        $state->strategyHint = true === ($data['strategy_hint'] ?? false);
        $conversation = $data['conversation'] ?? null;
        if (is_array($conversation)) {
            $state->conversation = LeadConversation::fromArray($conversation);
        }
        $questions = $data['questions'] ?? null;
        if (is_array($questions)) {
            $state->questions = ResearchQuestions::fromArray($questions);
        }
        $asked = $data['questions_asked'] ?? 0;
        $state->questionsAsked = is_numeric($asked) ? max(0, (int) $asked) : 0;
        $state->replies = self::replies($data['replies'] ?? null);

        return $state;
    }

    public function advance(ResearchStatus $status): void
    {
        if ($status->isTerminal()) {
            throw new \InvalidArgumentException('A running research step cannot finish the run.');
        }
        $this->status = $status;
        $this->assertShape();
    }

    public function finish(ResearchStatus $status, ResearchOutcome $outcome): void
    {
        if (!$status->isTerminal()) {
            throw new \InvalidArgumentException('A finished research run needs an outcome, and a running one does not.');
        }
        $this->status = $status;
        $this->outcome = $outcome;
        $this->assertShape();
    }

    public function assertShape(): void
    {
        $finished = $this->status->isTerminal();
        if ($finished !== (null !== $this->outcome)) {
            throw new \InvalidArgumentException('A finished research run needs an outcome, and a running one does not.');
        }
        if ($this->expectedSeq < 1 || $this->checkpointSeq !== $this->expectedSeq - 1) {
            throw new \InvalidArgumentException('Research progress is inconsistent.');
        }
    }

    /**
     * @return list<array{question: string, answer: string}>
     */
    private static function replies(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }
        $replies = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['question'] ?? null) || !is_string($row['answer'] ?? null)) {
                continue;
            }
            $replies[] = ['question' => $row['question'], 'answer' => $row['answer']];
        }

        return $replies;
    }

    private static function stamp(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
    }
}
