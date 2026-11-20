<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ResumeChatRunCommand;
use App\Service\Chat\Loop\ChatRunResume;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ResumeChatRunCommandHandler
{
    public function __construct(private ChatRunResume $resume)
    {
    }

    public function __invoke(ResumeChatRunCommand $command): void
    {
        $this->resume->decided($command->approvalId);
    }
}
