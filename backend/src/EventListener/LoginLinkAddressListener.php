<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Repository\VerificationTokenRepository;
use App\Service\Auth\EmailLinkSignIn;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * A sign-in link belongs to the mailbox it was sent to. When an account's
 * sign-in email changes — in Profile, by an administrator, anywhere — the
 * links still open in the old mailbox stop working.
 */
#[AsDoctrineListener(event: Events::preUpdate)]
final readonly class LoginLinkAddressListener
{
    public function __construct(
        private VerificationTokenRepository $tokens,
    ) {
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $user = $args->getObject();
        if (!$user instanceof User || !$args->hasChangedField('mail') || null === $user->getId()) {
            return;
        }

        $this->tokens->deleteForUser($user->getId(), EmailLinkSignIn::TOKEN_TYPE);
    }
}
