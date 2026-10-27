<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Lead;

use App\AI\ToolCalling\ToolCall;
use App\Service\Research\Run\Ledger\PlainText;
use App\Service\Research\Run\Ledger\Source;
use App\Service\Research\Run\Stage\ReadStage;
use App\Service\Research\Run\Stage\ResearchPrompts;
use App\Service\Research\Run\Stage\StageContext;
use App\Service\Research\Run\Stage\StageResult;

/**
 * Reads the sources the lead named. The whole call is refused when any id
 * is unknown or the budget cannot cover every source, so a partial read
 * cannot look like the list was finished. The result is cards, never the page.
 */
final readonly class LeadRead
{
    private const WRITER_CALL = 2;

    public function __construct(private ReadStage $read)
    {
    }

    public function sources(ToolCall $call, StageContext $ctx): LeadToolOutcome
    {
        $ids = $this->ids($call->arguments['ids'] ?? null);
        $point = $call->arguments['point'] ?? null;
        if (null === $ids || !is_string($point) || !$this->hasPoint($ctx, $point)) {
            return $this->refuse('not_allowed', $ctx);
        }

        return $this->read($ctx, $ids, [Source::FOUND, Source::READING], null);
    }

    public function again(ToolCall $call, StageContext $ctx): LeadToolOutcome
    {
        $source = $call->arguments['source'] ?? null;
        $question = $this->question($call->arguments['question'] ?? null);
        if (!is_string($source) || null === $question) {
            return $this->refuse('not_allowed', $ctx);
        }

        return $this->read($ctx, [$source], [Source::READ], $question);
    }

    /**
     * @param list<string> $ids
     * @param list<string> $statuses
     */
    private function read(StageContext $ctx, array $ids, array $statuses, ?string $question): LeadToolOutcome
    {
        $known = $this->known($ctx, $ids, $statuses);
        if (true !== $known) {
            return $this->refuse($known, $ctx);
        }
        $budget = $ctx->state->budget;
        if ($budget->sourcesLeft() < count($ids) || $budget->callsLeft() < count($ids) + self::WRITER_CALL) {
            return $this->refuse('budget_used', $ctx);
        }
        $read = $this->read->readListed($ctx, $ids, $question);
        if ($read instanceof StageResult) {
            return new LeadToolOutcome('', $read);
        }

        return new LeadToolOutcome(LeadToolReply::ok([
            'cards' => array_map($this->card(...), $read['cards']),
            'blocked' => array_map(
                static fn (array $row): array => ['id' => $row['id'], 'reason' => ResearchPrompts::data($row['reason'])],
                $read['blocked'],
            ),
        ], $ctx->state->budget));
    }

    /**
     * The fields the lead needs to judge a point. Quotes come from the page,
     * so every text is marked as data like the search results.
     *
     * @param array<string, mixed> $card
     *
     * @return array<string, mixed>
     */
    private function card(array $card): array
    {
        $quotes = [];
        foreach (is_array($card['quotes'] ?? null) ? $card['quotes'] : [] as $quote) {
            if (is_array($quote) && is_string($quote['text'] ?? null) && true === ($quote['verified'] ?? false)) {
                $quotes[] = ResearchPrompts::data($quote['text']);
            }
        }

        return [
            'id' => $card['id'] ?? null,
            'source' => $card['source'] ?? null,
            'point' => $card['point'] ?? null,
            'fact' => ResearchPrompts::data(is_string($card['fact'] ?? null) ? $card['fact'] : ''),
            'quotes' => $quotes,
            'confidence' => $card['confidence'] ?? null,
        ];
    }

    /**
     * @param list<string> $ids
     * @param list<string> $statuses
     */
    private function known(StageContext $ctx, array $ids, array $statuses): string|true
    {
        foreach ($ids as $id) {
            $source = $ctx->state->ledger->source($id);
            if (null === $source || !$this->read->canOpen($source)) {
                return 'unknown_id';
            }
            if (!in_array($source->status, $statuses, true)) {
                return 'not_allowed';
            }
        }

        return true;
    }

    /**
     * @return list<string>|null
     */
    private function ids(mixed $ids): ?array
    {
        if (!is_array($ids) || [] === $ids || count($ids) > 8) {
            return null;
        }
        $kept = [];
        foreach ($ids as $id) {
            if (!is_string($id) || 1 !== preg_match('/^s[1-9][0-9]*$/', $id) || in_array($id, $kept, true)) {
                return null;
            }
            $kept[] = $id;
        }

        return $kept;
    }

    private function question(mixed $question): ?string
    {
        if (!is_string($question)) {
            return null;
        }
        $line = rtrim(mb_substr(PlainText::line($question, PHP_INT_MAX), 0, 300));

        return '' === $line ? null : $line;
    }

    private function hasPoint(StageContext $ctx, string $id): bool
    {
        foreach ($ctx->state->ledger->points() as $point) {
            if ($point->id === $id) {
                return true;
            }
        }

        return false;
    }

    private function refuse(string $code, StageContext $ctx): LeadToolOutcome
    {
        return new LeadToolOutcome(LeadToolReply::error($code, $ctx->state->budget));
    }
}
