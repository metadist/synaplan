<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SendPasswordResetMessage;
use App\Service\Auth\PasswordResetRequest;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SendPasswordResetMessageHandler
{
    public function __construct(
        private PasswordResetRequest $passwordReset,
    ) {
    }

    public function __invoke(SendPasswordResetMessage $message): void
    {
        $this->passwordReset->deliver($message->email);
    }
}
