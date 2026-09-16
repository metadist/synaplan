<?php

declare(strict_types=1);

namespace App\Service\OAuth;

/**
 * The stored grant is gone for good: refresh token expired, consent revoked in
 * the tenant, password changed, or an admin removed the app.
 *
 * Distinct from {@see OAuthException} because the recovery differs — no retry
 * or backoff will help, only the user re-running consent. Callers mark the
 * connection {@see \App\Entity\Connection::STATUS_REAUTH_REQUIRED} instead of
 * counting it as a transient failure.
 */
final class OAuthReauthRequiredException extends OAuthException
{
}
