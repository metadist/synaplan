<?php

namespace App\Security;

use App\Service\OidcTokenService;
use App\Service\OidcUserService;
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
 * OIDC Bearer Token Authenticator.
 *
 * Accepts Authorization: Bearer <keycloak-jwt> on API endpoints.
 * Used by external services (e.g., synaplan-opencloud) that perform
 * OIDC token exchange to call Synaplan API on behalf of a user.
 *
 * The token is a Keycloak-issued JWT validated via signature verification.
 * Users are auto-provisioned on first access via OidcUserService.
 */
class OidcBearerAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private OidcTokenService $oidcTokenService,
        private OidcUserService $oidcUserService,
        private LoggerInterface $logger,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        // Only handle Authorization: Bearer tokens
        $authHeader = (string) $request->headers->get('Authorization', '');
        if (!str_starts_with($authHeader, 'Bearer ')) {
            return false;
        }

        $token = substr($authHeader, 7);

        // Defer to ApiKeyAuthenticator for Synaplan API keys
        if (str_starts_with($token, 'sk_')) {
            return false;
        }

        // Defer to ApiKeyAuthenticator for /v1/ routes (OpenAI-compatible)
        if (str_starts_with($request->getPathInfo(), '/v1/')) {
            return false;
        }

        // Defer to other authenticators if API key signals are present
        $apiKeyHeader = $request->headers->get('X-API-Key');
        if (is_string($apiKeyHeader) && '' !== trim($apiKeyHeader)) {
            return false;
        }

        $apiKeyQuery = $request->query->get('api_key');
        if (is_string($apiKeyQuery) && '' !== trim($apiKeyQuery)) {
            return false;
        }

        // Defer to CookieTokenAuthenticator if cookies are present
        if ($request->cookies->has('access_token') || $request->cookies->has('oidc_access_token')) {
            return false;
        }

        // Only handle tokens that look like JWTs (three dot-separated parts)
        if (3 !== count(explode('.', $token))) {
            return false;
        }

        return true;
    }

    public function authenticate(Request $request): Passport
    {
        $authHeader = (string) $request->headers->get('Authorization', '');
        $token = substr($authHeader, 7);

        $claims = $this->oidcTokenService->validateBearerToken($token);

        if (!$claims) {
            throw new CustomUserMessageAuthenticationException('Invalid or expired OIDC bearer token');
        }

        // Find or create user via shared service (full claims include role data)
        $user = $this->oidcUserService->findOrCreateFromClaims($claims);

        $this->logger->debug('OIDC bearer token authenticated', [
            'user_id' => $user->getId(),
            'sub' => $claims['sub'] ?? 'unknown',
        ]);

        return new SelfValidatingPassport(
            new UserBadge((string) $user->getId(), fn () => $user)
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse([
            'error' => 'Authentication failed',
            'message' => $exception->getMessage(),
            'code' => 'OIDC_BEARER_AUTH_FAILED',
        ], Response::HTTP_UNAUTHORIZED);
    }
}
