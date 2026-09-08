<?php

declare(strict_types=1);

namespace App\Service\UrlWatch;

/**
 * Line-based unified-ish diff. No composer dependency.
 *
 * Input is capped so LCS stays cheap on a long page. The DP table is one
 * packed array (not n nested PHP arrays) — at 400×400 that is ~160k ints,
 * not a 1500×1500 nest that can OOM a worker.
 */
final readonly class TextDiff
{
    public const MAX_DIFF_LINES = 200;
    public const MAX_INPUT_LINES = 400;

    public function unified(string $old, string $new, int $maxLines = self::MAX_DIFF_LINES): string
    {
        $a = $this->lines($old);
        $b = $this->lines($new);
        if ($a === $b) {
            return '';
        }

        $ops = $this->lcsOps($a, $b);
        $out = [];
        foreach ($ops as $op) {
            $out[] = $op;
            if (count($out) >= $maxLines) {
                $out[] = ' … (diff truncated)';
                break;
            }
        }

        return implode("\n", $out);
    }

    /**
     * @return list<string>
     */
    private function lines(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $text);
        if (count($lines) > self::MAX_INPUT_LINES) {
            $lines = array_slice($lines, 0, self::MAX_INPUT_LINES);
            $lines[] = '…';
        }

        return $lines;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     *
     * @return list<string>
     */
    private function lcsOps(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        $stride = $m + 1;
        $dp = array_fill(0, ($n + 1) * $stride, 0);
        for ($i = $n - 1; $i >= 0; --$i) {
            $row = $i * $stride;
            $next = $row + $stride;
            for ($j = $m - 1; $j >= 0; --$j) {
                $dp[$row + $j] = $a[$i] === $b[$j]
                    ? $dp[$next + $j + 1] + 1
                    : max($dp[$next + $j], $dp[$row + $j + 1]);
            }
        }

        $ops = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $ops[] = '  '.$a[$i];
                ++$i;
                ++$j;
            } elseif ($dp[($i + 1) * $stride + $j] >= $dp[$i * $stride + $j + 1]) {
                $ops[] = '- '.$a[$i];
                ++$i;
            } else {
                $ops[] = '+ '.$b[$j];
                ++$j;
            }
        }
        while ($i < $n) {
            $ops[] = '- '.$a[$i];
            ++$i;
        }
        while ($j < $m) {
            $ops[] = '+ '.$b[$j];
            ++$j;
        }

        return $ops;
    }
}
