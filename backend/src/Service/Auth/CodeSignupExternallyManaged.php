<?php

declare(strict_types=1);

namespace App\Service\Auth;

/**
 * The invitation link reached an account that signs in through an identity
 * provider. No local session is issued.
 */
final class CodeSignupExternallyManaged extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This email is managed by an organisation.');
    }
}
