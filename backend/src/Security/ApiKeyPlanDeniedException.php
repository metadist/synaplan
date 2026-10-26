<?php

declare(strict_types=1);

namespace App\Security;

use App\Exception\ApiKeyPlanRequiredException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * An existing key belongs to an account that is not on a paid plan.
 */
final class ApiKeyPlanDeniedException extends AuthenticationException
{
    public function __construct()
    {
        parent::__construct(ApiKeyPlanRequiredException::MESSAGE);
    }
}
