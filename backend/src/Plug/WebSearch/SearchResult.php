<?php

declare(strict_types=1);

namespace App\Plug\WebSearch;

/**
 * One hit from any web-search adapter. `toLegacyRow()` feeds
 * {@see SearchResultSet::formatForAi()} so Brave's layout stays the default.
 */
final readonly class SearchResult
{
    public const KIND_WEB = 'web';
    public const KIND_ANSWER_CITATION = 'answer_citation';

    public function __construct(
        public string $title,
        public string $url,
        public string $description = '',
        public string $kind = self::KIND_WEB,
        public ?string $content = null,
        public ?string $publishedAt = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toLegacyRow(): array
    {
        return [
            'title' => $this->title,
            'url' => $this->url,
            'description' => $this->description,
            'age' => $this->publishedAt ?? '',
            'kind' => $this->kind,
            'content' => $this->content,
        ];
    }
}
