<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ContinueSavedTaskAfterResearch;
use App\Service\Research\Run\ResearchSavedTaskResume;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ContinueSavedTaskAfterResearchHandler
{
    public function __construct(private ResearchSavedTaskResume $resume)
    {
    }

    public function __invoke(ContinueSavedTaskAfterResearch $message): void
    {
        $this->resume->continue($message);
    }
}
