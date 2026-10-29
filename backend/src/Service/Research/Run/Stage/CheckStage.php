<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\AI\StructuredOutput\Schema\ResearchCheckSchema;
use App\Service\Research\Run\Ledger\PassageText;
use App\Service\Research\Run\ResearchDepth;
use App\Service\Research\Run\ResearchFollowUp;
use App\Service\Research\Run\ResearchInputBudget;
use App\Service\Research\Run\ResearchStatus;

/**
 * Checks the report. The free check runs first and costs nothing. The
 * checker model then judges every cited statement that passed it, in
 * batches that fit its input budget. A statement that is not supported is
 * removed and listed. When a thorough run has a partial statement, it
 * tries one revision that keeps the supported part; if that revision adds
 * a fact or keeps a rejected statement, the removal stands. Without a
 * partial statement a revision could only remove, so no call is made. The
 * report ends with
 * the count. When the budget runs out after the first batch, the
 * statements not yet checked count as not confirmed, so the paid report
 * is still delivered.
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
        private CheckRevision $revision = new CheckRevision(new DraftCheck()),
    ) {
    }

    public function run(StageContext $ctx): StageResult
    {
        $original = $ctx->state->ledger->report();
        if (null === $original || '' === trim($original)) {
            return new StageResult('deliver');
        }
        $ctx->state->advance(ResearchStatus::Checking);
        $ctx->events->append($ctx->state->ref->id, 'checking', []);

        $passages = $this->passages($ctx);
        $sorted = $this->sort(CheckReport::withoutNotConfirmed($original), $passages);
        $ask = $sorted['ask'];
        $carried = [];
        if (ResearchFollowUp::REVISE === $ctx->state->followUp) {
            [$ask, $carried] = ChangedSentences::split($ctx->state->priorReport ?? '', $sorted['ask']);
        }
        $verdicts = [];
        foreach ($this->batches->groups($ask, $this->chars()) as $index => $group) {
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
        foreach ($carried as $item) {
            $verdicts[$item['id']] = ['verdict' => 'supported', 'part' => ''];
        }

        CheckVerdictEvents::announce($ctx, $sorted, $verdicts);
        $counts = $this->counts($sorted, $verdicts);
        $brief = $ctx->state->ledger->brief();
        $language = null !== $brief ? $brief->language : 'en';
        $sameProvider = $ctx->roles->checkerSameFamily;
        $report = CheckReport::apply($original, $sorted['drop'], $this->labels($verdicts), $sorted['ask'], $language, $counts, $sameProvider);
        $revised = $this->revise($ctx, $original, $passages, $sorted, $verdicts, $language, $report);
        if ($revised instanceof StageResult) {
            $this->record($ctx, $counts, $counts['supported'], 0);

            return $revised;
        }
        if (is_string($revised)) {
            $tally = $this->tally($sorted, $verdicts);
            $counts = [
                'statements' => $tally['statements'],
                'supported' => $tally['supported'] + $tally['softened'],
                'removed' => $tally['removed'],
            ];
            $report = CheckReport::close($revised, $language, $counts, $sameProvider, $tally['softened']);
            $this->record($ctx, $counts, $tally['supported'], $tally['softened']);
            $ctx->state->ledger->setReport($report);

            return new StageResult('deliver');
        }
        $this->record($ctx, $counts, $counts['supported'], 0);
        $ctx->state->ledger->setReport($report);

        return new StageResult('deliver');
    }

    /**
     * @param array<string, string> $passages
     *
     * @return array{drop: list<array{sentence: string, text: string, reason: string}>, ask: list<array{id: string, sentence: string, bare: string, passages: string}>}
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
            $key = PassageText::normalise((string) preg_replace(DraftSentences::MARKER, '', $sentence));
            $bare = DraftSentences::bare($sentence);
            $reason = $flagged[$key] ?? null;
            if (null !== $reason) {
                $drop[] = ['sentence' => $sentence, 'text' => $bare, 'reason' => $reason];
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
     * @param array<string, array{verdict: string, part: string}>                                                                                      $verdicts
     *
     * @return array{statements: int, supported: int, removed: int}
     */
    private function counts(array $sorted, array $verdicts): array
    {
        $supported = 0;
        foreach ($sorted['ask'] as $item) {
            if ('supported' === ($verdicts[$item['id']]['verdict'] ?? 'unsupported')) {
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
     * @return array<string, array{verdict: string, part: string, note?: string}>|null null when the answer has no verdict list at all
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
            $out[$id] = [
                'verdict' => $row['verdict'],
                'part' => is_string($row['unsupported_part'] ?? null) ? $row['unsupported_part'] : '',
                'note' => is_string($row['note'] ?? null) ? $row['note'] : '',
            ];
        }

        return $out;
    }

    /**
     * @param array<string, array{verdict: string, part: string}> $verdicts
     *
     * @return array<string, string>
     */
    private function labels(array $verdicts): array
    {
        $flat = [];
        foreach ($verdicts as $id => $row) {
            $flat[$id] = $row['verdict'];
        }

        return $flat;
    }

    /**
     * @param array{drop: list<array{sentence: string, text: string}>, ask: list<array{id: string, sentence: string, bare: string, passages: string}>} $sorted
     * @param array<string, array{verdict: string, part: string}>                                                                                      $verdicts
     *
     * @return array{statements: int, supported: int, softened: int, removed: int}
     */
    private function tally(array $sorted, array $verdicts): array
    {
        $supported = 0;
        $softened = 0;
        $removed = count($sorted['drop']);
        foreach ($sorted['ask'] as $item) {
            $verdict = $verdicts[$item['id']]['verdict'] ?? 'unsupported';
            if ('supported' === $verdict) {
                ++$supported;
            } elseif ('partial' === $verdict) {
                ++$softened;
            } else {
                ++$removed;
            }
        }

        return [
            'statements' => $supported + $softened + $removed,
            'supported' => $supported,
            'softened' => $softened,
            'removed' => $removed,
        ];
    }

    /**
     * @param array{statements: int, supported: int, removed: int} $counts
     */
    private function record(StageContext $ctx, array $counts, int $supported, int $softened): void
    {
        $ctx->state->ledger->setCheck($counts['statements'], $counts['supported'], $counts['removed']);
        $ctx->events->append($ctx->state->ref->id, 'check', [
            'statements' => $counts['statements'],
            'supported' => $supported,
            'softened' => $softened,
            'removed' => $counts['removed'],
            'same_provider' => $ctx->roles->checkerSameFamily,
        ]);
    }

    /**
     * @param array<string, string>                                                                                                                    $passages
     * @param array{drop: list<array{sentence: string, text: string}>, ask: list<array{id: string, sentence: string, bare: string, passages: string}>} $sorted
     * @param array<string, array{verdict: string, part: string}>                                                                                      $verdicts
     */
    private function revise(StageContext $ctx, string $original, array $passages, array $sorted, array $verdicts, string $language, string $fallback): string|StageResult|null
    {
        if (!$this->wantsRevision($ctx, $sorted, $verdicts)) {
            return null;
        }
        $ctx->state->ledger->setReport($fallback);
        $called = $this->calls->prose(
            $ctx,
            'writing',
            $ctx->roles->writer,
            ResearchPrompts::fill('tools:research_revise', [
                'NOT_CONFIRMED_TITLE' => CheckReport::notConfirmedTitle($language),
                'REPORT' => ResearchPrompts::data($original),
                'VERDICTS' => ResearchPrompts::data($this->verdictBlock($sorted, $verdicts)),
            ]),
            'Revise the report.',
        );
        if ($called instanceof StageResult) {
            return $called;
        }
        if (!$called instanceof ResearchModelReply || !$this->revision->acceptable($original, $called->text, $passages, $sorted, $verdicts)) {
            return null;
        }

        return $called->text;
    }

    /**
     * @param array{drop: list<array{sentence: string, text: string}>, ask: list<array{id: string, sentence: string, bare: string, passages: string}>} $sorted
     * @param array<string, array{verdict: string, part: string}>                                                                                      $verdicts
     */
    private function wantsRevision(StageContext $ctx, array $sorted, array $verdicts): bool
    {
        if (ResearchDepth::Thorough !== $ctx->state->ref->depth || !$ctx->state->budget->canCall() || $ctx->stop->requested()) {
            return false;
        }
        foreach ($sorted['ask'] as $item) {
            if ('partial' === ($verdicts[$item['id']]['verdict'] ?? 'unsupported')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{drop: list<array{sentence: string, text: string}>, ask: list<array{id: string, sentence: string, bare: string, passages: string}>} $sorted
     * @param array<string, array{verdict: string, part: string}>                                                                                      $verdicts
     */
    private function verdictBlock(array $sorted, array $verdicts): string
    {
        $lines = [];
        foreach ($sorted['drop'] as $item) {
            $lines[] = 'remove: '.$item['text'];
        }
        foreach ($sorted['ask'] as $item) {
            $row = $verdicts[$item['id']] ?? ['verdict' => 'unsupported', 'part' => ''];
            $line = $item['id'].' '.$row['verdict'].': '.$item['bare'];
            if ('partial' === $row['verdict'] && '' !== trim($row['part'])) {
                $line .= ' | unsupported part: '.$row['part'];
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
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
