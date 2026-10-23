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
    /**
     * @param list<ReportRow> $rows
     */
    public function write(string $path, string $arm, string $corpusLabel, string $command, array $rows): string
    {
        $dir = \dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create the report directory.');
        }

        $text = $this->render($arm, $corpusLabel, $command, $rows);
        if (false === file_put_contents($path, $text)) {
            throw new \RuntimeException('Could not write the report.');
        }

        return $text;
    }

    /**
     * @param list<ReportRow> $rows
     */
    public function render(string $arm, string $corpusLabel, string $command, array $rows): string
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
                $row['id'],
                null === $row['recall'] ? 'n/a' : number_format($row['recall'], 2, '.', ''),
                $row['abstained'] ? 'yes' : 'no',
                $row['ms'],
                $row['error'] ?? '',
            );
        }
        $lines[] = '';
        $mean = $scored > 0 ? number_format($sum / $scored, 2, '.', '') : 'n/a';
        $lines[] = 'Deterministic fact recall: '.$mean.' across '.$scored.' items with match strings.';
        if ($failed > 0) {
            $lines[] = $failed.' item'.(1 === $failed ? '' : 's').' failed before they could be scored.';
        }
        $lines[] = 'Judged metrics are included only when --judges is set and a judge answers.';
        $lines[] = '';

        return implode("\n", $lines);
    }
}
