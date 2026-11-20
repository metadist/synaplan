<?php

declare(strict_types=1);

namespace App\Message;

/**
 * The owner decided on an approval a chat run waits for. The run reads the
 * decision from the approval row, never from this message.
 */
final readonly class ResumeChatRunCommand
{
    public function __construct(public int $approvalId)
    {
    }
}
