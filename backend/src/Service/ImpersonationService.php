<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Admin impersonation: lets an admin operate the application as another user.
 *
 * Token-claim strategy (no extra refresh tokens persisted)
 * --------------------------------------------------------
 * When an admin starts impersonating, we mint a single fresh access token for
 * the target user and embed the admin's id into it as an `impersonator_id`
 * HMAC-signed claim. The admin's own refresh token is moved aside into a
 * dedicated `admin_refresh_token` HttpOnly cookie (the "stash") and the
 * regular `refresh_token` cookie is cleared.
 *
 * Why this design:
 *   - We never persist an extra refresh token for the impersonated user, so
 *     the BTOKENS table stays clean.
 *   - If the impersonation access token leaks, the attacker has at most a
 *     5-minute session as the target user with no way to refresh, because
 *     the admin's stashed refresh token lives in an HttpOnly cookie they
 *     don't control.
 *   - `/auth/refresh` becomes the impersonation-aware seam: with the stash
 *     present, it re-uses the admin's refresh token to mint a fresh
 *     impersonation access token instead of a regular admin token. See
 *     {@see issueRefreshedImpersonationAccessToken()}.
 *   - The impersonation banner reads `impersonator_id` straight from the
 *     active access token — there is no second source of truth.
 *
 * Constraints (enforced server-side; the UI only mirrors them):
 *   - Only ROLE_ADMIN users may impersonate.
 *   - You cannot impersonate yourself.
 *   - You cannot impersonate another admin (privilege-escalation guard).
 *   - You cannot nest impersonations — exit first.
 *   - OIDC/Keycloak sessions are not supported, since we have no app-token
 *     refresh to stash. The caller gets a clean error and the impersonation
 *     is refused without mutating cookies.
 */
final readonly class ImpersonationService
{
    /** Stash cookie holding the admin's original refresh token while impersonating. */
    public const ADMIN_REFRESH_STASH_COOKIE = 'admin_refresh_token';

    /**
     * Stash cookie inherits the refresh-token TTL so a long impersonation does
     * not silently log the admin out when they hit "Exit". The token itself is
     * DB-backed and may be revoked at any time independently.
     */
    private const STASH_TTL_SECONDS = TokenService::REFRESH_TOKEN_TTL;

    public function __construct(
        private TokenService $tokenService,
        private UserRepository $userRepository,
        private LoggerInterface $logger,
        private string $appEnv,
    ) {
    }

    /**
     * Begin impersonating $target as $admin.
     *
     * Mutates the response by attaching:
     *   - `admin_refresh_token`: the admin's existing refresh token, set aside.
     *   - `access_token`: a freshly minted access token for $target with an
     *     `impersonator_id` claim pointing at $admin.
     *   - `refresh_token`: explicitly cleared, so a stolen browser cookie
     *     cannot be used to keep the impersonation alive past the access TTL
     *     without going through `/auth/refresh` (which is impersonation-aware
     *     and validates against the stash).
     *
     * @throws AccessDeniedException when any of the impersonation rules is violated
     */
    public function startImpersonation(
        User $admin,
        User $target,
        Request $request,
        Response $response,
    ): void {
        $this->assertCanImpersonate($admin, $target, $request);

        $currentRefresh = $request->cookies->get(TokenService::REFRESH_COOKIE);

        // Without a current app-token refresh cookie we have nothing to swap
        // back to on exit. This typically means the admin authenticated via
        // OIDC — refuse cleanly rather than stranding them in an unrecoverable
        // state.
        if (!is_string($currentRefresh) || '' === $currentRefresh) {
            throw new AccessDeniedException('Impersonation is not available for OIDC sessions. Please sign in with an application account first.');
        }

        $impersonationAccess = $this->tokenService->generateAccessToken(
            $target,
            impersonatorId: (int) $admin->getId(),
        );

        // Move the admin refresh into the stash slot. The regular refresh
        // cookie is explicitly cleared: the only legitimate path to a fresh
        // impersonation access token is through the impersonation-aware
        // refresh route, which reads from the stash.
        $response->headers->setCookie($this->buildStashCookie($currentRefresh));
        $response->headers->setCookie($this->tokenService->createAccessCookie($impersonationAccess));
        $response->headers->setCookie($this->tokenService->createClearRefreshCookie());

        $this->logger->warning('Admin started impersonation', [
            'admin_id' => $admin->getId(),
            'admin_email' => $admin->getMail(),
            'target_user_id' => $target->getId(),
            'target_email' => $target->getMail(),
            'ip' => $request->getClientIp(),
        ]);
    }

    /**
     * Stop the active impersonation and restore the admin session.
     *
     * Mutates the response by:
     *   - Issuing a fresh access token for the admin (the previous one was
     *     scoped to the impersonated user, so we must mint a new one).
     *   - Restoring the stashed refresh token into the regular slot.
     *   - Clearing the stash cookie.
     *
     * @return User The admin user (used by the controller to refresh the
     *              client-side auth state)
     *
     * @throws AccessDeniedException when no impersonation is active or the stash
     *                               refresh token is invalid / no longer maps
     *                               to an admin
     */
    public function stopImpersonation(Request $request, Response $response): User
    {
        $stashRefresh = $request->cookies->get(self::ADMIN_REFRESH_STASH_COOKIE);

        if (!is_string($stashRefresh) || '' === $stashRefresh) {
            throw new AccessDeniedException('No active impersonation to exit.');
        }

        $admin = $this->resolveAdminFromStashedRefresh($stashRefresh);

        if (!$admin) {
            // Stash was tampered with, expired, revoked, or the original
            // admin lost their role mid-session. Clear everything and force
            // a fresh login rather than leaving the user in an ambiguous
            // state.
            $this->attachClearStashCookies($response);
            $this->tokenService->clearAuthCookies($response);
            throw new AccessDeniedException('Impersonation session expired. Please sign in again.');
        }

        $freshAccess = $this->tokenService->generateAccessToken($admin);

        $response->headers->setCookie($this->tokenService->createAccessCookie($freshAccess));
        $response->headers->setCookie($this->tokenService->createRefreshCookie($stashRefresh));
        $this->attachClearStashCookies($response);

        $this->logger->warning('Admin stopped impersonation', [
            'admin_id' => $admin->getId(),
            'admin_email' => $admin->getMail(),
            'ip' => $request->getClientIp(),
        ]);

        return $admin;
    }

    /**
     * Impersonation-aware token refresh.
     *
     * Called by {@see \App\Controller\AuthController::refresh} when a stash
     * cookie is present on the request. Validates the admin's stashed refresh
     * token, looks up the impersonated user from the (possibly expired)
     * access cookie, and mints a fresh impersonation access token without
     * touching the refresh token.
     *
     * @return array{access_token: string, user: User}|null the new access
     *                                                      token plus the impersonated user, or null when the refresh
     *                                                      cannot be honoured (caller is expected to clear cookies)
     */
    public function issueRefreshedImpersonationAccessToken(Request $request): ?array
    {
        $stashRefresh = $request->cookies->get(self::ADMIN_REFRESH_STASH_COOKIE);
        if (!is_string($stashRefresh) || '' === $stashRefresh) {
            return null;
        }

        $admin = $this->resolveAdminFromStashedRefresh($stashRefresh);
        if (!$admin) {
            return null;
        }

        // Decode the active access token to recover the impersonation target.
        // We accept an expired token here — that is exactly the scenario in
        // which `/refresh` is called.
        $accessTokenString = $request->cookies->get(TokenService::ACCESS_COOKIE);
        if (!is_string($accessTokenString) || '' === $accessTokenString) {
            return null;
        }

        $payload = $this->tokenService->decodeAccessTokenIgnoringExpiry($accessTokenString);
        if (!$payload || !isset($payload['user_id'], $payload['impersonator_id'])) {
            return null;
        }

        // Defence in depth: the access token's impersonator claim must agree
        // with the stash. A mismatch means tampering — refuse loudly.
        if ((int) $payload['impersonator_id'] !== (int) $admin->getId()) {
            $this->logger->warning('Impersonation refresh refused: claim/stash mismatch', [
                'claim_impersonator_id' => $payload['impersonator_id'],
                'stash_admin_id' => $admin->getId(),
                'ip' => $request->getClientIp(),
            ]);

            return null;
        }

        $target = $this->userRepository->find($payload['user_id']);
        if (!$target instanceof User) {
            return null;
        }
        // Targets that have since been promoted to admin must not continue to
        // be impersonated — the privilege-escalation guard at start-time only
        // checks the initial state.
        if ($target->isAdmin()) {
            return null;
        }

        $freshAccess = $this->tokenService->generateAccessToken(
            $target,
            impersonatorId: (int) $admin->getId(),
        );

        return ['access_token' => $freshAccess, 'user' => $target];
    }

    /**
     * Read the impersonator off the active access token claim.
     *
     * Used by `/auth/me` to label the impersonation banner. Returns null when
     * the current request is a regular (non-impersonation) session.
     */
    public function resolveImpersonatorFromActiveSession(Request $request): ?User
    {
        $accessTokenString = $request->cookies->get(TokenService::ACCESS_COOKIE);
        if (!is_string($accessTokenString) || '' === $accessTokenString) {
            return null;
        }

        $payload = $this->tokenService->decodeAccessTokenIgnoringExpiry($accessTokenString);
        if (!$payload || !isset($payload['impersonator_id'])) {
            return null;
        }

        // Defence in depth: the stash cookie must also be present. If it is
        // missing (e.g. wiped by logout while the access cookie is somehow
        // still around), the impersonation is no longer recoverable and we
        // pretend it isn't happening — the next request lifecycle will clean
        // up.
        if (!$this->isImpersonating($request)) {
            return null;
        }

        $admin = $this->userRepository->find($payload['impersonator_id']);

        // Re-verify role on every read: the user might have been demoted
        // since impersonation started.
        if (!$admin instanceof User || !$admin->isAdmin()) {
            return null;
        }

        return $admin;
    }

    /**
     * Returns true when the request carries an active impersonation stash.
     * Cheap presence check without token decoding.
     */
    public function isImpersonating(Request $request): bool
    {
        $stash = $request->cookies->get(self::ADMIN_REFRESH_STASH_COOKIE);

        return is_string($stash) && '' !== $stash;
    }

    /**
     * Attach a cookie that clears the impersonation stash to the given response.
     *
     * Public so the auth controller can call it from logout / revoke-all /
     * login paths: a leftover stash from an interrupted impersonation must
     * never be pickable by the next user signing in on the same browser.
     */
    public function attachClearStashCookies(Response $response): void
    {
        $response->headers->setCookie($this->buildStashCookie('', expire: 1));
    }

    /**
     * @throws AccessDeniedException
     */
    private function assertCanImpersonate(User $admin, User $target, Request $request): void
    {
        if (!$admin->isAdmin()) {
            throw new AccessDeniedException('Only administrators may impersonate users.');
        }

        if ($admin->getId() === $target->getId()) {
            throw new AccessDeniedException('You cannot impersonate yourself.');
        }

        if ($target->isAdmin()) {
            throw new AccessDeniedException('You cannot impersonate another administrator.');
        }

        if ($this->isImpersonating($request)) {
            throw new AccessDeniedException('Already impersonating — exit the current session first.');
        }
    }

    /**
     * Validate the stashed refresh token against the DB and return the admin
     * user it belongs to, or null on any failure (revoked, expired, demoted).
     */
    private function resolveAdminFromStashedRefresh(string $stashRefresh): ?User
    {
        $tokenEntity = $this->tokenService->validateRefreshToken($stashRefresh);
        $admin = $tokenEntity?->getUser();

        if (!$admin instanceof User || !$admin->isAdmin()) {
            return null;
        }

        return $admin;
    }

    private function buildStashCookie(string $value, ?int $expire = null): Cookie
    {
        $isProduction = 'prod' === $this->appEnv;
        $expiresAt = $expire ?? (time() + self::STASH_TTL_SECONDS);

        return Cookie::create(self::ADMIN_REFRESH_STASH_COOKIE)
            ->withValue($value)
            ->withExpires($expiresAt)
            ->withPath('/')
            ->withSecure($isProduction)
            ->withHttpOnly(true)
            ->withSameSite($isProduction ? Cookie::SAMESITE_STRICT : Cookie::SAMESITE_LAX);
    }
}
