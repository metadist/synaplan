<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\AI\StructuredOutput\Schema\ResearchCheckSchema;
use App\Service\Research\Run\Ledger\PassageText;
use App\Service\Research\Run\ResearchInputBudget;
use App\Service\Research\Run\ResearchStatus;

/**
 * Checks the report. The free check runs first and costs nothing. The
 * checker model then judges every cited statement that passed it, in
 * batches that fit its input budget. A statement that is not supported is
 * removed and listed. The report ends with the count. When the budget runs
 * out after the first batch, the statements not yet checked count as not
 * confirmed, so the paid report is still delivered.
 */
final readonly class CheckStage implements ResearchStage
{
    /** @var list<string> */
    private const VERDICTS = ['supported', 'partial', 'unsupported', 'contradicted'];

    public function __construct(
        private StageCall $calls,
        private DraftCheck $draft,
        private ResearchInputBudget $budgets,
        private CheckBatches $batches = new CheckBatches(),
        private int $batchChars = 0,
    ) {
    }

    public function run(StageContext $ctx): StageResult
    {
        $report = $ctx->state->ledger->report();
        if (null === $report || '' === trim($report)) {
            return new StageResult('deliver');
        }
        $ctx->state->advance(ResearchStatus::Checking);
        $ctx->events->append($ctx->state->ref->id, 'checking', []);

        $passages = $this->passages($ctx);
        $sorted = $this->sort(CheckReport::withoutNotConfirmed($report), $passages);
        $verdicts = [];
        foreach ($this->batches->groups($sorted['ask'], $this->chars()) as $index => $group) {
            if ($index > 0 && !$ctx->state->budget->canCall() && !$ctx->stop->requested()) {
                break;
            }
            $called = $this->calls->once(
                $ctx,
                'checker',
                $ctx->roles->checker,
                ResearchPrompts::fill('tools:research_check', []),
                $this->user($group),
                ResearchCheckSchema::build(),
            );
            if ($called->stop instanceof StageResult) {
                return $called->stop;
            }
            $parsed = $this->verdicts($called->reply?->data, $group);
            if (null === $parsed) {
                return StageEnds::model($ctx, 'checker');
            }
            $verdicts += $parsed;
        }

        $counts = $this->counts($sorted, $verdicts);
        $brief = $ctx->state->ledger->brief();
        $ctx->state->ledger->setReport(CheckReport::apply(
            $report,
            $sorted['drop'],
            $verdicts,
            $sorted['ask'],
            null !== $brief ? $brief->language : 'en',
            $counts,
            $ctx->roles->checkerSameFamily,
        ));
        $ctx->state->ledger->setCheck($counts['statements'], $counts['supported'], $counts['removed']);
        $ctx->events->append($ctx->state->ref->id, 'check', [
            'statements' => $counts['statements'],
            'supported' => $counts['supported'],
            'softened' => 0,
            'removed' => $counts['removed'],
        ]);

        return new StageResult('deliver');
    }

    /**
     * @param array<string, string> $passages
     *
     * @return array{drop: list<array{sentence: string, text: string}>, ask: list<array{id: string, sentence: string, bare: string, passages: string}>}
     */
    private function sort(string $report, array $passages): array
    {
        $flagged = [];
        foreach ($this->draft->check($report, $passages) as $issue) {
            $flagged[PassageText::normalise($issue['statement'])] = $issue['reason'];
        }
        $drop = [];
        $ask = [];
        $number = 0;
        foreach (DraftSentences::split($report) as $sentence) {
            $bare = trim((string) preg_replace(DraftSentences::MARKER, '', $sentence));
            $bare = trim((string) preg_replace('/\s+/u', ' ', $bare));
            $reason = $flagged[PassageText::normalise($bare)] ?? null;
            if (null !== $reason) {
                $drop[] = ['sentence' => $sentence, 'text' => $bare];
                continue;
            }
            if ([] === $this->ids($sentence)) {
                continue;
            }
            ++$number;
            $ask[] = [
                'id' => 'c'.$number,
                'sentence' => $sentence,
                'bare' => $bare,
                'passages' => $this->citedText($this->ids($sentence), $passages),
            ];
        }

        return ['drop' => $drop, 'ask' => $ask];
    }

    /**
     * @param array{drop: list<array{sentence: string, text: string}>, ask: list<array{id: string, sentence: string, bare: string, passages: string}>} $sorted
     * @param array<string, string>                                                                                                                    $verdicts
     *
     * @return array{statements: int, supported: int, removed: int}
     */
    private function counts(array $sorted, array $verdicts): array
    {
        $supported = 0;
        foreach ($sorted['ask'] as $item) {
            if ('supported' === ($verdicts[$item['id']] ?? 'unsupported')) {
                ++$supported;
            }
        }
        $statements = count($sorted['drop']) + count($sorted['ask']);

        return [
            'statements' => $statements,
            'supported' => $supported,
            'removed' => $statements - $supported,
        ];
    }

    /**
     * Only the statements of this batch count, each with its first verdict.
     * A row that is not usable is skipped, so that statement stays without
     * a verdict and is treated as unsupported.
     *
     * @param list<array{id: string, sentence: string, bare: string, passages: string}> $group
     *
     * @return array<string, string>|null null when the answer has no verdict list at all
     */
    private function verdicts(mixed $data, array $group): ?array
    {
        if (!is_array($data) || !is_array($data['verdicts'] ?? null)) {
            return null;
        }
        $asked = array_fill_keys(array_column($group, 'id'), true);
        $out = [];
        foreach ($data['verdicts'] as $row) {
            if (!is_array($row) || !is_string($row['statement'] ?? null) || !is_string($row['verdict'] ?? null)) {
                continue;
            }
            $id = $row['statement'];
            if (!isset($asked[$id]) || isset($out[$id]) || !in_array($row['verdict'], self::VERDICTS, true)) {
                continue;
            }
            $out[$id] = $row['verdict'];
        }

        return $out;
    }

    /**
     * @param list<array{id: string, sentence: string, bare: string, passages: string}> $group
     */
    private function user(array $group): string
    {
        $lines = [];
        foreach ($group as $item) {
            $lines[] = '<statement id="'.$item['id'].'">';
            $lines[] = '<text>'.ResearchPrompts::data($item['bare']).'</text>';
            $lines[] = '<passages>';
            $lines[] = ResearchPrompts::data($item['passages']);
            $lines[] = '</passages>';
            $lines[] = '</statement>';
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, string>
     */
    private function passages(StageContext $ctx): array
    {
        $out = [];
        foreach ($ctx->state->ledger->evidenceForWriter() as $cards) {
            foreach ($cards as $card) {
                $parts = [];
                foreach (is_array($card['quotes'] ?? null) ? $card['quotes'] : [] as $quote) {
                    if (is_array($quote) && is_string($quote['text'] ?? null) && '' !== $quote['text']) {
                        $parts[] = $quote['text'];
                    }
                }
                if ([] === $parts && is_string($card['fact'] ?? null)) {
                    $parts[] = $card['fact'];
                }
                $out[(string) $card['id']] = implode("\n", $parts);
            }
        }

        return $out;
    }

    /**
     * @param list<string>          $ids
     * @param array<string, string> $passages
     */
    private function citedText(array $ids, array $passages): string
    {
        $parts = [];
        foreach ($ids as $id) {
            $parts[] = $id.': '.($passages[$id] ?? '');
        }

        return implode("\n", $parts);
    }

    /**
     * @return list<string>
     */
    private function ids(string $sentence): array
    {
        preg_match_all('/e\d+/i', implode(' ', $this->markers($sentence)), $ids);

        return array_values(array_unique(array_map('strtolower', $ids[0])));
    }

    /**
     * @return list<string>
     */
    private function markers(string $sentence): array
    {
        preg_match_all(DraftSentences::MARKER, $sentence, $markers);

        return $markers[0];
    }

    private function chars(): int
    {
        return $this->batchChars > 0 ? $this->batchChars : $this->budgets->tokens('checker', null) * 4;
    }
}
