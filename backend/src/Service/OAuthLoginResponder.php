<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds the final response after a successful (or failed) OAuth callback,
 * branching between the web and native (mobile) flows.
 *
 * Web: redirect to the SPA `/auth/callback` with HttpOnly auth cookies set.
 * Native: redirect to the app's custom-scheme deep link carrying a short-lived
 * handoff token (NativeAuthHandoffService) that the app exchanges for Bearer
 * tokens — cookies are useless cross-origin in the WebView.
 *
 * Centralises the per-provider duplication (Google/GitHub/Keycloak controllers
 * all ended with the same cookie-redirect block).
 */
final readonly class OAuthLoginResponder
{
    public function __construct(
        private TokenService $tokenService,
        private ImpersonationService $impersonationService,
        private NativeAuthHandoffService $handoffService,
        private string $frontendUrl,
        private string $nativeDeepLinkScheme,
    ) {
    }

    /**
     * Success response for an authenticated user.
     */
    public function success(User $user, Request $request, string $provider, bool $native): Response
    {
        if ($native) {
            return new RedirectResponse($this->deepLink([
                'success' => 'true',
                'provider' => $provider,
                'handoff' => $this->handoffService->generate($user),
            ]));
        }

        $accessToken = $this->tokenService->generateAccessToken($user);
        $refreshToken = $this->tokenService->generateRefreshToken($user, $request->getClientIp());

        $response = new RedirectResponse($this->frontendUrl.'/auth/callback?'.http_build_query([
            'success' => 'true',
            'provider' => $provider,
        ]));

        // Set fresh auth cookies and defensively wipe any orphan impersonation
        // stash that survived from a prior session on this browser, so a new
        // sign-in can never inherit a previous admin's suspended session.
        $this->tokenService->addAuthCookies($response, $accessToken, $refreshToken);
        $this->impersonationService->attachClearStashCookies($response);

        return $response;
    }

    /**
     * Error response routed to the right place for the client type.
     */
    public function error(string $provider, string $error, bool $native): Response
    {
        if ($native) {
            return new RedirectResponse($this->deepLink([
                'error' => $error,
                'provider' => $provider,
            ]));
        }

        return new RedirectResponse($this->frontendUrl.'/auth/callback?'.http_build_query([
            'error' => $error,
            'provider' => $provider,
        ]));
    }

    /**
     * @param array<string, string> $query
     */
    private function deepLink(array $query): string
    {
        // e.g. com.synaplan.app://oauth/callback?success=true&provider=google&handoff=...
        return sprintf('%s://oauth/callback?%s', $this->nativeDeepLinkScheme, http_build_query($query));
    }
}
