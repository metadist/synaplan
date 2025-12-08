<?php

namespace App\Security;

use App\Repository\UserRepository;
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
 * Falls back to Authorization header for API compatibility.
 */
class CookieTokenAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private TokenService $tokenService,
        private UserRepository $userRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        // Support requests with access token cookie OR Authorization header
        return $request->cookies->has(TokenService::ACCESS_COOKIE)
            || $request->headers->has('Authorization');
    }

    public function authenticate(Request $request): Passport
    {
        $token = $this->extractToken($request);

        if (!$token) {
            throw new CustomUserMessageAuthenticationException('No access token provided');
        }

        // Validate access token
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
     */
    private function extractToken(Request $request): ?string
    {
        // First try cookie (preferred)
        $token = $request->cookies->get(TokenService::ACCESS_COOKIE);

        if ($token) {
            return $token;
        }

        // Fall back to Authorization header (for API clients)
        $authHeader = $request->headers->get('Authorization');

        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }

        return null;
    }
}
