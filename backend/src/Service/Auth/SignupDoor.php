<?php

declare(strict_types=1);

namespace App\Service\Auth;

/**
 * A door a stranger can walk through to get an account.
 *
 * Directory sign-in, the operator and channel identities are not doors here.
 * The value is the provider id stored on the user.
 */
enum SignupDoor: string
{
    case Email = 'local';
    case Google = 'google';
    case GitHub = 'github';
    case Apple = 'apple';
}
