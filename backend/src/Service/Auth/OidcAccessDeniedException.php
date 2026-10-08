<?php

declare(strict_types=1);

namespace App\Service\Auth;

/**
 * An authenticated OIDC identity that this instance does not admit.
 *
 * The message is safe to log; it never carries token contents. Callers that
 * answer a browser or API client must use {@see self::ERROR_CODE} and a
 * generic sentence instead of the message.
 */
final class OidcAccessDeniedException extends \RuntimeException
{
    public const ERROR_CODE = 'oidc_not_authorized';
    public const USER_MESSAGE = 'Your company account is not allowed to use this workspace. Nothing was signed in and no account was created. Ask an administrator to give you access.';

    public function __construct(public readonly OidcAccessDenialReason $reason)
    {
        parent::__construct(sprintf('OIDC access denied: %s', $reason->value));
    }
}
