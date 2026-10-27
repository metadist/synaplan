<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\AI\StructuredOutput\Schema\ResearchTriageSchema;
use App\Service\Research\Run\Ledger\PlanPoint;
use App\Service\Research\Run\Ledger\Source;
use App\Service\Research\Run\ResearchStatus;

/** Searches the planned queries, then keeps the hits the sorter marked to read. */
final readonly class GatherStage implements ResearchStage
{
    /**
     * Pages that turn out blocked or empty do not use the read budget, so
     * spares behind the best picks keep a run from ending with nothing read.
     */
    private const SPARE_FACTOR = 2;

    public function __construct(
        private StageCall $calls,
        private ResearchSearch $search,
        private GatherChoice $choice,
        private ?ResearchFileSearch $files = null,
    ) {
    }

    public function run(StageContext $ctx): StageResult
    {
        $hits = $this->searchPlanned($ctx);
        if ([] !== $hits) {
            $picked = $this->triage($ctx, $hits);
            if ($picked instanceof StageResult) {
                return $picked;
            }
            $this->keep($ctx, $picked);
        }
        $ctx->state->advance(ResearchStatus::Gathering);

        return new StageResult('read');
    }

    /**
     * @return list<array{id: string, url: string, host: string, title: string, snippet: string, date: string}>
     */
    private function searchPlanned(StageContext $ctx): array
    {
        $view = StageBudget::view($ctx->state->budget);
        $ran = [];
        foreach ($ctx->state->ledger->queries() as $query) {
            $prefix = 'files' === $query->scope ? $query->pointId.'|files|' : $query->pointId.'|';
            $ran[$prefix.$query->query] = true;
        }

        $hits = [];
        foreach ($ctx->state->ledger->points() as $point) {
            foreach ($point->queries as $query) {
                if ('web' !== $query['scope']) {
                    $fileKey = $point->id.'|files|'.$query['q'];
                    if (!isset($ran[$fileKey])) {
                        $this->keepFiles($ctx, $point->id, $query['q']);
                        $ran[$fileKey] = true;
                    }
                }
                if ('files' === $query['scope'] || !$ctx->webOn() || !$ctx->state->budget->canSearch()) {
                    continue;
                }
                $key = $point->id.'|'.$query['q'];
                if (isset($ran[$key])) {
                    continue;
                }
                $found = $this->search->search($query['q'], $query['language'], $view['perSearch'], $ctx->state->ref->userId);
                $ctx->state->budget->spend('searches');
                $ran[$key] = true;
                $ctx->state->ledger->addQuery($point->id, $query['q'], $query['scope'], count($found));
                $ctx->events->append($ctx->state->ref->id, 'search', [
                    'point' => $point->id,
                    'query' => $query['q'],
                    'scope' => $query['scope'],
                    'results' => count($found),
                ]);
                foreach ($found as $hit) {
                    $hits[] = [
                        'id' => 'r'.(count($hits) + 1),
                        'url' => $hit->url,
                        'host' => GatherChoice::host($hit->url),
                        'title' => $hit->title,
                        'snippet' => $hit->snippet,
                        'date' => $hit->date ?? '',
                    ];
                }
            }
        }

        return $hits;
    }

    private function keepFiles(StageContext $ctx, string $pointId, string $query): void
    {
        if (null === $this->files || !$ctx->filesOn()) {
            return;
        }

        ResearchFileHits::gather($this->files, $ctx, $pointId, $query);
    }

    /**
     * @param list<array{id: string, url: string, host: string, title: string, snippet: string, date: string}> $hits
     *
     * @return list<array{url: string, title: string, kind: string}>|StageResult
     */
    private function triage(StageContext $ctx, array $hits): array|StageResult
    {
        $brief = $ctx->state->ledger->brief();
        $lines = [];
        foreach ($hits as $hit) {
            $lines[] = $hit['id']."\n".'title: '.ResearchPrompts::data($hit['title'])."\n".'url: '.$hit['url']."\n".'snippet: '.ResearchPrompts::data($hit['snippet'])."\n".'date: '.ResearchPrompts::data($hit['date']);
        }
        $points = array_map(static fn (PlanPoint $point): string => $point->id.' '.$point->title, $ctx->state->ledger->points());
        $frame = null !== $brief ? $brief->timeFrame : null;
        $system = ResearchPrompts::fill('tools:research_triage', [
            'TODAY' => $ctx->today->format('Y-m-d'),
            'TIME_FRAME' => is_array($frame) ? trim(($frame['from'] ?? '').' '.$frame['to']) : 'none',
            'MUST_USE' => implode(', ', null !== $brief ? $brief->mustUse : []),
            'MUST_AVOID' => implode(', ', null !== $brief ? $brief->mustAvoid : []),
            'POINTS' => implode("\n", $points),
            'RESULTS' => implode("\n\n", $lines),
        ]);
        $called = $this->calls->once($ctx, 'sorting', $ctx->roles->sorter, $system, 'Choose what to read.', ResearchTriageSchema::build());
        if ($called->stop instanceof StageResult) {
            return $called->stop;
        }

        return $this->choice->choose(
            $hits,
            GatherChoice::verdicts(is_array($called->reply->data) ? $called->reply->data : []),
            null !== $brief ? $brief->mustUse : [],
            null !== $brief ? $brief->mustAvoid : [],
            $ctx->state->budget->sourcesLeft() * self::SPARE_FACTOR,
        );
    }

    /**
     * @param list<array{url: string, title: string, kind: string}> $picked
     */
    private function keep(StageContext $ctx, array $picked): void
    {
        foreach ($picked as $row) {
            if (!$ctx->state->budget->canRead()) {
                break;
            }
            $id = $ctx->state->ledger->addSource(Source::web($row['url'], $row['title']));
            $ctx->state->ledger->describeSource($id, $row['kind'], null);
            $source = $ctx->state->ledger->source($id);
            if (null !== $source) {
                $ctx->events->append($ctx->state->ref->id, 'source', $source->toEvent());
            }
        }
    }
}
