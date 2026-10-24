<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\Service\Research\Run\Source\ResearchSourceReader;

/**
 * Opens a page through the existing reader. Link extraction is not in that
 * reader yet, so this returns no links and a lead cannot be invented.
 */
final readonly class UrlResearchPageSource implements ResearchPageSource
{
    public function __construct(private ResearchSourceReader $pages)
    {
    }

    public function open(string $url): ResearchPage
    {
        $read = $this->pages->read($url);

        return new ResearchPage($read->blocked, $read->text, $read->reason, []);
    }
}
