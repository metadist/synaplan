<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Source;

/**
 * Reads one source for a research run.
 *
 * RQ2's stub takes a URL. RS6 widens this to the source, the read budget and
 * the world, and adds the production reader. The fixture host does not change.
 */
interface ResearchSourceReaderInterface
{
    public function read(string $url): SourceRead;
}
