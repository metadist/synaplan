<?php

declare(strict_types=1);

namespace App\Service\Research\Eval;

/**
 * Splits a report into sentences that carry a citation: evidence ids such as
 * [e3], Markdown links, bare URLs, or numbered references resolved from a
 * sources list. Sentences without a citation are not returned.
 */
final class CitationExtractor
{
    private const MIN_WORDS = 3;
    private const SOURCES_HEADING = '/^(?:#{1,6}\s*|\*\*)?\s*(?:sources?|references?|citations?|quellen|fuentes|r[ée]f[ée]rences)\b/iu';
    private const EVIDENCE = '/\[((?:e\d+[\s,;]*)+)\]/u';
    private const LINK = '/\[([^\]]*)\]\((https?:\/\/[^)\s]+)\)/u';
    private const BARE_URL = '/https?:\/\/[^\s)\]>"\']+/u';
    private const NUMBERED = '/\[(\d+(?:\s*[,;]\s*\d+)*)\]/u';
    private const ABBREVIATION_END = '/(?:\b(?:Art|Abs|Buchst|Bst|Nr|Ziff|lit|Kap|Rn|Anh|bzw|ca|vgl|ggf|Mio|Mrd|etc|vs|No|Vol|Sec|Ch|pp|ff|Dr|Prof|St|Mr|Mrs|Ms|Mme|cf|al)|\b\p{L}|(?<![\d.])\d{1,2})\.$/u';

    /**
     * @return list<array{statement: string, refs: list<string>}>
     */
    public function statements(string $report): array
    {
        $lines = preg_split("/\r\n|\r|\n/u", $report) ?: [];
        $numbers = $this->referenceList($lines);
        $out = [];
        $inSources = false;
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($this->isHeading($trimmed)) {
                $inSources = 1 === preg_match(self::SOURCES_HEADING, $trimmed);
                continue;
            }
            if ($inSources || '' === $trimmed || 1 === preg_match('/^\[\d+\]:/u', $trimmed)) {
                continue;
            }
            foreach ($this->sentences($trimmed) as $sentence) {
                $refs = $this->refs($sentence, $numbers);
                if ([] === $refs) {
                    continue;
                }
                $statement = $this->clean($sentence);
                if (\count(preg_split('/\s+/u', $statement) ?: []) < self::MIN_WORDS) {
                    continue;
                }
                $out[] = ['statement' => $statement, 'refs' => $refs];
            }
        }

        return $out;
    }

    /**
     * @param list<string> $lines
     *
     * @return array<string, string>
     */
    private function referenceList(array $lines): array
    {
        $numbers = [];
        $inSources = false;
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($this->isHeading($trimmed)) {
                $inSources = 1 === preg_match(self::SOURCES_HEADING, $trimmed);
                continue;
            }
            $definition = 1 === preg_match('/^\[(\d+)\]:?\s*(.*)$/u', $trimmed, $match);
            if (!$definition && $inSources) {
                $definition = 1 === preg_match('/^(?:[-*]\s*)?(\d+)[.)]\s*(.*)$/u', $trimmed, $match);
            }
            if ($definition && 1 === preg_match(self::BARE_URL, $match[2], $url)) {
                $numbers[$match[1]] = $this->trimUrl($url[0]);
            }
        }

        return $numbers;
    }

    private function isHeading(string $line): bool
    {
        return 1 === preg_match('/^#{1,6}\s/u', $line)
            || 1 === preg_match('/^\*\*[^*]+\*\*:?$/u', $line)
            || (1 === preg_match(self::SOURCES_HEADING, $line) && mb_strlen($line) < 40);
    }

    /**
     * A sentence that holds only citation markers belongs to the one before it.
     *
     * @return list<string>
     */
    private function sentences(string $line): array
    {
        $parts = preg_split('/(?<=[.!?])\s+/u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $markersOnly = '' === trim((string) preg_replace([self::EVIDENCE, self::NUMBERED, self::LINK, self::BARE_URL, '/[().,;\s]/u'], '', $part));
            $afterAbbreviation = [] !== $out && 1 === preg_match(self::ABBREVIATION_END, $out[\count($out) - 1]);
            if (($markersOnly || $afterAbbreviation) && [] !== $out) {
                $out[\count($out) - 1] .= ' '.$part;
                continue;
            }
            $out[] = $part;
        }

        return $out;
    }

    /**
     * @param array<string, string> $numbers
     *
     * @return list<string>
     */
    private function refs(string $sentence, array $numbers): array
    {
        $refs = [];
        if (preg_match_all(self::EVIDENCE, $sentence, $groups) > 0) {
            foreach ($groups[1] as $group) {
                preg_match_all('/e\d+/u', $group, $ids);
                array_push($refs, ...$ids[0]);
            }
        }
        $withoutLinks = (string) preg_replace_callback(self::LINK, static function (array $match) use (&$refs): string {
            $refs[] = $match[2];

            return $match[1];
        }, $sentence);
        if (preg_match_all(self::BARE_URL, $withoutLinks, $urls) > 0) {
            foreach ($urls[0] as $url) {
                $refs[] = $this->trimUrl($url);
            }
        }
        if ([] !== $numbers && preg_match_all(self::NUMBERED, $withoutLinks, $groups) > 0) {
            foreach ($groups[1] as $group) {
                foreach (preg_split('/\s*[,;]\s*/u', $group) ?: [] as $number) {
                    if (isset($numbers[$number])) {
                        $refs[] = $numbers[$number];
                    }
                }
            }
        }

        return array_values(array_unique(array_map(fn (string $ref): string => str_starts_with($ref, 'http') ? $this->trimUrl($ref) : $ref, $refs)));
    }

    private function clean(string $sentence): string
    {
        $text = (string) preg_replace(self::LINK, '$1', $sentence);
        $text = (string) preg_replace([self::EVIDENCE, self::NUMBERED, self::BARE_URL], '', $text);
        $text = (string) preg_replace('/^(?:[-*•]|\d+[.)])\s+/u', '', trim($text));
        $text = str_replace(['**', '__', '`'], '', $text);
        $text = (string) preg_replace('/\(\s*\)|\s+(?=[.,;:!?])/u', '', $text);
        $text = trim(str_replace('|', ' ', $text));

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function trimUrl(string $url): string
    {
        return rtrim($url, '.,;:!?*');
    }
}
