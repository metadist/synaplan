<?php

declare(strict_types=1);

namespace App\Service\Research\Spike;

use App\Service\Research\Eval\CorpusItem;
use App\Service\Research\Eval\CorpusLoader;
use App\Service\Research\Eval\EvalReportWriter;
use App\Service\Research\Eval\Scorer;

/**
 * Runs one spike mode over a corpus and scores fact recall with the eval scorer.
 */
final readonly class SpikeBatch
{
    public function __construct(
        private SpikeEngine $engine,
        private CorpusLoader $corpus,
        private Scorer $scorer,
        private EvalReportWriter $reports,
    ) {
    }

    /**
     * @param (\Closure(string): void)|null $progress receives one line per finished item
     */
    public function run(string $corpusPath, bool $smokeOnly, string $mode, int $userId, SpikeLimits $limits, int $repeat, string $command, ?string $only = null, ?\Closure $progress = null): string
    {
        $items = $this->corpus->load($corpusPath, $smokeOnly);
        if (null !== $only) {
            $items = array_values(array_filter($items, static fn (CorpusItem $item): bool => $item->id === $only));
            if ([] === $items) {
                throw new \RuntimeException('The corpus has no item '.$only.'.');
            }
        }
        $rows = [];
        $answers = [];
        foreach ($items as $item) {
            if (!$item->web) {
                $rows[] = [
                    'id' => $item->id,
                    'recall' => null,
                    'abstained' => false,
                    'ms' => 0,
                    'error' => 'This spike reads web pages. This item has no web source.',
                ];
                continue;
            }
            [$row, $answer] = $this->row($item, $mode, $userId, $limits, $repeat);
            $rows[] = $row;
            $answers[] = $answer;
            if (null !== $progress) {
                $progress($item->id.' '.$mode.' recall '.(null === $row['recall'] ? 'n/a' : number_format($row['recall'], 2, '.', '')).' '.$row['ms'].'ms'.(null !== $row['error'] ? ' error: '.$row['error'] : ''));
            }
        }
        $label = ($smokeOnly ? 'smoke' : 'starter').' ('.\count($items).' items)';

        return $this->reports->render($mode, $label, $command, $rows)."\n".$this->answers($answers);
    }

    /**
     * @return array{0: array{id: string, recall: float|null, abstained: bool, ms: int, error: string|null}, 1: string}
     */
    private function row(CorpusItem $item, string $mode, int $userId, SpikeLimits $limits, int $repeat): array
    {
        $recalls = [];
        $abstainedEvery = true;
        $error = null;
        $text = '';
        $trace = '';
        $answerNs = 0;
        $attempts = 0;
        $scoredRuns = 0;
        $searches = 0;
        $hits = 0;
        $reads = 0;
        $kept = 0;
        $dropped = 0;
        $promptTokens = 0;
        $completionTokens = 0;
        for ($i = 0; $i < $repeat; ++$i) {
            $started = hrtime(true);
            try {
                $answer = $this->engine->answer($item->question, $limits, $mode, $userId);
                $text = $answer->report;
                ++$scoredRuns;
                $searches += $answer->searches;
                $hits += $answer->hits;
                $reads += $answer->reads;
                $kept += $answer->keptFindings;
                $dropped += $answer->droppedFindings;
                $promptTokens += $answer->inputTokens;
                $completionTokens += $answer->outputTokens;
                $trace = sprintf(
                    'mean over %d runs: searches %.1f, hits %.1f, sources read %.1f, findings kept %.1f, findings dropped %.1f, prompt tokens %d, completion tokens %d',
                    $scoredRuns,
                    $searches / $scoredRuns,
                    $hits / $scoredRuns,
                    $reads / $scoredRuns,
                    $kept / $scoredRuns,
                    $dropped / $scoredRuns,
                    (int) round($promptTokens / $scoredRuns),
                    (int) round($completionTokens / $scoredRuns),
                )."\n".$answer->readLog;
            } catch (\Throwable $e) {
                $error = $e->getMessage();
                break;
            } finally {
                $answerNs += hrtime(true) - $started;
                ++$attempts;
            }
            $score = $this->scorer->score($item, $text);
            $abstainedEvery = $abstainedEvery && $score['abstained'];
            if (null !== $score['recall']) {
                $recalls[] = $score['recall'];
            }
        }

        return [[
            'id' => $item->id,
            'recall' => [] === $recalls || null !== $error ? null : array_sum($recalls) / \count($recalls),
            'abstained' => null === $error && $abstainedEvery,
            'ms' => (int) ($answerNs / max(1, $attempts) / 1_000_000),
            'error' => $error,
        ], "### {$item->id}\n\n".$trace."\n\n".$text];
    }

    /**
     * @param list<string> $answers
     */
    private function answers(array $answers): string
    {
        if ([] === $answers) {
            return '';
        }

        return "## Answers\n\n".implode("\n\n", $answers)."\n";
    }
}
