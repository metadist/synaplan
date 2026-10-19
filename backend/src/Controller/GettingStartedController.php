<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Chat\GettingStartedStatusService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * One answer for the empty-chat checklist. The browser used to ask every
 * integration itself, and those requests queued in front of the chat.
 */
#[Route('/api/v1/getting-started', name: 'api_getting_started_')]
#[OA\Tag(name: 'Chat')]
final class GettingStartedController extends AbstractController
{
    public function __construct(
        private readonly GettingStartedStatusService $status,
    ) {
    }

    #[Route('', name: 'show', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/getting-started',
        summary: 'Getting-started checklist for the signed-in user',
        description: 'One request for the empty-chat checklist. A missing item means that step is hidden (the check failed, assistants are switched off, or the person is not an admin). False means the step is still to do.',
        tags: ['Chat'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Checklist steps',
                content: new OA\JsonContent(
                    required: ['success', 'items'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'items',
                            properties: [
                                new OA\Property(property: 'file', type: 'boolean', example: false, description: 'The library has at least one file. Absent when the check failed.'),
                                new OA\Property(property: 'assistant', type: 'boolean', example: false, description: 'The user has an assistant. Absent when assistants are switched off or the check failed.'),
                                new OA\Property(property: 'app', type: 'boolean', example: false, description: 'At least one app the directory shows is connected. Absent when the check failed.'),
                                new OA\Property(property: 'provider', type: 'boolean', example: true, description: 'A chat provider of this install is ready; the Synaplan Demo does not count. Present for admins only; absent when the check failed.'),
                            ],
                            type: 'object',
                        ),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
        ],
    )]
    public function show(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json([
            'success' => true,
            'items' => $this->status->items($user),
        ]);
    }
}
