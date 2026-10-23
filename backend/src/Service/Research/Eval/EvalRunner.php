<?php

declare(strict_types=1);

namespace App\Service\Research\Eval;

/**
 * Loads the corpus, runs one arm, and scores the deterministic metrics.
 *
 * A failed item is a row with the sentence the arm raised. It is not scored
 * as a wrong answer. A failed judge is a note; it never fails the item.
 */
final readonly class EvalRunner
{
    private const ARMS = ['b0', 'b1', 'b1plus', 'b4'];

    public function __construct(
        private CorpusLoader $corpus,
        private Scorer $scorer,
        private B0Adapter $b0,
        private B1Importer $b1,
        private B1PlusAdapter $b1Plus,
        private B4Adapter $b4,
        private JudgeClient $judges,
        private EvalReportWriter $reports,
        private SyntheticDocumentGenerator $documents,
    ) {
    }

    public function run(EvalRequest $request): string
    {
        if (null !== $request->files->documentsDir) {
            $this->documents->write($request->files->documentsDir);

            return sprintf(
                'Wrote %d synthetic files to %s. Upload them into the evaluation folder before a knowledge item.',
                \count($this->documents->documents()),
                $request->files->documentsDir,
            );
        }

        $this->guard($request);
        $items = $this->corpus->load($request->corpusPath, $request->smokeOnly);
        $imported = null !== $request->files->importPath ? $this->b1->load($request->files->importPath) : null;
        $frozen = null !== $request->files->frozenDir ? new FrozenStore($request->files->frozenDir) : null;
        $rows = [];
        $judgeNotes = [];
        foreach ($items as $item) {
            $rows[] = $this->row($request, $item, $imported, $frozen, $judgeNotes);
        }

        $label = ($request->smokeOnly ? 'smoke' : 'full').' ('.\count($items).' items)';
        $text = $this->reports->render($request->arm, $label, $request->commandLine, $rows, $judgeNotes);
        if (null !== $request->files->reportPath) {
            $this->reports->write($request->files->reportPath, $text);
        }

        return $text;
    }

    private function guard(EvalRequest $request): void
    {
        if (!\in_array($request->arm, self::ARMS, true)) {
            throw new \RuntimeException('Pass --arm with b0, b1, b1plus or b4.');
        }
        $imported = null !== $request->files->importPath;
        if ('b1' === $request->arm && !$imported) {
            throw new \RuntimeException('B1 scores an imported desktop report. Pass --import. Nothing was sent.');
        }
        if ('b0' === $request->arm && !$imported && $request->userId < 1) {
            throw new \RuntimeException('Pass --user with the evaluation account. Nothing was searched.');
        }
        if ([] !== $request->live->judges && $request->userId < 1) {
            throw new \RuntimeException('Judges run on the evaluation account. Pass --user. Nothing was sent.');
        }
        if (null !== $request->files->frozenDir && ('b0' !== $request->arm || $imported)) {
            throw new \RuntimeException('--frozen replays the searches of a live b0 run only. Nothing was sent.');
        }
    }

    /**
     * @param array<string, string>|null $imported
     * @param list<string>               $judgeNotes
     *
     * @return array{id: string, recall: float|null, abstained: bool, ms: int, error: string|null}
     */
    private function row(EvalRequest $request, CorpusItem $item, ?array $imported, ?FrozenStore $frozen, array &$judgeNotes): array
    {
        $recalls = [];
        $abstainedEvery = true;
        $error = null;
        $text = '';
        $answerNs = 0;
        $attempts = 0;
        $times = null !== $imported ? 1 : max(1, $request->repeat);
        for ($i = 0; $i < $times; ++$i) {
            $started = hrtime(true);
            try {
                $text = $this->answer($request, $item, $imported, $frozen);
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
        if (null === $error) {
            $this->recordJudges($request, $item, $text, $judgeNotes);
        }

        return [
            'id' => $item->id,
            'recall' => [] === $recalls || null !== $error ? null : array_sum($recalls) / \count($recalls),
            'abstained' => null === $error && $abstainedEvery,
            'ms' => (int) ($answerNs / max(1, $attempts) / 1_000_000),
            'error' => $error,
        ];
    }

    /**
     * @param array<string, string>|null $imported
     */
    private function answer(EvalRequest $request, CorpusItem $item, ?array $imported, ?FrozenStore $frozen): string
    {
        if (null !== $imported) {
            if (!isset($imported[$item->id])) {
                throw new \RuntimeException('The import has no report for '.$item->id.'.');
            }

            return $imported[$item->id];
        }

        $live = $request->live;

        return match ($request->arm) {
            'b0' => $this->b0->answer($item, $request->userId, $live->folder, $frozen),
            'b1plus' => $this->b1Plus->answer($item, (string) $live->gatewayUrl, (string) $live->apiKey, $live->folder, $live->gatewayModel),
            'b4' => $this->b4->answer($item, (string) $live->b4Endpoint),
            default => throw new \RuntimeException('This arm needs --import. Nothing was sent.'),
        };
    }

    /**
     * @param list<string> $judgeNotes
     */
    private function recordJudges(EvalRequest $request, CorpusItem $item, string $text, array &$judgeNotes): void
    {
        if ([] === $request->live->judges) {
            return;
        }
        foreach ($item->facts->entries as $entry) {
            try {
                $verdicts = $this->judges->judgeFact($entry['assertion'], $text, $request->live->judges, $request->userId);
            } catch (\Throwable $e) {
                $judgeNotes[] = $item->id.' '.$entry['id'].': the judge could not answer ('.$e->getMessage().').';
                continue;
            }
            foreach ($verdicts as $verdict) {
                $judgeNotes[] = $item->id.' '.$entry['id'].' '.$verdict['judge'].': '.$verdict['verdict'];
            }
        }
    }
}
