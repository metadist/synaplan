<?php

declare(strict_types=1);

namespace Plugin\Synaform\Service;

/**
 * Outcome of reading one applicant page.
 *
 * Text is set only for a public page that answered 2xx with readable
 * content. A warning is the sentence shown to the user. Refused means the
 * address itself is not a public web page, so the caller must not store it
 * on a new entry and must drop any text already stored.
 */
final readonly class ApplicantUrlFetch
{
    public function __construct(
        public ?string $text,
        public ?string $warning,
        public bool $refused,
    ) {
    }

    /**
     * Text already stored on an entry, or null when a warning says it was
     * not a readable public page. Error bodies saved before this check are
     * dropped the same way.
     */
    public static function storedText(mixed $snippet, mixed $warning): ?string
    {
        if (is_string($warning) && '' !== trim($warning)) {
            return null;
        }
        if (!is_string($snippet) || '' === trim($snippet)) {
            return null;
        }

        return $snippet;
    }
}
