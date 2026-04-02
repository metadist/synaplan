<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\JwtValidator;
use App\Service\OidcTokenService;
use Doctrine\ORM\EntityManagerInterface;
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
 * Users are auto-provisioned on first access.
 */
class OidcBearerAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private OidcTokenService $oidcTokenService,
        private UserRepository $userRepository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private string $oidcDiscoveryUrl,
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

        // Validate via OidcTokenService (JWT signature + claims)
        $user = $this->oidcTokenService->getUserFromOidcToken($token);

        if ($user) {
            $this->logger->debug('OIDC bearer token authenticated existing user', [
                'user_id' => $user->getId(),
            ]);

            return new SelfValidatingPassport(
                new UserBadge((string) $user->getId(), fn () => $user)
            );
        }

        // User not found — try auto-provisioning
        $claims = $this->oidcTokenService->validateOidcToken($token);

        if (!$claims) {
            throw new CustomUserMessageAuthenticationException('Invalid or expired OIDC bearer token');
        }

        $user = $this->provisionUser($claims);

        $this->logger->info('OIDC bearer token auto-provisioned new user', [
            'user_id' => $user->getId(),
            'sub' => $claims['sub'] ?? 'unknown',
            'email' => $claims['email'] ?? 'unknown',
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

    /**
     * Auto-provision a Synaplan user from OIDC claims.
     *
     * Same logic as KeycloakAuthController::findOrCreateUser but without
     * provider conflict checks (bearer tokens come from token exchange,
     * the user may not have logged in via browser before).
     */
    private function provisionUser(array $claims): User
    {
        $sub = $claims['sub'] ?? null;
        $email = $claims['email'] ?? null;

        if (!$sub) {
            throw new CustomUserMessageAuthenticationException('OIDC token missing subject claim');
        }

        $user = new User();
        $user->setMail($email ?? $sub.'@oidc.local');
        $user->setType('WEB');
        $user->setProviderId('keycloak');
        $user->setUserLevel('NEW');
        $user->setEmailVerified(true);
        $user->setCreated(date('Y-m-d H:i:s'));

        $userDetails = [
            'oidc_sub' => $sub,
            'oidc_email' => $email,
            'oidc_username' => $claims['preferred_username'] ?? null,
            'oidc_last_login' => (new \DateTime())->format('Y-m-d H:i:s'),
        ];

        if (isset($claims['given_name'])) {
            $userDetails['first_name'] = $claims['given_name'];
        }
        if (isset($claims['family_name'])) {
            $userDetails['last_name'] = $claims['family_name'];
        }
        if (isset($claims['name'])) {
            $userDetails['full_name'] = $claims['name'];
        }

        $user->setUserDetails($userDetails);
        $user->setPaymentDetails([]);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
