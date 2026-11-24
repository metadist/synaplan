<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Auth\PasswordResetRateLimited;
use App\Service\Auth\PasswordResetRequest;
use App\Service\Auth\SignupProtection;
use App\Service\Http\ClientAddress;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/auth', name: 'api_auth_')]
#[OA\Tag(name: 'Authentication')]
final class PasswordResetController extends AbstractController
{
    public const HUMAN_CHECK_ACTION = 'password_reset';

    public function __construct(
        private readonly PasswordResetRequest $passwordReset,
        private readonly SignupProtection $humanCheck,
    ) {
    }

    #[Route('/forgot-password', name: 'forgot_password', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/auth/forgot-password',
        operationId: 'requestPasswordReset',
        summary: 'Email a password reset link; answers the same whether or not the address has an account',
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'ada@example.com'),
                new OA\Property(property: 'challengeToken', type: 'string', nullable: true, description: 'Human-check token (Cloudflare Turnstile or reCAPTCHA v3, action `password_reset`). Required when the instance has a provider configured.'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'If an account exists for the address, a reset link (valid one hour) is on its way', content: new OA\JsonContent(
                required: ['success', 'message'],
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'message', type: 'string', example: 'If email exists, reset instructions sent'),
                ]
            )),
            new OA\Response(response: 400, description: 'No address given, or the human check failed (SIGNUP_CHALLENGE_FAILED)', content: new OA\JsonContent(
                required: ['error'],
                properties: [
                    new OA\Property(property: 'error', type: 'string', example: 'Verification failed. Please try again.'),
                    new OA\Property(property: 'code', type: 'string', example: 'SIGNUP_CHALLENGE_FAILED'),
                ]
            )),
            new OA\Response(response: 429, description: 'Too many requests from this connection or for this address (PASSWORD_RESET_RATE_LIMITED); see Retry-After', content: new OA\JsonContent(
                required: ['success', 'error', 'code'],
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: false),
                    new OA\Property(property: 'error', type: 'string', example: 'Too many attempts. Please wait a few minutes and try again.'),
                    new OA\Property(property: 'code', type: 'string', example: 'PASSWORD_RESET_RATE_LIMITED'),
                ]
            )),
        ]
    )]
    public function forgotPassword(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        $payload = is_array($body) ? $body : [];
        $email = is_string($payload['email'] ?? null) ? trim($payload['email']) : '';
        if ('' === $email) {
            return $this->json(['error' => 'Email required'], Response::HTTP_BAD_REQUEST);
        }

        $clientIp = ClientAddress::resolve($request);
        if (!$this->humanCheck->verifyHuman(SignupProtection::tokenFromPayload($payload), self::HUMAN_CHECK_ACTION, $clientIp)) {
            return $this->json(['error' => 'Verification failed. Please try again.', 'code' => 'SIGNUP_CHALLENGE_FAILED'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->passwordReset->request($email, $clientIp);
        } catch (PasswordResetRateLimited $e) {
            return $this->json(
                ['success' => false, 'error' => 'Too many attempts. Please wait a few minutes and try again.', 'code' => 'PASSWORD_RESET_RATE_LIMITED'],
                Response::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => (string) $e->retryAfterSeconds],
            );
        }

        return $this->json([
            'success' => true,
            'message' => 'If email exists, reset instructions sent',
        ]);
    }
}
