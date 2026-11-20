<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\AI\StructuredOutput\Schema\ResearchCheckSchema;
use App\Service\Research\Run\Ledger\PassageText;
use App\Service\Research\Run\ResearchInputBudget;
use App\Service\Research\Run\ResearchModelChoice;
use App\Service\Research\Run\Stage\CheckBatches;
use App\Service\Research\Run\Stage\CheckVerdicts;
use App\Service\Research\Run\Stage\DraftCheck;
use App\Service\Research\Run\Stage\DraftSentences;
use App\Service\Research\Run\Stage\ResearchModelCaller;
use App\Service\Research\Run\Stage\ResearchPrompts;
use App\Service\Run\RunClaim;

/**
 * The check before a chat run's answer is delivered (03 §3.2). It runs when
 * the answer links to pages this run read, and looks only at the statements
 * that cite one.
 *
 * The research check runs on them unchanged: first the free check (the
 * numbers, dates and names of a statement must be in the page it cites),
 * then, when an admin set a checking model, that model judges the rest
 * against the same pages. The answer already streamed, so nothing is
 * removed from it: a note under it names the statements that failed.
 *
 * It runs once per run. A checker that fails or does not answer adds
 * nothing; a statement without a verdict is not called unbacked.
 */
final readonly class ChatRunCheck
{
    public const TOPIC = 'tools:research_check';

    /** The checker calls one answer may cost. Statements past them get only the free check. */
    public const MAX_MODEL_CALLS = 3;

    /** Runs that end with an answer of the model. */
    private const CHECKED = [ChatRunEnd::Answered, ChatRunEnd::Finished, ChatRunEnd::FinishFallback, ChatRunEnd::NoProgress];

    /** A statement whose words outside its links are fewer than this only names a source. */
    private const MIN_OWN_CHARS = 12;

    private const MICRO = 1_000_000;

    public function __construct(
        private ChatRunWorld $world,
        private ChatRunBudget $budget,
        private ChatRunCheckModel $checkers,
        private ResearchModelCaller $caller,
        private ChatRunEvents $events,
        private DraftCheck $draft = new DraftCheck(),
        private ResearchInputBudget $inputs = new ResearchInputBudget(),
        private CheckBatches $batches = new CheckBatches(),
    ) {
    }

    public function apply(ChatRunState $state, RunClaim $claim): void
    {
        if (!$state->isTerminal() || null !== $state->note) {
            return;
        }
        $state->note = '';
        $answer = $state->answer;
        if (null === $answer || '' === trim($answer) || !\in_array($state->end, self::CHECKED, true)) {
            return;
        }
        $cited = ChatRunSources::cite($answer, ChatRunSources::read($state->messages));
        $statements = $this->statements($cited['report'], $cited['passages']);
        if ([] === $statements) {
            return;
        }
        $failed = $this->freeCheck($cited['report'], $cited['passages'], $statements);
        $ask = array_values(array_filter($statements, static fn (array $s): bool => !isset($failed[$s['id']])));
        $checker = [] === $ask ? null : $this->checkers->pick($state);
        $asked = null !== $checker && $this->modelCheck($state, $claim, $checker, $ask, $failed);
        $listed = [];
        foreach ($statements as $item) {
            if (isset($failed[$item['id']])) {
                $listed[] = $item['shown'];
            }
        }
        $state->note = ChatRunCheckNote::text($listed, $state->locale);
        $this->events->append($state->runId, ChatRunEvents::CHECKED, [
            'statements' => \count($statements),
            'flagged' => \count($listed),
            'model' => $asked,
        ]);
    }

    /**
     * The statements that cite a page of this run, in the shape the checker
     * reads. A link's text names the source, like a marker, so it is not
     * checked; the note shows the statement with it, as the person read it.
     *
     * @param array<string, string> $passages
     *
     * @return list<array{id: string, sentence: string, bare: string, passages: string, shown: string}>
     */
    private function statements(string $report, array $passages): array
    {
        $out = [];
        foreach (DraftSentences::split($report) as $written) {
            $sentence = ChatRunSources::withoutLabels($written);
            $ids = self::ids($sentence);
            if ([] === $ids) {
                continue;
            }
            $bare = DraftSentences::bare($sentence);
            if (!self::saysSomething($bare)) {
                continue;
            }
            $cited = [];
            foreach ($ids as $id) {
                $cited[] = $id.': '.($passages[$id] ?? '');
            }
            $out[] = [
                'id' => 'c'.(\count($out) + 1),
                'sentence' => $sentence,
                'bare' => $bare,
                'passages' => implode("\n", $cited),
                'shown' => DraftSentences::bare(ChatRunSources::shown($written)),
            ];
        }

        return $out;
    }

    /**
     * The free check of the research engine. A statement that cites nothing
     * is not this check's business: the chat may answer from what it knows.
     *
     * @param array<string, string>                                                                    $passages
     * @param list<array{id: string, sentence: string, bare: string, passages: string, shown: string}> $statements
     *
     * @return array<string, true> failed statements by id
     */
    private function freeCheck(string $report, array $passages, array $statements): array
    {
        $flagged = [];
        foreach ($this->draft->check(ChatRunSources::withoutLabels($report), $passages) as $issue) {
            if (DraftCheck::UNCITED !== $issue['reason']) {
                $flagged[PassageText::normalise($issue['statement'])] = true;
            }
        }
        $failed = [];
        foreach ($statements as $item) {
            if (isset($flagged[PassageText::normalise((string) preg_replace(DraftSentences::MARKER, '', $item['sentence']))])) {
                $failed[$item['id']] = true;
            }
        }

        return $failed;
    }

    /**
     * Asks the checking model, batch by batch, while the payer's budget allows.
     *
     * @param list<array{id: string, sentence: string, bare: string, passages: string, shown: string}> $ask
     * @param array<string, true>                                                                      $failed by id
     *
     * @return bool whether the model answered at least once
     */
    private function modelCheck(ChatRunState $state, RunClaim $claim, ResearchModelChoice $checker, array $ask, array &$failed): bool
    {
        $scope = $this->world->resolve($state);
        if (!$scope instanceof ChatRunScope) {
            return false;
        }
        $answered = false;
        $groups = $this->batches->groups($ask, $this->inputs->tokens('checker', $checker->contextWindow) * 4);
        foreach (\array_slice($groups, 0, self::MAX_MODEL_CALLS) as $group) {
            if ($claim->cancelled() || !$this->budget->allows($scope->user)) {
                break;
            }
            $reply = $this->caller->answer(
                $state->userId,
                $checker->id,
                ResearchPrompts::fill(self::TOPIC, []),
                CheckVerdicts::ask($group),
                ResearchCheckSchema::build(),
                null,
                $claim,
            );
            $state->costMicro += max(0, (int) round(($reply->cost ?? 0.0) * self::MICRO));
            $verdicts = $reply->ok ? CheckVerdicts::parse($reply->data, $group) : null;
            if (null === $verdicts) {
                break;
            }
            $answered = true;
            foreach ($group as $item) {
                $verdict = $verdicts[$item['id']]['verdict'] ?? null;
                if (null !== $verdict && CheckVerdicts::SUPPORTED !== $verdict) {
                    $failed[$item['id']] = true;
                }
            }
        }

        return $answered;
    }

    /**
     * @return list<string>
     */
    private static function ids(string $sentence): array
    {
        preg_match_all(DraftSentences::MARKER, $sentence, $markers);
        preg_match_all('/e\d+/i', implode(' ', $markers[0]), $ids);

        return array_values(array_unique(array_map('strtolower', $ids[0])));
    }

    /** A line that only lists a source ("Source: [Title](url)") states nothing to check. */
    private static function saysSomething(string $bare): bool
    {
        $own = (string) preg_replace(DraftSentences::MARKER, '', $bare);

        return mb_strlen((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $own)) >= self::MIN_OWN_CHARS;
    }
}
