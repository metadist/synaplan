<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Lead;

use App\Service\Research\Run\Stage\ResearchPage;
use App\Service\Research\Run\Stage\ResearchPageSource;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Recorded pages for the conformance suite. An unknown address is blocked. */
#[Exclude]
final class ScriptedPages implements ResearchPageSource
{
    /** @var array<string, ResearchPage> */
    public array $pages = [];

    public int $opened = 0;

    public function open(string $url): ResearchPage
    {
        ++$this->opened;

        return $this->pages[$url] ?? new ResearchPage(true, '', 'There was no text to read.', []);
    }
}
