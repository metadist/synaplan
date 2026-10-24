<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

interface ResearchPageSource
{
    public function open(string $url): ResearchPage;
}
