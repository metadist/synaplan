<?php

namespace App\Security;

use App\Repository\UserRepository;
use App\Service\TokenService;
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
 * Authenticator for access tokens in query parameters.
 * Used for EventSource/SSE endpoints that can't send cookies.
 */
class QueryTokenAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private TokenService $tokenService,
        private UserRepository $userRepository,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        // Only support requests with ?token= query parameter
        return $request->query->has('token');
    }

    public function authenticate(Request $request): Passport
    {
        $token = $request->query->get('token');

        if (!$token) {
            throw new CustomUserMessageAuthenticationException('No token provided');
        }

        // Validate using our TokenService
        $payload = $this->tokenService->validateAccessToken($token);

        if (!$payload) {
            throw new CustomUserMessageAuthenticationException('Invalid or expired token');
        }

        if (!isset($payload['user_id'])) {
            throw new CustomUserMessageAuthenticationException('Token missing user ID');
        }

        return new SelfValidatingPassport(
            new UserBadge((string) $payload['user_id'], function (string $userIdentifier) {
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
        return new JsonResponse([
            'error' => 'Authentication failed',
            'message' => $exception->getMessage(),
        ], Response::HTTP_UNAUTHORIZED);
    }
}
