<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Auth\EmailChange;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[OA\Tag(name: 'Profile')]
final class EmailChangeController extends AbstractController
{
    public function __construct(
        private readonly EmailChange $emailChange,
    ) {
    }

    #[Route('/api/v1/auth/email-change/confirm', name: 'auth_email_change_confirm', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/auth/email-change/confirm',
        operationId: 'confirmEmailChange',
        summary: 'Store a new sign-in email with the token from the link mailed to that address',
        description: 'Public: the token is the proof. The link works once, for one hour.',
        tags: ['Profile'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['token'],
            properties: [new OA\Property(property: 'token', type: 'string', example: '3f9a…')]
        )),
        responses: [
            new OA\Response(response: 200, description: 'The account now signs in with the new address', content: new OA\JsonContent(
                required: ['success', 'email'],
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'new@example.com'),
                ]
            )),
            new OA\Response(response: 400, description: 'The link is unknown, expired, already used, or the address was taken meanwhile (EMAIL_CHANGE_INVALID)', content: new OA\JsonContent(
                required: ['success', 'error', 'code'],
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: false),
                    new OA\Property(property: 'error', type: 'string', example: 'This link has expired or was already used. Change the email again in your profile.'),
                    new OA\Property(property: 'code', type: 'string', example: 'EMAIL_CHANGE_INVALID'),
                ]
            )),
            new OA\Response(response: 415, description: 'The body must be sent as application/json (EMAIL_CHANGE_JSON_REQUIRED)'),
        ]
    )]
    public function confirm(Request $request): JsonResponse
    {
        if ('json' !== $request->getContentTypeFormat()) {
            return $this->json(['success' => false, 'error' => 'Send the request as JSON.', 'code' => 'EMAIL_CHANGE_JSON_REQUIRED'], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }
        $body = json_decode($request->getContent(), true);
        $email = $this->emailChange->confirm(is_array($body) && is_string($body['token'] ?? null) ? $body['token'] : '');
        if (null === $email) {
            return $this->json([
                'success' => false,
                'error' => 'This link has expired or was already used. Change the email again in your profile.',
                'code' => 'EMAIL_CHANGE_INVALID',
            ], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['success' => true, 'email' => $email]);
    }

    #[Route('/api/v1/profile/email-change', name: 'profile_email_change_cancel', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/profile/email-change',
        operationId: 'cancelEmailChange',
        summary: 'Withdraw a sign-in email change that still waits for its link',
        security: [['Bearer' => []]],
        tags: ['Profile'],
        responses: [
            new OA\Response(response: 200, description: 'Nothing waits any more; the mailed link stops working', content: new OA\JsonContent(
                required: ['success'],
                properties: [new OA\Property(property: 'success', type: 'boolean', example: true)]
            )),
            new OA\Response(response: 401, description: 'Not authenticated'),
        ]
    )]
    public function cancel(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }
        $this->emailChange->cancel($user);

        return $this->json(['success' => true]);
    }
}
