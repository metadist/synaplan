<?php

declare(strict_types=1);

namespace App\Service\Research\Eval;

/**
 * Loads the corpus, runs one arm, and scores the deterministic metrics.
 *
 * A failed item is a row with the sentence the arm raised. It is not scored
 * as a wrong answer.
 */
final readonly class EvalRunner
{
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

            return 'Wrote 3 synthetic files to '.$request->files->documentsDir.'. Upload them into the evaluation folder before a knowledge item.';
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

        $label = $request->smokeOnly ? 'smoke ('.\count($items).' items)' : 'full ('.\count($items).' items)';
        if (null !== $request->files->reportPath) {
            return $this->reports->write($request->files->reportPath, $request->arm, $label, $request->commandLine, $rows, $judgeNotes);
        }

        return $this->reports->render($request->arm, $label, $request->commandLine, $rows, $judgeNotes);
    }

    private function guard(EvalRequest $request): void
    {
        if ('b1' === $request->arm && null === $request->files->importPath) {
            throw new \RuntimeException('B1 scores an imported desktop report. Pass --import. Nothing was sent.');
        }
        if (\in_array($request->arm, ['b0', 'b1plus'], true) && null === $request->files->importPath && $request->userId < 1) {
            throw new \RuntimeException('Pass --user with the evaluation account. Nothing was searched.');
        }
        if (!\in_array($request->arm, ['b0', 'b1', 'b1plus', 'b4'], true)) {
            throw new \RuntimeException('The arm must be b0, b1, b1plus or b4.');
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
        $started = hrtime(true);
        $recalls = [];
        $abstained = false;
        $error = null;
        $text = null;
        $times = null !== $imported ? 1 : max(1, $request->repeat);
        for ($i = 0; $i < $times; ++$i) {
            try {
                $text = $this->answer($request, $item, $imported, $frozen);
                $score = $this->scorer->score($item, $text);
                $abstained = $score['abstained'];
                if (null !== $score['recall']) {
                    $recalls[] = $score['recall'];
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
                break;
            }
        }
        if (null === $error) {
            $this->recordJudges($request, $item, $text, $judgeNotes);
        }
        $ms = (int) ((hrtime(true) - $started) / 1_000_000);

        return [
            'id' => $item->id,
            'recall' => [] === $recalls || null !== $error ? null : array_sum($recalls) / \count($recalls),
            'abstained' => null === $error && $abstained,
            'ms' => $ms,
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

        return match ($request->arm) {
            'b0' => $this->b0->answer($item, $request->userId, $request->live->folder, $frozen),
            'b1plus' => $this->b1Plus->answer($item, (string) $request->live->gatewayUrl, (string) $request->live->apiKey, $request->live->folder),
            'b4' => $this->b4->answer($item, (string) $request->live->b4Endpoint),
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
                $judgeNotes[] = $item->id.' '.$entry['id'].' judge failed: '.$e->getMessage();

                continue;
            }
            foreach ($verdicts as $verdict) {
                $judgeNotes[] = $item->id.' '.$entry['id'].' '.$verdict['judge'].': '.$verdict['verdict'];
            }
        }
    }
}
