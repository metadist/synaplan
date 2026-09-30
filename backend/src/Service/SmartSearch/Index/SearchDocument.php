<?php

declare(strict_types=1);

namespace App\Service\SmartSearch\Index;

/**
 * One searchable item as it is stored in BSEARCHINDEX.
 */
final readonly class SearchDocument
{
    public const MAX_TITLE_LENGTH = 255;
    public const MAX_BODY_LENGTH = 4000;

    public string $title;
    public string $body;

    public function __construct(
        public int $userId,
        public string $kind,
        public string $refId,
        string $title,
        string $body,
        public int $updated,
        public ?string $lang = null,
    ) {
        $this->title = mb_substr(trim($title), 0, self::MAX_TITLE_LENGTH);
        $this->body = mb_substr(trim(self::titleWords($this->title).' '.preg_replace('/\s+/u', ' ', $body)), 0, self::MAX_BODY_LENGTH);
    }

    /**
     * FULLTEXT keeps `_` inside a token, so `invoice_march.pdf` is one word.
     * The split form in the body makes "invoice" and "march" findable.
     */
    public static function titleWords(string $title): string
    {
        if (!preg_match('/[_.\-]/u', $title)) {
            return '';
        }

        return trim((string) preg_replace('/[\s_.\-]+/u', ' ', $title));
    }

    /** The stored body without the split title words (for snippets). */
    public static function contentOf(string $title, string $body): string
    {
        $words = self::titleWords($title);

        return '' !== $words && str_starts_with($body, $words) ? ltrim(substr($body, strlen($words))) : $body;
    }

    public function hash(): string
    {
        return sha1($this->title."\n".$this->body);
    }
}
