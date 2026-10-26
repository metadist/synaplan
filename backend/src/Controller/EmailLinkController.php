<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Auth\EmailLinkRateLimited;
use App\Service\Auth\EmailLinkSignIn;
use App\Service\Auth\ImpersonationService;
use App\Service\Auth\TokenService;
use App\Service\Billing\PayFirst\PayFirstConfig;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/auth/email-link', name: 'auth_email_link_')]
#[OA\Tag(name: 'Authentication')]
final class EmailLinkController extends AbstractController
{
    public function __construct(
        private readonly PayFirstConfig $payFirst,
        private readonly EmailLinkSignIn $signIn,
        private readonly TokenService $tokenService,
        private readonly ImpersonationService $impersonation,
    ) {
    }

    #[Route('', name: 'request', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/auth/email-link',
        operationId: 'requestEmailSignInLink',
        summary: 'Mail a one-time sign-in link; answers the same whether or not the address has an account',
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email'],
            properties: [new OA\Property(property: 'email', type: 'string', format: 'email', example: 'ada@example.com')]
        )),
        responses: [
            new OA\Response(response: 200, description: 'If an account exists for the address, a link is on its way (15 minutes, single use)', content: new OA\JsonContent(
                required: ['success'],
                properties: [new OA\Property(property: 'success', type: 'boolean', example: true)]
            )),
            new OA\Response(response: 404, description: 'Sign-in links are not active on this installation'),
            new OA\Response(response: 429, description: 'Too many requests from this connection or for this address (EMAIL_LINK_RATE_LIMITED)'),
        ]
    )]
    public function request(Request $request): JsonResponse
    {
        if (!$this->payFirst->isActive()) {
            return $this->json(['success' => false, 'error' => 'Not found.', 'code' => 'EMAIL_LINK_OFF'], Response::HTTP_NOT_FOUND);
        }
        $body = json_decode($request->getContent(), true);
        try {
            $this->signIn->request(is_array($body) && is_string($body['email'] ?? null) ? $body['email'] : '', $request->getClientIp());
        } catch (EmailLinkRateLimited $e) {
            return $this->json(
                ['success' => false, 'error' => 'Too many attempts. Please wait a few minutes and try again.', 'code' => 'EMAIL_LINK_RATE_LIMITED'],
                Response::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => (string) $e->retryAfterSeconds],
            );
        }

        return $this->json(['success' => true]);
    }

    #[Route('/consume', name: 'consume', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/auth/email-link/consume',
        operationId: 'consumeEmailSignInLink',
        summary: 'Sign in with the token from a one-time link; sets the auth cookies and marks the address verified',
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['token'],
            properties: [new OA\Property(property: 'token', type: 'string', example: '3f9a…')]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Signed in', content: new OA\JsonContent(
                required: ['success'],
                properties: [new OA\Property(property: 'success', type: 'boolean', example: true)]
            )),
            new OA\Response(response: 400, description: 'The link is unknown, expired or already used (EMAIL_LINK_INVALID)'),
        ]
    )]
    public function consume(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        $user = $this->signIn->consume(is_array($body) && is_string($body['token'] ?? null) ? $body['token'] : '');
        if (null === $user) {
            return $this->json([
                'success' => false,
                'error' => 'This sign-in link has expired or was already used. Ask for a new one on the sign-in page.',
                'code' => 'EMAIL_LINK_INVALID',
            ], Response::HTTP_BAD_REQUEST);
        }

        $response = $this->json(['success' => true]);
        $this->tokenService->addAuthCookies($response, $this->tokenService->generateAccessToken($user), $this->tokenService->generateRefreshToken($user, $request->getClientIp()));
        $this->impersonation->attachClearStashCookies($response);

        return $response;
    }
}
