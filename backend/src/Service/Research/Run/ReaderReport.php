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
 * out, a heading the check emptied goes with them, and every evidence id
 * becomes the number of its source in the order the sources first appear,
 * so "[e12][e31]" reads as "[1][2]" and the chat lists the sources beneath.
 */
final class ReaderReport
{
    private const CITATION_GROUP = '/(?:[ \t]*\[\s*e\d+(?:\s*,\s*e\d+)*\s*\])+/i';
    private const HEADING = '/^\s*(#{1,6})\s+\S/u';

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
     * or above (or the end) is a section the check emptied.
     *
     * @param list<string> $lines
     */
    private static function withoutEmptyHeadings(array $lines): string
    {
        $kept = [];
        $count = count($lines);
        foreach ($lines as $index => $line) {
            if (1 === preg_match(self::HEADING, $line, $match)) {
                $level = strlen($match[1]);
                $next = $index + 1;
                while ($next < $count && '' === trim($lines[$next])) {
                    ++$next;
                }
                if ($next >= $count || (1 === preg_match(self::HEADING, $lines[$next], $after) && strlen($after[1]) <= $level)) {
                    continue;
                }
            }
            $kept[] = rtrim($line);
        }

        return trim((string) preg_replace("/\n{3,}/u", "\n\n", implode("\n", $kept)));
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
