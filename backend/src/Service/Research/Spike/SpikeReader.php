<?php

declare(strict_types=1);

namespace App\Service\Research\Spike;

/**
 * One source becomes evidence cards. A missing passage drops the finding.
 * A lead survives only when its URL is in the page's own link list.
 */
final class SpikeReader
{
    private const PASSAGE_BUDGET = 12000;

    public function __construct(
        private readonly SpikeChat $chat,
        private readonly PassageSplitter $splitter,
        private readonly PassageCheck $check,
        private readonly LeadUrlFilter $urls,
        private readonly JsonSlice $json,
        private readonly PromptBook $prompts,
    ) {
    }

    /**
     * @param list<array{id: string, title: string}> $points
     *
     * @return array{cards: list<array{point: string, fact: string, quote: ?string, passage: string}>, leads: list<string>, dropped: int, kept: int, blocked: ?string, note: string, inputTokens: int, outputTokens: int}
     */
    public function read(string $text, array $links, array $points, string $language, int $userId, ?string $sharper = null): array
    {
        if ('' === trim($text)) {
            return ['cards' => [], 'leads' => [], 'dropped' => 0, 'kept' => 0, 'blocked' => 'no_text', 'note' => '', 'inputTokens' => 0, 'outputTokens' => 0];
        }

        $passages = $this->splitter->split($text);
        $shown = $this->shownLinks($links);
        $source = $this->numbered($passages)."\n<links>\n".$this->linkLines($shown)."\n</links>";
        $turn = $this->chat->complete([
            ['role' => 'system', 'content' => $this->prompts->fill('research_read', [
                'TODAY' => gmdate('Y-m-d'),
                'LANGUAGE' => $language,
                'POINTS' => $this->pointLines($points),
                'SOURCE' => $source,
            ])],
            ['role' => 'user', 'content' => null !== $sharper && '' !== trim($sharper) ? 'Sharper question: '.$sharper : 'Extract evidence from the source.'],
        ], [], $userId);

        $parsed = $this->json->object($turn->content);
        $cards = [];
        $dropped = 0;
        foreach ($parsed['findings'] ?? [] as $row) {
            if (!\is_array($row) || !\is_string($row['fact'] ?? null)) {
                continue;
            }
            $ids = [];
            foreach ($row['passages'] ?? [] as $id) {
                if (\is_string($id) && '' !== $id) {
                    $ids[] = $id;
                }
            }
            $quote = \is_string($row['quote'] ?? null) && '' !== trim($row['quote']) ? $row['quote'] : null;
            $checked = $this->check->apply([new Finding($row['fact'], $ids, $quote)], $passages);
            $dropped += $checked['dropped'];
            $kept = $checked['kept'][0] ?? null;
            if (!$kept instanceof Finding) {
                continue;
            }
            $point = $row['point'] ?? 'p1';
            $cards[] = [
                'point' => \is_string($point) ? $point : 'p1',
                'fact' => $kept->fact,
                'quote' => $kept->quote,
                'passage' => $this->passageText($kept->passageIds, $passages),
            ];
        }

        return [
            'cards' => $cards,
            'leads' => $this->leads($parsed['leads'] ?? [], $shown),
            'dropped' => $dropped,
            'kept' => \count($cards),
            'blocked' => null,
            'note' => [] === $cards ? mb_substr(preg_replace('/\s+/u', ' ', $turn->content) ?? '', 0, 240) : '',
            'inputTokens' => $turn->inputTokens,
            'outputTokens' => $turn->outputTokens,
        ];
    }

    /**
     * @param list<string>  $ids
     * @param list<Passage> $passages
     */
    private function passageText(array $ids, array $passages): string
    {
        $texts = [];
        foreach ($passages as $passage) {
            if (\in_array($passage->id, $ids, true)) {
                $texts[] = $passage->text;
            }
        }

        return implode("\n", $texts);
    }

    /**
     * @param list<Passage> $passages
     */
    private function numbered(array $passages): string
    {
        $lines = [];
        $used = 0;
        foreach ($passages as $passage) {
            $line = '['.$passage->id.'] '.$passage->text;
            if ($used + mb_strlen($line) > self::PASSAGE_BUDGET) {
                $lines[] = '[note] The rest of this source was not sent.';
                break;
            }
            $lines[] = $line;
            $used += mb_strlen($line);
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $links
     *
     * @return list<string>
     */
    private function shownLinks(array $links): array
    {
        $shown = [];
        foreach ($links as $link) {
            $canonical = $this->urls->canonical($link);
            if (null === $canonical || isset($shown[$canonical])) {
                continue;
            }
            $shown[$canonical] = $canonical;
            if (\count($shown) >= 8) {
                break;
            }
        }

        return array_values($shown);
    }

    /**
     * @param list<string> $links
     */
    private function linkLines(array $links): string
    {
        if ([] === $links) {
            return 'none';
        }
        $lines = [];
        foreach ($links as $index => $link) {
            $lines[] = 'L'.($index + 1).' '.$link;
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array{id: string, title: string}> $points
     */
    private function pointLines(array $points): string
    {
        $lines = [];
        foreach ($points as $point) {
            $lines[] = $point['id'].' '.$point['title'];
        }

        return [] === $lines ? 'p1 the question' : implode("\n", $lines);
    }

    /**
     * @param list<string> $shown
     *
     * @return list<string>
     */
    private function leads(mixed $rows, array $shown): array
    {
        if (!\is_array($rows)) {
            return [];
        }
        $proposed = [];
        foreach ($rows as $row) {
            if (!\is_array($row) || !\is_string($row['link'] ?? null)) {
                continue;
            }
            if (1 === preg_match('/^L(\d+)$/', $row['link'], $match)) {
                $index = (int) $match[1] - 1;
                if (isset($shown[$index])) {
                    $proposed[] = $shown[$index];
                }
                continue;
            }
            $proposed[] = $row['link'];
        }

        return $this->urls->keep($proposed, $shown);
    }
}
