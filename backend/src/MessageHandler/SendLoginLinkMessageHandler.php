<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SendLoginLinkMessage;
use App\Service\Auth\EmailLinkSignIn;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SendLoginLinkMessageHandler
{
    public function __construct(
        private EmailLinkSignIn $signIn,
    ) {
    }

    public function __invoke(SendLoginLinkMessage $message): void
    {
        $this->signIn->deliver($message->email);
    }
}
