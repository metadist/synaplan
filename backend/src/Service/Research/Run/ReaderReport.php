<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Service\Research\Run\Ledger\EvidenceLedger;
use App\Service\Research\Run\Ledger\Source;
use App\Service\Research\Run\Stage\CheckReport;
use App\Service\Research\Run\Stage\CitationMarks;

/**
 * The report as the person reads it in the chat. The checked report keeps
 * everything the run needs later — the Not confirmed list that seeds Look
 * deeper, the count the check appended, the evidence ids — but the person
 * asked a question, not for the audit. So the reader's version holds the
 * answer only: the Not confirmed section and the check's closing lines stay
 * out, a heading the check emptied goes with them, with the dividing line
 * the writer drew under it, and every evidence id becomes the number of its
 * source in the order the sources first appear, so "[e12][e31]" reads as
 * "[1][2]" and the chat lists the sources beneath.
 */
final class ReaderReport
{
    private const CITATION_GROUP = '/(?:[ \t]*\[\s*e\d+(?:\s*,\s*e\d+)*\s*\])+/i';
    private const HEADING = '/^\s*(#{1,6})\s+\S/u';
    private const DIVIDER = '/^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/';
    private const FENCE = '/^ {0,3}(`{3,}|~{3,})/';
    private const FENCE_END = '/^ {0,3}(`{3,}|~{3,})[ \t]*$/';

    /**
     * @return array{markdown: string, sources: list<array{title: string, url: string, host: string, description: string}>}
     */
    public static function from(string $report, EvidenceLedger $ledger): array
    {
        $lines = array_filter(
            preg_split("/\r\n|\r|\n/u", CheckReport::withoutNotConfirmed(trim($report))) ?: [],
            static fn (string $line): bool => !CheckReport::isClosing($line),
        );
        $body = self::withoutEmptyHeadings(array_values($lines));
        if ('' === trim($body)) {
            return ['markdown' => self::gaps(CheckReport::notConfirmedBody($report)), 'sources' => []];
        }

        return self::numbered($body, $ledger);
    }

    /**
     * The same report with its sources written out, for a step that receives
     * only text (an email, a later saved-task step). The chat lists the
     * sources under the reply instead, so this is not attached as a file.
     *
     * @param list<array{title: string, url: string, host: string, description: string}> $sources
     */
    public static function withSourceList(string $markdown, array $sources, string $title): string
    {
        if ([] === $sources) {
            return $markdown;
        }
        $lines = ['## '.$title, ''];
        foreach ($sources as $index => $source) {
            $where = '' !== $source['url'] ? ' — '.$source['url'] : '';
            $lines[] = sprintf('%d. %s%s', $index + 1, $source['title'], $where);
        }

        return trim($markdown)."\n\n".implode("\n", $lines);
    }

    /**
     * The gaps, when the check left no sentence a source supports. A line
     * that still cites a source is a rejected claim and stays out.
     */
    private static function gaps(string $section): string
    {
        if ('' === trim($section)) {
            return '';
        }
        $kept = [];
        foreach (preg_split("/\r\n|\r|\n/u", $section) ?: [] as $line) {
            if (CitationMarks::cites($line) || CheckReport::isClosing($line)) {
                continue;
            }
            $kept[] = rtrim($line);
        }
        $text = trim((string) preg_replace("/\n{3,}/u", "\n\n", implode("\n", $kept)));
        if (1 === preg_match('/^#{1,6}\s+\S[\s\S]*$/u', $text) && 1 !== preg_match('/\n\s*\S/u', $text)) {
            return '';
        }

        return $text;
    }

    /**
     * A heading with nothing under it before the next heading of its level
     * or above (or the end) is a section the check emptied. A dividing line
     * the writer drew between sections is not content, and neither is a
     * deeper heading that is empty itself: a heading with only those under
     * it goes too. A dividing line that no longer divides two parts — at
     * the start, at the end, or right after another one — goes with it.
     * A fenced code block is content and stays as it was written.
     *
     * @param list<string> $lines
     */
    private static function withoutEmptyHeadings(array $lines): string
    {
        $kept = [];
        $textAbove = false;
        $openDivider = null;
        $fence = null;
        foreach ($lines as $index => $line) {
            if (null !== $fence) {
                $kept[] = $line;
                if (self::closesFence($line, $fence)) {
                    $fence = null;
                }
                continue;
            }
            if (self::isDivider($lines, $index)) {
                if (!$textAbove) {
                    continue;
                }
                // Directly under a text line the dashes would turn that line into a heading.
                if ('' !== $kept[array_key_last($kept)]) {
                    $kept[] = '';
                }
                $openDivider = count($kept);
                $textAbove = false;
                $kept[] = rtrim($line);
                continue;
            }
            if (1 === preg_match(self::HEADING, $line, $match) && !self::hasContent($lines, $index, strlen($match[1]))) {
                continue;
            }
            if ('' === trim($line)) {
                if ([] !== $kept && '' !== $kept[array_key_last($kept)]) {
                    $kept[] = '';
                }
                continue;
            }
            if (1 === preg_match(self::FENCE, $line, $match)) {
                $fence = $match[1];
            }
            $textAbove = true;
            $openDivider = null;
            $kept[] = rtrim($line);
        }
        if (null !== $openDivider) {
            array_splice($kept, $openDivider);
        }

        return trim(implode("\n", $kept));
    }

    /**
     * Whether a section holds more than blank lines, dividing lines and
     * headings below it.
     *
     * @param list<string> $lines
     */
    private static function hasContent(array $lines, int $heading, int $level): bool
    {
        for ($next = $heading + 1, $count = count($lines); $next < $count; ++$next) {
            if ('' === trim($lines[$next]) || self::isDivider($lines, $next)) {
                continue;
            }
            if (1 !== preg_match(self::HEADING, $lines[$next], $match)) {
                return true;
            }
            if (strlen($match[1]) <= $level) {
                return false;
            }
        }

        return false;
    }

    /** The line that ends a code block: the same fence character, at least as long, and nothing after it. */
    private static function closesFence(string $line, string $fence): bool
    {
        return 1 === preg_match(self::FENCE_END, $line, $match)
            && $match[1][0] === $fence[0]
            && strlen($match[1]) >= strlen($fence);
    }

    /**
     * A horizontal rule such as "---", "***" or "___". Dashes directly
     * under a line of text are that line's heading underline, not a rule.
     *
     * @param list<string> $lines
     */
    private static function isDivider(array $lines, int $index): bool
    {
        if (1 !== preg_match(self::DIVIDER, $lines[$index], $match)) {
            return false;
        }
        $above = $index > 0 ? trim($lines[$index - 1]) : '';

        return '-' !== $match[1]
            || '' === $above
            || 1 === preg_match(self::HEADING, $above)
            || 1 === preg_match(self::DIVIDER, $above)
            || 1 === preg_match(self::FENCE, $above);
    }

    /**
     * @return array{markdown: string, sources: list<array{title: string, url: string, host: string, description: string}>}
     */
    private static function numbered(string $body, EvidenceLedger $ledger): array
    {
        $cards = [];
        foreach ($ledger->evidenceForWriter() as $group) {
            foreach ($group as $card) {
                if (is_string($card['id'] ?? null) && is_string($card['source'] ?? null)) {
                    $cards[strtolower($card['id'])] = ['source' => $card['source'], 'fact' => is_string($card['fact'] ?? null) ? $card['fact'] : ''];
                }
            }
        }
        $numbers = [];
        $sources = [];
        $markdown = preg_replace_callback(self::CITATION_GROUP, static function (array $match) use ($cards, $ledger, &$numbers, &$sources): string {
            preg_match_all('/e\d+/i', $match[0], $ids);
            $cited = [];
            foreach ($ids[0] as $id) {
                $card = $cards[strtolower($id)] ?? null;
                $source = null !== $card ? $ledger->source($card['source']) : null;
                if (null === $card || !$source instanceof Source) {
                    continue;
                }
                if (!isset($numbers[$source->id])) {
                    $numbers[$source->id] = count($numbers) + 1;
                    $sources[] = self::row($source, $card['fact']);
                }
                $cited[$numbers[$source->id]] = true;
            }
            if ([] === $cited) {
                return '';
            }
            ksort($cited);

            return ' '.implode('', array_map(static fn (int $number): string => '['.$number.']', array_keys($cited)));
        }, $body);

        return ['markdown' => trim($markdown ?? $body), 'sources' => $sources];
    }

    /**
     * @return array{title: string, url: string, host: string, description: string}
     */
    private static function row(Source $source, string $fact): array
    {
        $url = $source->url ?? (null !== $source->fileId ? '/files?file='.$source->fileId : '');

        return [
            'title' => $source->title,
            'url' => $url,
            'host' => $source->host ?? $source->kind,
            'description' => $fact,
        ];
    }
}
