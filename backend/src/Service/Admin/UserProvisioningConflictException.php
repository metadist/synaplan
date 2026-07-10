<?php

declare(strict_types=1);

namespace App\Service\Admin;

/**
 * Thrown when a provisioning request cannot be satisfied idempotently — e.g.
 * the email already belongs to a user created through a different auth
 * provider, so silently attaching an external identity would hijack it.
 */
final class UserProvisioningConflictException extends \RuntimeException
{
}
