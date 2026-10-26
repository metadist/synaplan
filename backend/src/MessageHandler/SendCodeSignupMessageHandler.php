<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SendCodeSignupMessage;
use App\Service\Auth\CodeSignup;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SendCodeSignupMessageHandler
{
    public function __construct(
        private CodeSignup $signup,
    ) {
    }

    public function __invoke(SendCodeSignupMessage $message): void
    {
        $this->signup->deliver($message->email, $message->codeId, $message->locale);
    }
}
