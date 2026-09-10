<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\DesktopAssistantPublicView;
use App\Entity\User;
use App\Service\Agent\AgentConfig;
use App\Service\Agent\DesktopAssistantLister;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Read-only Assistant list for paired desktop keys (`desktop:messages`).
 *
 * `/api/v1/agents*` stays `agents:*` (C14). No POST/PATCH/DELETE here.
 */
#[OA\Tag(name: 'OpenAI Compatible')]
final class DesktopAssistantController extends AbstractController
{
    public const DISABLED_CODE = 'assistants_disabled';

    public function __construct(
        private readonly AgentConfig $agentConfig,
        private readonly DesktopAssistantLister $lister,
    ) {
    }

    #[Route('/v1/assistants', name: 'desktop_assistants_list', methods: ['GET'])]
    #[OA\Get(
        path: '/v1/assistants',
        summary: 'List Assistants this key may run',
        description: 'Reader `publicView` rows including `models.chat` / `vision` / `vectorize` catalog keys. Returns 404 with code `assistants_disabled` when AGENTS.ENABLED is off. Allowed for paired desktop keys. Creating or editing Assistants stays on `/api/v1/agents`.',
        security: [['Bearer' => []], ['ApiKey' => []]],
        tags: ['OpenAI Compatible']
    )]
    #[OA\Response(
        response: 200,
        description: 'Assistant list (`object=list`)',
        content: new OA\JsonContent(
            required: ['object', 'data'],
            properties: [
                new OA\Property(property: 'object', type: 'string', example: 'list'),
                new OA\Property(
                    property: 'data',
                    type: 'array',
                    items: new OA\Items(ref: new Model(type: DesktopAssistantPublicView::class)),
                ),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Authentication required')]
    #[OA\Response(
        response: 404,
        description: 'Assistants are turned off (`assistants_disabled`)',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'error',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'type', type: 'string', example: 'not_found_error'),
                        new OA\Property(property: 'code', type: 'string', example: 'assistants_disabled'),
                    ]
                ),
            ]
        )
    )]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);

        return new JsonResponse([
            'object' => 'list',
            'data' => $this->lister->listRunnable($user),
        ]);
    }

    #[Route('/v1/assistants/{id}', name: 'desktop_assistants_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[OA\Get(
        path: '/v1/assistants/{id}',
        summary: 'Get one Assistant this key may run',
        description: 'Same `publicView` as the list. 404 when the feature is off, or the Assistant is missing or not usable by this user.',
        security: [['Bearer' => []], ['ApiKey' => []]],
        tags: ['OpenAI Compatible']
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 200,
        description: 'Assistant publicView',
        content: new OA\JsonContent(ref: new Model(type: DesktopAssistantPublicView::class))
    )]
    #[OA\Response(response: 401, description: 'Authentication required')]
    #[OA\Response(response: 404, description: 'Not found or assistants_disabled')]
    public function get(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $denied = $this->guard($user);
        if (null !== $denied) {
            return $denied;
        }
        \assert($user instanceof User);

        $row = $this->lister->oneRunnable($user, $id);
        if (null === $row) {
            return new JsonResponse(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($row);
    }

    private function guard(?User $user): ?JsonResponse
    {
        if (!$user) {
            return new JsonResponse(
                [
                    'error' => [
                        'message' => 'Authentication required',
                        'type' => 'invalid_request_error',
                        'code' => 'invalid_api_key',
                    ],
                ],
                Response::HTTP_UNAUTHORIZED,
            );
        }
        if (!$this->agentConfig->isEnabled((int) $user->getId())) {
            return new JsonResponse(
                [
                    'error' => [
                        'message' => 'Assistants are turned off on this workspace.',
                        'type' => 'not_found_error',
                        'code' => self::DISABLED_CODE,
                    ],
                ],
                Response::HTTP_NOT_FOUND,
            );
        }

        return null;
    }
}
