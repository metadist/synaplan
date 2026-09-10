<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\DesktopCatalogEntry;
use App\Entity\User;
use App\Service\Model\CapabilityCatalog;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Capability-grouped model catalog for paired desktop keys (`desktop:messages`).
 *
 * Distinct from {@see ConfigController::getModels()} which needs `messages:*`.
 */
#[OA\Tag(name: 'OpenAI Compatible')]
final class DesktopModelCatalogController extends AbstractController
{
    public function __construct(
        private readonly CapabilityCatalog $catalog,
    ) {
    }

    #[Route('/v1/models/catalog', name: 'desktop_models_catalog', methods: ['GET'])]
    #[OA\Get(
        path: '/v1/models/catalog',
        summary: 'List selectable models grouped by capability',
        description: 'Returns active, user-selectable models for the eight project-companion capabilities. `id` is the catalog key `service:providerId:tag`. Unavailable models stay in the list with `available=false`. Allowed for paired desktop keys (`desktop:messages`). Not gated by AGENTS.ENABLED. `GET /api/v1/config/models` remains `messages:*` only.',
        security: [['Bearer' => []], ['ApiKey' => []]],
        tags: ['OpenAI Compatible']
    )]
    #[OA\Response(
        response: 200,
        description: 'Capability catalog',
        content: new OA\JsonContent(
            required: ['object', 'capabilities'],
            properties: [
                new OA\Property(property: 'object', type: 'string', example: 'catalog'),
                new OA\Property(
                    property: 'capabilities',
                    type: 'object',
                    required: ['CHAT', 'SOUND2TEXT', 'TEXT2SOUND', 'PIC2TEXT', 'TEXT2PIC', 'TEXT2VID', 'VECTORIZE', 'ANALYZE'],
                    properties: [
                        new OA\Property(property: 'CHAT', type: 'array', items: new OA\Items(ref: new Model(type: DesktopCatalogEntry::class))),
                        new OA\Property(property: 'SOUND2TEXT', type: 'array', items: new OA\Items(ref: new Model(type: DesktopCatalogEntry::class))),
                        new OA\Property(property: 'TEXT2SOUND', type: 'array', items: new OA\Items(ref: new Model(type: DesktopCatalogEntry::class))),
                        new OA\Property(property: 'PIC2TEXT', type: 'array', items: new OA\Items(ref: new Model(type: DesktopCatalogEntry::class))),
                        new OA\Property(property: 'TEXT2PIC', type: 'array', items: new OA\Items(ref: new Model(type: DesktopCatalogEntry::class))),
                        new OA\Property(property: 'TEXT2VID', type: 'array', items: new OA\Items(ref: new Model(type: DesktopCatalogEntry::class))),
                        new OA\Property(property: 'VECTORIZE', type: 'array', items: new OA\Items(ref: new Model(type: DesktopCatalogEntry::class))),
                        new OA\Property(property: 'ANALYZE', type: 'array', items: new OA\Items(ref: new Model(type: DesktopCatalogEntry::class))),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Authentication required')]
    public function catalog(#[CurrentUser] ?User $user): JsonResponse
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

        return new JsonResponse($this->catalog->forUser($user));
    }
}
