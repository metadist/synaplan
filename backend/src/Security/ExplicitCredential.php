<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\ApiKey;
use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tells a request that names its caller from one the browser signed on its own.
 *
 * A session cookie rides along with every request to this origin, including
 * the one an `<img>` or a link in somebody else's text makes. A route that
 * changes something must not act on such a request when it arrives as a GET.
 * An `Authorization` or `X-API-Key` header, or a query token or API key that
 * resolved to the same account, cannot be attached that way.
 */
final class ExplicitCredential
{
    public static function accompanies(Request $request, ?User $user): bool
    {
        if (!$user instanceof User) {
            return false;
        }

        foreach (['Authorization', 'X-API-Key'] as $header) {
            if ('' !== trim((string) $request->headers->get($header, ''))) {
                return true;
            }
        }

        $userId = (int) $user->getId();
        if ($request->attributes->get(QueryTokenAuthenticator::USER_ID_ATTRIBUTE) === $userId) {
            return true;
        }

        $apiKey = $request->attributes->get('api_key');

        return $apiKey instanceof ApiKey && $apiKey->getOwnerId() === $userId;
    }
}
