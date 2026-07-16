<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Rejects authentication for suspended or banned accounts (Apple Guideline 1.2:
 * "the ability to block abusive users").
 *
 * Wired as the firewall `user_checker`, so it runs for EVERY authenticator
 * (cookie/bearer token, API key, OIDC) — a suspended user's existing tokens stop
 * working immediately, no per-authenticator duplication. Password login has its
 * own explicit check in AuthController for a cleaner error message.
 */
final class AccountStatusChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (!$user->isActive()) {
            throw new CustomUserMessageAccountStatusException('This account has been suspended. Please contact support.');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        // No post-authentication checks required.
    }
}
