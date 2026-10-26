<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Service\Research\Run\Stage\ResearchPage;
use App\Service\Research\Run\Stage\ResearchPageSource;

final class ReplayPages implements ResearchPageSource
{
    /** @var array<string, ResearchPage> */
    public array $pages = [];

    public function open(string $url): ResearchPage
    {
        return $this->pages[$url] ?? new ResearchPage(true, '', 'There was no text to read.', []);
    }
}
