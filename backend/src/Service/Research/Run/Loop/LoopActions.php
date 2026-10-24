<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Loop;

use App\Service\Research\Run\Ledger\EvidenceLedger;
use App\Service\Research\Run\Ledger\Source;
use App\Service\Research\Run\ResearchBudget;

/**
 * Keeps the actions a round may run. A finish drops every action. Anything
 * unknown, repeated, or past the budget is dropped and not reported back.
 */
final readonly class LoopActions
{
    private const FINISH = ['covered', 'budget', 'no_progress'];

    private const SCOPES = ['web', 'files', 'both'];

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
    public function keep(array $decision, EvidenceLedger $ledger, ResearchBudget $budget, int $questionsAsked = 0): array
    {
        if (null !== $this->finish($decision)) {
            return [];
        }

        $limits = $this->limits($budget);
        $seen = $this->asked($ledger);
        $used = $budget->toArray();
        $left = [
            LoopAction::SEARCH => $used['searches'][1] - $used['searches'][0],
            LoopAction::READ => $used['sources_read'][1] - $used['sources_read'][0],
            LoopAction::ASK_USER => $limits['questions'] - $questionsAsked,
        ];
        $kept = [];
        $actions = $decision['actions'] ?? [];
        if (!is_array($actions)) {
            return [];
        }

        foreach ($actions as $action) {
            if (count($kept) >= $limits['max_actions']) {
                break;
            }
            if (!is_array($action)) {
                continue;
            }
            $one = $this->one($action, $ledger, $budget, $questionsAsked, $seen);
            if (null === $one) {
                continue;
            }
            if (isset($left[$one->type])) {
                if ($left[$one->type] <= 0) {
                    continue;
                }
                --$left[$one->type];
            }
            $kept[] = $one;
            if (LoopAction::SEARCH === $one->type && null !== $one->point && null !== $one->query) {
                $seen[$one->point.' '.$this->norm($one->query)] = true;
            }
        }

        return $kept;
    }

    /**
     * @param array<mixed>        $action
     * @param array<string, true> $seen
     */
    private function one(array $action, EvidenceLedger $ledger, ResearchBudget $budget, int $questionsAsked, array $seen): ?LoopAction
    {
        $type = $action['type'] ?? null;
        if (!is_string($type)) {
            return null;
        }

        return match ($type) {
            LoopAction::SEARCH => $this->search($action, $ledger, $budget, $seen),
            LoopAction::READ => $this->read($action, $ledger, $budget),
            LoopAction::REREAD => $this->reread($action, $ledger),
            LoopAction::ASK_USER => $this->ask($action, $budget, $questionsAsked),
            default => null,
        };
    }

    /**
     * @param array<mixed>        $action
     * @param array<string, true> $seen
     */
    private function search(array $action, EvidenceLedger $ledger, ResearchBudget $budget, array $seen): ?LoopAction
    {
        $point = $action['point'] ?? null;
        $query = $action['query'] ?? null;
        if (!$budget->canSearch() || !is_string($point) || !$this->hasPoint($ledger, $point) || !is_string($query)) {
            return null;
        }
        $query = trim($query);
        if (mb_strlen($query) > 200) {
            $query = mb_substr($query, 0, 200);
        }
        if ('' === $query || isset($seen[$point.' '.$this->norm($query)])) {
            return null;
        }
        $language = $action['language'] ?? null;
        $scope = $action['scope'] ?? 'web';
        if ((null !== $language && (!is_string($language) || 1 !== preg_match('/^[a-z]{2}$/', $language)))
            || !is_string($scope) || !in_array($scope, self::SCOPES, true)) {
            return null;
        }

        return new LoopAction(LoopAction::SEARCH, $point, $query, is_string($language) ? $language : null, $scope, null, null);
    }

    /**
     * @param array<mixed> $action
     */
    private function read(array $action, EvidenceLedger $ledger, ResearchBudget $budget): ?LoopAction
    {
        $source = $this->source($action, $ledger, [Source::FOUND, Source::READING]);
        if (!$budget->canRead() || null === $source) {
            return null;
        }

        return new LoopAction(LoopAction::READ, null, null, null, null, $source, null);
    }

    /**
     * @param array<mixed> $action
     */
    private function reread(array $action, EvidenceLedger $ledger): ?LoopAction
    {
        $source = $this->source($action, $ledger, [Source::READ]);
        $question = $action['question'] ?? null;
        if (null === $source || !is_string($question) || '' === trim($question)) {
            return null;
        }

        return new LoopAction(LoopAction::REREAD, null, null, null, null, $source, trim($question));
    }

    /**
     * @param array<mixed> $action
     */
    private function ask(array $action, ResearchBudget $budget, int $questionsAsked): ?LoopAction
    {
        $question = $action['question'] ?? null;
        $options = $action['options'] ?? null;
        if ($questionsAsked >= $this->limits($budget)['questions'] || !is_string($question) || '' === trim($question) || !is_array($options)) {
            return null;
        }
        $kept = [];
        foreach ($options as $option) {
            if (is_string($option) && '' !== trim($option)) {
                $kept[] = trim($option);
            }
        }
        if (count($kept) < 2 || count($kept) > 4) {
            return null;
        }

        return new LoopAction(LoopAction::ASK_USER, null, null, null, null, null, trim($question), $kept);
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
     * @return array<string, true>
     */
    private function asked(EvidenceLedger $ledger): array
    {
        $seen = [];
        foreach ($ledger->queries() as $query) {
            $seen[$query->pointId.' '.$this->norm($query->query)] = true;
        }

        return $seen;
    }

    /**
     * @return array{max_actions: int, questions: int}
     */
    private function limits(ResearchBudget $budget): array
    {
        $limits = $budget->export()['limits'] ?? [];
        $extras = is_array($limits) && is_array($limits['extras'] ?? null) ? $limits['extras'] : [];

        return [
            'max_actions' => is_numeric($extras['max_actions'] ?? null) ? (int) $extras['max_actions'] : 0,
            'questions' => is_numeric($extras['questions'] ?? null) ? (int) $extras['questions'] : 0,
        ];
    }

    private function norm(string $query): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($query)) ?? trim($query);

        return mb_strtolower($collapsed);
    }
}
