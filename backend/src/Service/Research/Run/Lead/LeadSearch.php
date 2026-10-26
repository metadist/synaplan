<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Lead;

use App\AI\ToolCalling\ToolCall;
use App\Service\Research\Run\Ledger\PlainText;
use App\Service\Research\Run\Ledger\Source;
use App\Service\Research\Run\Stage\ResearchHit;
use App\Service\Research\Run\Stage\ResearchPrompts;
use App\Service\Research\Run\Stage\ResearchSearch;
use App\Service\Research\Run\Stage\StageContext;

/**
 * One web search the lead asked for. A repeated query, an unknown point, or
 * a spent search budget returns an error and does not search. File search
 * waits for the knowledge source, so a files scope is refused.
 */
final readonly class LeadSearch
{
    public function __construct(private ResearchSearch $search)
    {
    }

    public function run(ToolCall $call, StageContext $ctx): string
    {
        $point = $call->arguments['point'] ?? null;
        $query = $this->query($call->arguments['query'] ?? null);
        $scope = $call->arguments['scope'] ?? null;
        if (!is_string($point) || null === $query || !is_string($scope) || !in_array($scope, ['web', 'files', 'both'], true)) {
            return LeadToolReply::error('not_allowed', $ctx->state->budget);
        }
        if (!$this->hasPoint($ctx, $point)) {
            return LeadToolReply::error('unknown_id', $ctx->state->budget);
        }
        if ('web' !== $scope) {
            return LeadToolReply::error('not_allowed', $ctx->state->budget);
        }
        if ($this->repeated($ctx, $query, $scope)) {
            return LeadToolReply::error('repeated_query', $ctx->state->budget);
        }
        if (!$ctx->state->budget->canSearch()) {
            return LeadToolReply::error('budget_used', $ctx->state->budget);
        }

        $found = $this->search->search($query, $this->language($ctx, $call->arguments['language'] ?? null), $ctx->state->budget->extras()->resultsPerSearch, $ctx->state->ref->userId);
        $ctx->state->budget->spend('searches');
        $ctx->state->ledger->addQuery($point, $query, 'web', count($found));
        $ctx->events->append($ctx->state->ref->id, 'search', [
            'point' => $point,
            'query' => $query,
            'scope' => 'web',
            'results' => count($found),
        ]);

        return LeadToolReply::ok(['results' => $this->rows($ctx, $found)], $ctx->state->budget);
    }

    /**
     * @param list<ResearchHit> $found
     *
     * @return list<array<string, mixed>>
     */
    private function rows(StageContext $ctx, array $found): array
    {
        $rows = [];
        foreach ($found as $hit) {
            $before = count($ctx->state->ledger->sources());
            $id = $ctx->state->ledger->addSource(Source::web($hit->url, $hit->title));
            $source = $ctx->state->ledger->source($id);
            if (null === $source) {
                continue;
            }
            if (count($ctx->state->ledger->sources()) > $before) {
                $ctx->events->append($ctx->state->ref->id, 'source', $source->toEvent());
            }
            $row = [
                'id' => $id,
                'title' => ResearchPrompts::data($source->title),
                'host' => ResearchPrompts::data($source->host ?? ''),
                'snippet' => ResearchPrompts::data($hit->snippet),
            ];
            if (null !== $hit->date) {
                $row['date'] = ResearchPrompts::data($hit->date);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function query(mixed $query): ?string
    {
        if (!is_string($query)) {
            return null;
        }
        $line = rtrim(mb_substr(PlainText::line($query, PHP_INT_MAX), 0, 200));

        return '' === $line ? null : $line;
    }

    private function language(StageContext $ctx, mixed $language): string
    {
        if (is_string($language) && 1 === preg_match('/^[a-z]{2}$/', strtolower(trim($language)))) {
            return strtolower(trim($language));
        }
        $brief = $ctx->state->ledger->brief();

        return null !== $brief && 1 === preg_match('/^[a-z]{2}$/', $brief->language) ? $brief->language : 'en';
    }

    private function repeated(StageContext $ctx, string $query, string $scope): bool
    {
        $key = $scope.' '.$this->norm($query);
        foreach ($ctx->state->ledger->queries() as $earlier) {
            if ($earlier->scope.' '.$this->norm($earlier->query) === $key) {
                return true;
            }
        }

        return false;
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

    private function norm(string $text): string
    {
        return mb_strtolower(PlainText::line($text, PHP_INT_MAX));
    }
}
