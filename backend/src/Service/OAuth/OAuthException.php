<?php

declare(strict_types=1);

namespace App\Service\OAuth;

/**
 * Any failure in the outbound OAuth2 flow that is not "the user must consent
 * again" ({@see OAuthReauthRequiredException} covers that case).
 */
class OAuthException extends \RuntimeException
{
}
