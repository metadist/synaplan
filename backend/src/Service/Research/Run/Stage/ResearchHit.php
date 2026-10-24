<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\Service\Research\Run\Ledger\CanonicalUrl;
use App\Service\Research\Run\Ledger\PlainText;

/** One search hit, already one line, so a page title cannot become an instruction. */
final readonly class ResearchHit
{
    public function __construct(
        public string $url,
        public string $title,
        public string $snippet,
        public ?string $date,
    ) {
    }

    public static function from(string $url, string $title, string $snippet, ?string $date): ?self
    {
        $key = CanonicalUrl::key($url);
        if (null === $key) {
            return null;
        }
        $when = null === $date ? null : PlainText::line($date, 40);

        return new self(
            $key,
            PlainText::line($title, 120),
            PlainText::line($snippet, 300),
            null === $when || '' === $when ? null : $when,
        );
    }
}
