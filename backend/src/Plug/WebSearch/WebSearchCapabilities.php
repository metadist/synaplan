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
}
