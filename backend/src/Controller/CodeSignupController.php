<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Auth\CodeSignup;
use App\Service\Auth\CodeSignupRateLimited;
use App\Service\Auth\ImpersonationService;
use App\Service\Auth\SignupBlockedException;
use App\Service\Auth\SignupProtection;
use App\Service\Auth\TokenService;
use App\Service\Billing\PayFirst\PayFirstConfig;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Invitation code without a password. The account does not exist until the
 * link in the mail is opened.
 */
#[Route('/api/v1/auth/code-signup', name: 'auth_code_signup_')]
#[OA\Tag(name: 'Authentication')]
final class CodeSignupController extends AbstractController
{
    public function __construct(
        private readonly PayFirstConfig $payFirst,
        private readonly SignupProtection $protection,
        private readonly CodeSignup $signup,
        private readonly TokenService $tokenService,
        private readonly ImpersonationService $impersonation,
    ) {
    }

    #[Route('', name: 'request', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/auth/code-signup',
        operationId: 'requestCodeSignup',
        summary: 'Mail a one-time link for an invitation code; the account is created when the link is opened',
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email', 'code'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'ada@example.com'),
                new OA\Property(property: 'code', type: 'string', example: 'ABCD2345EFGH'),
                new OA\Property(property: 'challengeToken', type: 'string', nullable: true, description: 'Human-check token when a provider is configured'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'A link is on its way (15 minutes, single use). The same answer whether or not the address already has an account.', content: new OA\JsonContent(
                required: ['success'],
                properties: [new OA\Property(property: 'success', type: 'boolean', example: true)]
            )),
            new OA\Response(response: 400, description: 'The code cannot be used (SIGNUP_CODE_INVALID) or the human check failed (SIGNUP_CHALLENGE_FAILED)'),
            new OA\Response(response: 404, description: 'Pay first is not active on this installation (CODE_SIGNUP_OFF)'),
            new OA\Response(response: 415, description: 'The body must be sent as application/json (CODE_SIGNUP_JSON_REQUIRED)'),
            new OA\Response(response: 429, description: 'Too many requests from this connection or for this address (CODE_SIGNUP_RATE_LIMITED)'),
        ]
    )]
    public function request(Request $request): JsonResponse
    {
        $refused = $this->gate($request, true);
        if (null !== $refused) {
            return $refused;
        }
        $body = json_decode($request->getContent(), true);
        $body = is_array($body) ? $body : [];
        if (!$this->protection->verifyHuman(SignupProtection::tokenFromPayload($body), 'code_signup', $request->getClientIp())) {
            return $this->json(['success' => false, 'error' => 'Verification failed. Please try again.', 'code' => 'SIGNUP_CHALLENGE_FAILED'], Response::HTTP_BAD_REQUEST);
        }

        return $this->queued($body, $request);
    }

    #[Route('/consume', name: 'consume', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/auth/code-signup/consume',
        operationId: 'consumeCodeSignup',
        summary: 'Open the account from an invitation link, or sign in when the address already has one; sets the auth cookies',
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['token'],
            properties: [new OA\Property(property: 'token', type: 'string', example: '3f9a…')]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Signed in. created is true only when this link made the account and spent the code.', content: new OA\JsonContent(
                required: ['success', 'created', 'user'],
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'created', type: 'boolean', example: true),
                    new OA\Property(property: 'user', required: ['id', 'email', 'level', 'isAdmin', 'emailVerified'], properties: [
                        new OA\Property(property: 'id', type: 'integer', example: 42),
                        new OA\Property(property: 'email', type: 'string', example: 'ada@example.com'),
                        new OA\Property(property: 'level', type: 'string', example: 'PRO'),
                        new OA\Property(property: 'isAdmin', type: 'boolean', example: false),
                        new OA\Property(property: 'emailVerified', type: 'boolean', example: true),
                    ], type: 'object'),
                ]
            )),
            new OA\Response(response: 400, description: 'The link is unknown, expired or already used (CODE_SIGNUP_INVALID)'),
            new OA\Response(response: 415, description: 'The body must be sent as application/json (CODE_SIGNUP_JSON_REQUIRED)'),
        ]
    )]
    public function consume(Request $request): JsonResponse
    {
        $refused = $this->gate($request, false);
        if (null !== $refused) {
            return $refused;
        }
        $body = json_decode($request->getContent(), true);
        $outcome = $this->signup->consume(is_array($body) && is_string($body['token'] ?? null) ? $body['token'] : '');
        if (null === $outcome) {
            return $this->json([
                'success' => false,
                'error' => 'This link has expired or was already used. Enter your invitation code again to get a new one.',
                'code' => 'CODE_SIGNUP_INVALID',
            ], Response::HTTP_BAD_REQUEST);
        }

        $user = $outcome->user;
        $response = $this->json([
            'success' => true,
            'created' => $outcome->created,
            'user' => [
                'id' => (int) $user->getId(),
                'email' => $user->getMail(),
                'level' => $user->getUserLevel(),
                'isAdmin' => $user->isAdmin(),
                'emailVerified' => $user->isEmailVerified(),
            ],
        ]);
        $this->tokenService->addAuthCookies($response, $this->tokenService->generateAccessToken($user), $this->tokenService->generateRefreshToken($user, $request->getClientIp()));
        $this->impersonation->attachClearStashCookies($response);

        return $response;
    }

    /**
     * Asking for a link needs pay first. Opening a link that was already
     * sent does not: the invitation was accepted while the switch was on.
     * JSON only, so a cross-site form cannot plant a session.
     */
    private function gate(Request $request, bool $requireActive): ?JsonResponse
    {
        if ($requireActive && !$this->payFirst->isActive()) {
            return $this->json(['success' => false, 'error' => 'Not found.', 'code' => 'CODE_SIGNUP_OFF'], Response::HTTP_NOT_FOUND);
        }
        if ('json' !== $request->getContentTypeFormat()) {
            return $this->json(['success' => false, 'error' => 'Send the request as JSON.', 'code' => 'CODE_SIGNUP_JSON_REQUIRED'], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function queued(array $body, Request $request): JsonResponse
    {
        try {
            $accepted = $this->signup->request(
                is_string($body['email'] ?? null) ? $body['email'] : '',
                is_string($body['code'] ?? null) ? $body['code'] : '',
                $request->getClientIp(),
                $request->getLocale(),
            );
        } catch (CodeSignupRateLimited $e) {
            return $this->json(
                ['success' => false, 'error' => 'Too many attempts. Please wait a few minutes and try again.', 'code' => 'CODE_SIGNUP_RATE_LIMITED'],
                Response::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => (string) $e->retryAfterSeconds],
            );
        }
        if (!$accepted) {
            return $this->json([
                'success' => false,
                'error' => SignupBlockedException::codeInvalid()->getMessage(),
                'code' => SignupBlockedException::CODE_CODE_INVALID,
            ], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['success' => true]);
    }
}
