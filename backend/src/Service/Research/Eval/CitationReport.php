<?php

declare(strict_types=1);

namespace App\Service\Research\Eval;

/**
 * Writes the citation-precision table, every judged pair, and a blind sample
 * for people to label (statement and passage only, no arm, no votes).
 *
 * @phpstan-import-type CitationPair from CitationRunner
 */
final class CitationReport
{
    private const LISTED_MISSES = 40;
    private const STATEMENT_CHARS = 220;

    /**
     * @param list<CitationPair>                                                            $pairs
     * @param list<string>                                                                  $judgeKeys
     * @param array<string, int>                                                            $tokens
     * @param list<array{pair: string, status: string, statement: string, passage: string}> $shuffled
     */
    public function write(string $outDir, array $pairs, array $judgeKeys, array $tokens, array $shuffled, int $sampleSize, int $seed): string
    {
        if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
            throw new \RuntimeException('Could not create the citation directory '.$outDir.'.');
        }
        $sample = [];
        foreach ($shuffled as $pair) {
            if ('ok' === $pair['status'] && \count($sample) < $sampleSize) {
                $sample[] = ['pair' => $pair['pair'], 'statement' => $pair['statement'], 'passage' => $pair['passage'], 'label' => null];
            }
        }
        $this->json($outDir.'/pairs.json', $pairs);
        $this->json($outDir.'/sample.json', $sample);
        $text = $this->markdown($pairs, $judgeKeys, $tokens, \count($sample), $seed);
        if (false === file_put_contents($outDir.'/report.md', $text)) {
            throw new \RuntimeException('Could not write the citation report to '.$outDir.'.');
        }

        return $text;
    }

    /**
     * @param list<CitationPair> $pairs
     * @param list<string>       $judgeKeys
     * @param array<string, int> $tokens
     */
    private function markdown(array $pairs, array $judgeKeys, array $tokens, int $sampleCount, int $seed): string
    {
        $lines = [
            '# Citation precision',
            '',
            'Judges: '.implode(', ', $judgeKeys).'. Seed '.$seed.'. Not calibrated against people (06 §4): a signal, not a gate.',
            '',
            '| Arm | Answers with a citation | Cited statements | Judged | Supports | Does not support | Unknown evidence id | Uncertain | Judge error | Unreadable page | Precision | Judges agree |',
            '| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |',
        ];
        $byArm = [];
        foreach ($pairs as $pair) {
            $byArm[$pair['arm']][] = $pair;
        }
        ksort($byArm);
        foreach ($byArm as $arm => $armPairs) {
            $lines[] = $this->row((string) $arm, $armPairs);
        }
        $lines[] = '';
        $lines[] = 'Precision is supports / (supports + does not support), with an unknown evidence id counted as does not support. Uncertain, judge errors and unreadable pages are left out and listed.';
        $lines[] = '';
        $lines[] = 'Judge tokens: '.implode(', ', array_map(static fn (string $key): string => $key.' '.$tokens[$key], $judgeKeys)).'.';
        $lines[] = 'Blind sample for people to label: '.$sampleCount.' pairs in sample.json.';
        $lines[] = '';
        $lines[] = '## Not supported';
        $lines[] = '';
        $listed = 0;
        foreach ($pairs as $pair) {
            if ('does_not_support' !== $pair['verdict'] || $listed >= self::LISTED_MISSES) {
                continue;
            }
            ++$listed;
            $lines[] = sprintf('- %s %s %s run %d: %s', $pair['pair'], $pair['arm'], $pair['item'], $pair['run'], mb_substr($pair['statement'], 0, self::STATEMENT_CHARS))
                .('unknown_ref' === $pair['status'] ? ' (unknown evidence id)' : '');
        }
        if (0 === $listed) {
            $lines[] = 'none';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param list<array{arm: string, item: string, run: int, status: string, votes: array<string, string>, verdict: string}> $pairs
     */
    private function row(string $arm, array $pairs): string
    {
        $count = static fn (string $verdict): int => \count(array_filter($pairs, static fn (array $pair): bool => $pair['verdict'] === $verdict));
        $answers = [];
        $judged = 0;
        $agree = 0;
        foreach ($pairs as $pair) {
            $answers[$pair['item'].'#'.$pair['run']] = true;
            if ('ok' !== $pair['status']) {
                continue;
            }
            ++$judged;
            $votes = array_values($pair['votes']);
            if (isset($votes[1]) && $votes[0] === $votes[1]) {
                ++$agree;
            }
        }
        $supports = $count('supports');
        $notSupported = $count('does_not_support');
        $unknown = \count(array_filter($pairs, static fn (array $pair): bool => 'unknown_ref' === $pair['status']));
        $unreadable = \count(array_filter($pairs, static fn (array $pair): bool => 'unreadable' === $pair['status']));

        return sprintf(
            '| %s | %d | %d | %d | %d | %d | %d | %d | %d | %d | %s | %s |',
            $arm,
            \count($answers),
            \count($pairs),
            $judged,
            $supports,
            $notSupported - $unknown,
            $unknown,
            $count('uncertain'),
            $count('judge_error'),
            $unreadable,
            $supports + $notSupported > 0 ? number_format(100 * $supports / ($supports + $notSupported), 1).' %' : 'n/a',
            $judged > 0 ? number_format(100 * $agree / $judged, 1).' %' : 'n/a',
        );
    }

    private function json(string $path, mixed $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (false === file_put_contents($path, $json."\n")) {
            throw new \RuntimeException('Could not write '.$path.'.');
        }
    }
}
