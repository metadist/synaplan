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
        $this->body = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $body)), 0, self::MAX_BODY_LENGTH);
    }

    public function hash(): string
    {
        return sha1($this->title."\n".$this->body);
    }
}
