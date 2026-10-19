<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Authorization checker with a fixed answer, for presenters built by hand.
 */
final readonly class FixedAuthorizationChecker implements AuthorizationCheckerInterface
{
    public function __construct(private bool $granted)
    {
    }

    public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
    {
        return $this->granted;
    }
}
