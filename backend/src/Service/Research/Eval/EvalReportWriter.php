<?php

declare(strict_types=1);

namespace App\Service\Research\Eval;

/**
 * The Markdown report a STATUS row is copied from. It only contains numbers
 * this run produced.
 *
 * @phpstan-type ReportRow array{id: string, recall: float|null, abstained: bool, ms: int, error: string|null}
 */
final readonly class EvalReportWriter
{
    public function write(string $path, string $text): void
    {
        $dir = \dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create the report directory '.$dir.'.');
        }
        if (false === file_put_contents($path, $text)) {
            throw new \RuntimeException('Could not write the report to '.$path.'.');
        }
    }

    /**
     * @param list<ReportRow> $rows
     * @param list<string>    $judgeNotes
     */
    public function render(string $arm, string $corpusLabel, string $command, array $rows, array $judgeNotes = []): string
    {
        $lines = [
            '# Research eval',
            '',
            'Arm: '.$arm,
            'Corpus: '.$corpusLabel,
            'Command: '.$command,
            '',
            '| Item | Fact recall | Abstained | ms | Note |',
            '| ---- | ----------- | --------- | -- | ---- |',
        ];
        $sum = 0.0;
        $scored = 0;
        $failed = 0;
        foreach ($rows as $row) {
            if (null !== $row['error']) {
                ++$failed;
            }
            if (null !== $row['recall']) {
                $sum += $row['recall'];
                ++$scored;
            }
            $lines[] = sprintf(
                '| %s | %s | %s | %d | %s |',
                $this->cell($row['id']),
                null === $row['recall'] ? 'n/a' : number_format($row['recall'], 2, '.', ''),
                $row['abstained'] ? 'yes' : 'no',
                $row['ms'],
                $this->cell($row['error'] ?? ''),
            );
        }
        $lines[] = '';
        $mean = $scored > 0 ? number_format($sum / $scored, 2, '.', '') : 'n/a';
        $lines[] = 'Deterministic fact recall: '.$mean.' across '.$scored.' items with match strings.';
        if ($failed > 0) {
            $lines[] = $failed.' item'.(1 === $failed ? '' : 's').' failed before they could be scored.';
        }
        $lines[] = 'The ms column is the mean answer time per attempt; judge calls are not included.';
        if ([] === $judgeNotes) {
            $lines[] = 'Judged metrics are included only when --judges is set and a judge answers.';
        } else {
            $lines[] = '';
            $lines[] = '## Judges';
            $lines[] = '';
            foreach ($judgeNotes as $note) {
                $lines[] = '- '.$note;
            }
        }
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function cell(string $value): string
    {
        $flat = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return str_replace('|', '\|', trim($flat));
    }
}
