<?php

declare(strict_types=1);

namespace App\Service\Research\Eval;

use App\Service\Research\Spike\PassageSplitter;
use App\Service\Research\Spike\SpikeSources;

/**
 * Finds the text a citation points at. An evidence id uses the passage the
 * reader kept. A URL is read again with the spike reader and the passages
 * that share the most words and numbers with the statement are used.
 */
final class CitationPassages
{
    private const PASSAGES_PER_URL = 4;
    private const CHARS_PER_URL = 2500;
    private const CHARS_TOTAL = 5000;
    private const NUMBER_WEIGHT = 3;

    /**
     * @var array<string, list<string>|null>
     */
    private array $pages = [];

    public function __construct(
        private readonly SpikeSources $sources,
        private readonly PassageSplitter $splitter,
    ) {
    }

    /**
     * status is "ok", "unknown_ref" when no cited evidence id exists, or
     * "unreadable" when no cited page could be read.
     *
     * @param list<string>                                       $refs
     * @param array<string, array{url: string, passage: string}> $evidence
     *
     * @return array{status: string, passage: string}
     */
    public function forStatement(string $statement, array $refs, array $evidence): array
    {
        $texts = [];
        $unknown = 0;
        $unreadable = 0;
        foreach ($refs as $ref) {
            if (!str_starts_with($ref, 'http')) {
                $passage = $evidence[$ref]['passage'] ?? '';
                if ('' === trim($passage)) {
                    ++$unknown;
                } else {
                    $texts[] = $passage;
                }
                continue;
            }
            $passages = $this->page($ref);
            if (null === $passages) {
                ++$unreadable;
                continue;
            }
            $best = $this->best($statement, $passages);
            $texts[] = mb_substr(implode("\n", [] === $best ? \array_slice($passages, 0, self::PASSAGES_PER_URL) : $best), 0, self::CHARS_PER_URL);
        }
        if ([] === $texts) {
            return ['status' => $unknown > 0 ? 'unknown_ref' : 'unreadable', 'passage' => ''];
        }

        return ['status' => 'ok', 'passage' => mb_substr(implode("\n---\n", $texts), 0, self::CHARS_TOTAL)];
    }

    /**
     * @return list<string>|null
     */
    private function page(string $url): ?array
    {
        if (!\array_key_exists($url, $this->pages)) {
            try {
                $body = $this->sources->read($url);
                $this->pages[$url] = null === $body->blocked && '' !== trim($body->text)
                    ? array_map(static fn ($passage): string => $passage->text, $this->splitter->split($body->text))
                    : null;
            } catch (\Throwable) {
                $this->pages[$url] = null;
            }
        }

        return $this->pages[$url];
    }

    /**
     * @param list<string> $passages
     *
     * @return list<string>
     */
    private function best(string $statement, array $passages): array
    {
        [$words, $numbers] = $this->terms($statement);
        $scores = [];
        foreach ($passages as $index => $passage) {
            [$passageWords, $passageNumbers] = $this->terms($passage);
            $score = \count(array_intersect_key($words, $passageWords))
                + self::NUMBER_WEIGHT * \count(array_intersect_key($numbers, $passageNumbers));
            if ($score > 0) {
                $scores[$index] = $score;
            }
        }
        arsort($scores);
        $top = \array_slice(array_keys($scores), 0, self::PASSAGES_PER_URL);
        sort($top);

        return array_map(static fn (int $index): string => $passages[$index], $top);
    }

    /**
     * @return array{0: array<string, true>, 1: array<string, true>}
     */
    private function terms(string $text): array
    {
        $words = [];
        $numbers = [];
        preg_match_all('/\p{L}{4,}/u', mb_strtolower($text), $wordMatches);
        foreach ($wordMatches[0] as $word) {
            $words[$word] = true;
        }
        preg_match_all('/\d+(?:[.,]\d+)*/u', $text, $numberMatches);
        foreach ($numberMatches[0] as $number) {
            $numbers[str_replace(',', '.', $number)] = true;
        }

        return [$words, $numbers];
    }
}
