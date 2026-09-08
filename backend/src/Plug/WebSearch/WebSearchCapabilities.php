<?php

declare(strict_types=1);

namespace App\Plug\WebSearch;

/**
 * What a web-search adapter can honour. Unused fields stay in `meta`.
 */
final readonly class WebSearchCapabilities
{
    public function __construct(
        public bool $freshness,
        public bool $country,
        public bool $language,
        public bool $siteFilter,
        public bool $fullContent,
        public bool $answer,
    ) {
    }

    public static function brave(): self
    {
        return new self(
            freshness: true,
            country: true,
            language: true,
            siteFilter: false,
            fullContent: false,
            answer: false,
        );
    }

    public static function searxng(): self
    {
        return new self(
            freshness: true,
            country: false,
            language: true,
            siteFilter: true,
            fullContent: false,
            answer: false,
        );
    }

    public static function tavily(): self
    {
        return new self(
            freshness: true,
            country: false,
            language: false,
            siteFilter: false,
            fullContent: true,
            answer: true,
        );
    }

    public static function exa(): self
    {
        return new self(
            freshness: true,
            country: false,
            language: false,
            siteFilter: true,
            fullContent: true,
            answer: false,
        );
    }

    public static function firecrawl(): self
    {
        return new self(
            freshness: false,
            country: false,
            language: false,
            siteFilter: false,
            fullContent: true,
            answer: false,
        );
    }

    public static function perplexity(): self
    {
        return new self(
            freshness: true,
            country: false,
            language: false,
            siteFilter: false,
            fullContent: false,
            answer: true,
        );
    }

    /**
     * @return array{freshness: bool, country: bool, language: bool, siteFilter: bool, fullContent: bool, answer: bool}
     */
    public function toArray(): array
    {
        return [
            'freshness' => $this->freshness,
            'country' => $this->country,
            'language' => $this->language,
            'siteFilter' => $this->siteFilter,
            'fullContent' => $this->fullContent,
            'answer' => $this->answer,
        ];
    }
}
