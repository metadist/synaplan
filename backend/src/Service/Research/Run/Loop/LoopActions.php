<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Loop;

use App\Service\Research\Run\Ledger\EvidenceLedger;
use App\Service\Research\Run\Ledger\PlainText;
use App\Service\Research\Run\Ledger\Source;
use App\Service\Research\Run\ResearchBudget;

/**
 * Keeps the actions a round may run. A finish drops every action. Anything
 * unknown, repeated, or past the budget is dropped and not reported back.
 * The budget counts the actions kept earlier in the same decision, so six
 * searches with one search left keep one.
 */
final readonly class LoopActions
{
    private const FINISH = ['covered', 'budget', 'no_progress'];

    private const SCOPES = ['web', 'files', 'both'];

    private const QUERY_CHARS = 200;

    private const QUESTION_CHARS = 300;

    private const OPTION_CHARS = 120;

    private const MIN_OPTIONS = 2;

    private const MAX_OPTIONS = 4;

    /**
     * @param array<string, mixed> $decision
     */
    public function finish(array $decision): ?string
    {
        $finish = $decision['finish'] ?? null;
        if (!is_string($finish) || !in_array($finish, self::FINISH, true)) {
            return null;
        }

        return $finish;
    }

    /**
     * @param array<string, mixed> $decision
     *
     * @return list<LoopAction>
     */
    public function keep(array $decision, EvidenceLedger $ledger, ResearchBudget $budget, int $questionsAsked): array
    {
        $actions = $decision['actions'] ?? [];
        if (null !== $this->finish($decision) || !is_array($actions)) {
            return [];
        }

        $extras = $budget->extras();
        $left = [
            LoopAction::SEARCH => $budget->searchesLeft(),
            LoopAction::READ => $budget->sourcesLeft(),
            LoopAction::ASK_USER => max(0, $extras->questions - $questionsAsked),
        ];
        $seen = $this->asked($ledger);
        $kept = [];
        foreach ($actions as $action) {
            if (count($kept) >= $extras->maxActions) {
                break;
            }
            $one = is_array($action) ? $this->one($action, $ledger) : null;
            if (null === $one) {
                continue;
            }
            $key = $this->key($one);
            if (isset($seen[$key]) || (isset($left[$one->type]) && $left[$one->type] < 1)) {
                continue;
            }
            if (isset($left[$one->type])) {
                --$left[$one->type];
            }
            $seen[$key] = true;
            $kept[] = $one;
        }

        return $kept;
    }

    /**
     * @param array<mixed> $action
     */
    private function one(array $action, EvidenceLedger $ledger): ?LoopAction
    {
        return match ($action['type'] ?? null) {
            LoopAction::SEARCH => $this->search($action, $ledger),
            LoopAction::READ => $this->read($action, $ledger),
            LoopAction::REREAD => $this->reread($action, $ledger),
            LoopAction::ASK_USER => $this->ask($action),
            default => null,
        };
    }

    /**
     * @param array<mixed> $action
     */
    private function search(array $action, EvidenceLedger $ledger): ?LoopAction
    {
        $point = $action['point'] ?? null;
        $query = $this->text($action['query'] ?? null, self::QUERY_CHARS);
        $scope = $action['scope'] ?? 'web';
        if (!is_string($point) || !$this->hasPoint($ledger, $point) || null === $query
            || !is_string($scope) || !in_array($scope, self::SCOPES, true)) {
            return null;
        }

        return LoopAction::search($point, $query, $this->language($action['language'] ?? null), $scope);
    }

    /**
     * @param array<mixed> $action
     */
    private function read(array $action, EvidenceLedger $ledger): ?LoopAction
    {
        $source = $this->source($action, $ledger, [Source::FOUND, Source::READING]);

        return null === $source ? null : LoopAction::read($source);
    }

    /**
     * @param array<mixed> $action
     */
    private function reread(array $action, EvidenceLedger $ledger): ?LoopAction
    {
        $source = $this->source($action, $ledger, [Source::READ]);
        $question = $this->text($action['question'] ?? null, self::QUESTION_CHARS);

        return null === $source || null === $question ? null : LoopAction::reread($source, $question);
    }

    /**
     * @param array<mixed> $action
     */
    private function ask(array $action): ?LoopAction
    {
        $question = $this->text($action['question'] ?? null, self::QUESTION_CHARS);
        $options = $action['options'] ?? null;
        if (null === $question || !is_array($options)) {
            return null;
        }
        $kept = [];
        foreach ($options as $option) {
            $text = $this->text($option, self::OPTION_CHARS);
            if (null !== $text && !in_array($text, $kept, true)) {
                $kept[] = $text;
            }
        }
        if (count($kept) < self::MIN_OPTIONS || count($kept) > self::MAX_OPTIONS) {
            return null;
        }

        return LoopAction::askUser($question, $kept);
    }

    /**
     * @param array<mixed> $action
     * @param list<string> $statuses
     */
    private function source(array $action, EvidenceLedger $ledger, array $statuses): ?string
    {
        $id = $action['source'] ?? null;
        if (!is_string($id)) {
            return null;
        }
        $source = $ledger->source($id);

        return null !== $source && in_array($source->status, $statuses, true) ? $id : null;
    }

    private function hasPoint(EvidenceLedger $ledger, string $id): bool
    {
        foreach ($ledger->points() as $point) {
            if ($point->id === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * A language the lead got wrong falls back to the brief's language; it
     * does not cost the search.
     */
    private function language(mixed $language): ?string
    {
        if (!is_string($language)) {
            return null;
        }
        $language = strtolower(trim($language));

        return 1 === preg_match('/^[a-z]{2}$/', $language) ? $language : null;
    }

    /**
     * One line of model output, without control characters, cut to `$max`.
     */
    private function text(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $line = rtrim(mb_substr(PlainText::line($value, PHP_INT_MAX), 0, $max));

        return '' === $line ? null : $line;
    }

    /**
     * Searches repeat when the same words run in the same scope, whatever
     * point asked for them: the results would be the same.
     */
    private function key(LoopAction $action): string
    {
        return match ($action->type) {
            LoopAction::SEARCH => 'search '.$action->scope.' '.$this->norm((string) $action->query),
            LoopAction::READ => 'read '.$action->source,
            LoopAction::REREAD => 'reread '.$action->source.' '.$this->norm((string) $action->question),
            default => 'ask '.$this->norm((string) $action->question),
        };
    }

    /**
     * @return array<string, true>
     */
    private function asked(EvidenceLedger $ledger): array
    {
        $seen = [];
        foreach ($ledger->queries() as $query) {
            $seen['search '.$query->scope.' '.$this->norm($query->query)] = true;
        }

        return $seen;
    }

    private function norm(string $text): string
    {
        return mb_strtolower(PlainText::line($text, PHP_INT_MAX));
    }
}
