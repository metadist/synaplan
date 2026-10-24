<?php

namespace App\Security;

use App\Service\Auth\OidcAccessDeniedException;
use App\Service\Auth\OidcTokenService;
use App\Service\OidcUserService;
use App\Service\PlatformLink\Exception\PlatformDisconnectedException;
use App\Service\PlatformLink\PlatformBearerAttribution;
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
    /** JWT claims, so a later controller can read `azp` without parsing the token again. */
    public const CLAIMS_ATTRIBUTE = 'oidc.claims';

    public function __construct(
        private OidcTokenService $oidcTokenService,
        private OidcUserService $oidcUserService,
        private LoggerInterface $logger,
        private PlatformBearerAttribution $attribution,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        $token = $this->bearerToken($request);
        if (null === $token || str_starts_with($token, 'sk_')) {
            return false;
        }

        // API keys stay on ApiKeyAuthenticator. Bearer tokens are accepted on
        // the API and on exactly two OpenAI-compatible paths (D18).
        if (str_starts_with($request->getPathInfo(), '/v1/') && !$this->isChatPath($request)) {
            return false;
        }

        $apiKeyHeader = $request->headers->get('X-API-Key');
        if (is_string($apiKeyHeader) && '' !== trim($apiKeyHeader)) {
            return false;
        }

        $apiKeyQuery = $request->query->get('api_key');
        if (is_string($apiKeyQuery) && '' !== trim($apiKeyQuery)) {
            return false;
        }

        if ($request->cookies->has('access_token') || $request->cookies->has('oidc_access_token')) {
            return false;
        }

        if (3 !== count(explode('.', $token))) {
            return false;
        }

        return true;
    }

    private function bearerToken(Request $request): ?string
    {
        $authHeader = (string) $request->headers->get('Authorization', '');
        if (!str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        return substr($authHeader, 7);
    }

    private function isChatPath(Request $request): bool
    {
        $path = $request->getPathInfo();

        return ('POST' === $request->getMethod() && '/v1/chat/completions' === $path)
            || ('GET' === $request->getMethod() && '/v1/models' === $path);
    }

    public function authenticate(Request $request): Passport
    {
        $token = $this->bearerToken($request) ?? '';
        $claims = $this->oidcTokenService->validateBearerToken($token);

        if (!$claims) {
            throw new CustomUserMessageAuthenticationException('Invalid or expired OIDC bearer token');
        }

        $request->attributes->set(self::CLAIMS_ATTRIBUTE, $claims);

        // Find or create user via shared service (full claims include role data)
        try {
            $user = $this->oidcUserService->findOrCreateFromClaims($claims);
        } catch (OidcAccessDeniedException) {
            throw new CustomUserMessageAuthenticationException('Invalid or expired OIDC bearer token');
        }

        $this->attribution->attribute($user, $claims);

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
        if ($exception instanceof PlatformDisconnectedException) {
            return new JsonResponse([
                'error' => PlatformDisconnectedException::CODE,
                'message' => PlatformDisconnectedException::MESSAGE,
                'code' => PlatformDisconnectedException::CODE,
            ], Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse([
            'error' => 'Authentication failed',
            'message' => $exception->getMessage(),
            'code' => 'OIDC_BEARER_AUTH_FAILED',
        ], Response::HTTP_UNAUTHORIZED);
    }
}
