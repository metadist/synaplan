<?php

declare(strict_types=1);

namespace App\Service\Setup;

/**
 * Why the wizard did not create the first administrator.
 */
enum SetupRefusal: string
{
    case TokenInvalid = 'SETUP_TOKEN_INVALID';
    case InProgress = 'SETUP_IN_PROGRESS';
    case AlreadyCompleted = 'SETUP_ALREADY_COMPLETED';
}
