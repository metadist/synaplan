<?php

namespace App\Security;

use App\Repository\UserRepository;
use App\Service\OidcTokenService;
use App\Service\TokenService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Cookie-based Token Authenticator.
 *
 * Validates access tokens from HttpOnly cookies.
 * Supports both app tokens and OIDC tokens (Keycloak).
 * Falls back to Authorization header for API compatibility.
 */
class CookieTokenAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private TokenService $tokenService,
        private OidcTokenService $oidcTokenService,
        private UserRepository $userRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        // Support requests with access token cookie OR OIDC token cookie OR Authorization header
        return $request->cookies->has(TokenService::ACCESS_COOKIE)
            || $request->cookies->has(OidcTokenService::OIDC_ACCESS_COOKIE)
            || $request->headers->has('Authorization');
    }

    public function authenticate(Request $request): Passport
    {
        $token = $this->extractToken($request);

        if (!$token) {
            throw new CustomUserMessageAuthenticationException('No access token provided');
        }

        // Check if OIDC token (try OIDC validation first if OIDC cookies present)
        if ($request->cookies->has(OidcTokenService::OIDC_ACCESS_COOKIE)) {
            $oidcProvider = $request->cookies->get(OidcTokenService::OIDC_PROVIDER_COOKIE, 'keycloak');

            // Try OIDC token validation
            $user = $this->oidcTokenService->getUserFromOidcToken($token, $oidcProvider);

            if ($user) {
                $this->logger->debug('OIDC token authenticated successfully', [
                    'user_id' => $user->getId(),
                    'provider' => $oidcProvider,
                ]);

                return new SelfValidatingPassport(
                    new UserBadge((string) $user->getId(), fn () => $user)
                );
            }

            // OIDC validation failed - token might be expired
            $this->logger->debug('OIDC token validation failed, will trigger refresh');
            throw new CustomUserMessageAuthenticationException('OIDC token expired');
        }

        // Fall back to app token validation
        $payload = $this->tokenService->validateAccessToken($token);

        if (!$payload) {
            throw new CustomUserMessageAuthenticationException('Invalid or expired access token');
        }

        $userId = $payload['user_id'] ?? null;

        if (!$userId) {
            throw new CustomUserMessageAuthenticationException('Invalid token payload');
        }

        return new SelfValidatingPassport(
            new UserBadge((string) $userId, function (string $userIdentifier) {
                $user = $this->userRepository->find((int) $userIdentifier);

                if (!$user) {
                    throw new CustomUserMessageAuthenticationException('User not found');
                }

                return $user;
            })
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Let the request continue
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        // Return 401 with info about the error
        return new JsonResponse([
            'error' => 'Authentication failed',
            'message' => $exception->getMessage(),
            'code' => 'AUTH_FAILED',
        ], Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Extract token from cookie or header.
     *
     * Priority: OIDC token > App token > Authorization header
     */
    private function extractToken(Request $request): ?string
    {
        // First try OIDC token (if present)
        $oidcToken = $request->cookies->get(OidcTokenService::OIDC_ACCESS_COOKIE);
        if ($oidcToken) {
            return $oidcToken;
        }

        // Then try app token
        $appToken = $request->cookies->get(TokenService::ACCESS_COOKIE);
        if ($appToken) {
            return $appToken;
        }

        // Fall back to Authorization header (for API clients)
        $authHeader = $request->headers->get('Authorization');
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }

        return null;
    }
}
