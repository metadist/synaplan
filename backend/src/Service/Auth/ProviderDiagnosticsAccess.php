<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Who may receive a raw provider dump.
 *
 * The dump is stored on every failure. It leaves the server only for an
 * administrator on their own session, or for an administrator who is
 * impersonating the account this request is signed in as. The impersonated
 * person never qualifies: their own token has no impersonator claim and no
 * stash cookie, and a copied access token is not enough without the
 * admin's still-valid refresh token.
 */
final readonly class ProviderDiagnosticsAccess
{
    public function __construct(
        private Security $security,
        private ImpersonationService $impersonation,
        private RequestStack $requests,
    ) {
    }

    public function viewerMaySee(): bool
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        return $this->isImpersonatingThisUser($user);
    }

    /**
     * The authenticated principal must be the impersonation target named by
     * the access token that passed signature and expiry checks, and the
     * stashed refresh token must still belong to that same active admin.
     * A leftover stash, an API key, or a claim for a different account does
     * not open the dump.
     */
    private function isImpersonatingThisUser(User $user): bool
    {
        $request = $this->requests->getCurrentRequest();
        if (null === $request) {
            return false;
        }

        $claimed = $request->attributes->get(TokenService::IMPERSONATOR_ATTRIBUTE);
        if (!is_int($claimed) || $claimed <= 0) {
            return false;
        }

        $tokenUserId = $request->attributes->get('access_token_user_id');
        if (!is_int($tokenUserId) || $tokenUserId !== (int) $user->getId()) {
            return false;
        }

        $admin = $this->impersonation->resolveStashedAdmin($request);

        return $admin instanceof User && (int) $admin->getId() === $claimed;
    }
}
