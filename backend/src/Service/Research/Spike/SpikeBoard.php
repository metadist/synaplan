<?php

declare(strict_types=1);

namespace App\Service\Research\Spike;

/**
 * Sources and evidence for one spike run. The lead sees cards, never the page text.
 */
final class SpikeBoard
{
    /**
     * @var array<string, array{url: string, title: string, snippet: string, read: bool}>
     */
    public array $sources = [];

    /**
     * @var list<string>
     */
    public array $queries = [];

    /**
     * @var list<array{id: string, point: string, fact: string, quote: ?string, url: string, passage: string}>
     */
    public array $cards = [];

    public int $dropped = 0;

    public int $kept = 0;

    public string $readLog = '';

    public int $inputTokens = 0;

    public int $outputTokens = 0;

    private int $nextSource = 1;

    private int $nextEvidence = 1;

    public function __construct(
        public int $searchesLeft,
        public int $readsLeft,
    ) {
    }

    public function hasQuery(string $query): bool
    {
        $needle = mb_strtolower(trim($query));

        return \in_array($needle, $this->queries, true);
    }

    public function rememberQuery(string $query): void
    {
        $this->queries[] = mb_strtolower(trim($query));
    }

    public function addHit(SearchHit $hit): string
    {
        foreach ($this->sources as $id => $source) {
            if ($source['url'] === $hit->url) {
                return $id;
            }
        }
        $id = 's'.$this->nextSource;
        ++$this->nextSource;
        $this->sources[$id] = [
            'url' => $hit->url,
            'title' => mb_substr($hit->title, 0, 120),
            'snippet' => mb_substr($hit->snippet, 0, 300),
            'read' => false,
        ];

        return $id;
    }

    /**
     * @param array{point: string, fact: string, quote: ?string, passage?: string} $card
     */
    public function addCard(array $card, string $url): string
    {
        $id = 'e'.$this->nextEvidence;
        ++$this->nextEvidence;
        $this->cards[] = [
            'id' => $id,
            'point' => $card['point'],
            'fact' => $card['fact'],
            'quote' => $card['quote'],
            'url' => $url,
            'passage' => $card['passage'] ?? '',
        ];

        return $id;
    }

    /**
     * The passages each evidence id rests on, for the citation judge.
     *
     * @return array<string, array{url: string, passage: string}>
     */
    public function evidence(): array
    {
        $out = [];
        foreach ($this->cards as $card) {
            $out[$card['id']] = ['url' => $card['url'], 'passage' => $card['passage']];
        }

        return $out;
    }

    public function foundSummary(): string
    {
        if ([] === $this->cards) {
            return 'nothing yet';
        }
        $lines = [];
        foreach ($this->cards as $card) {
            $lines[] = $card['id'].' '.$card['point'].' '.$card['fact'];
        }

        return implode("\n", $lines);
    }

    public function unreadSummary(): string
    {
        $lines = [];
        foreach ($this->sources as $id => $source) {
            if ($source['read']) {
                continue;
            }
            $lines[] = $id.' '.$source['title'].' '.$source['url'];
        }

        return [] === $lines ? 'none' : implode("\n", $lines);
    }

    public function evidenceBlock(): string
    {
        if ([] === $this->cards) {
            return 'none';
        }
        $lines = [];
        foreach ($this->cards as $card) {
            $quote = null !== $card['quote'] ? ' quote: '.$card['quote'] : '';
            $lines[] = $card['id'].' | '.$card['point'].' | '.$card['fact'].$quote;
        }

        return implode("\n", $lines);
    }
}
