<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Source;

/**
 * Reads one source for a research run.
 *
 * RQ2's stub takes a URL. RS4's production reader implements this and adds
 * an optional cloud flag on the concrete class. RS6 widens the signature to
 * the source, the read budget and the world. The fixture host does not change.
 */
interface ResearchSourceReaderInterface
{
    public function read(string $url): SourceRead;
}
