<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Model\DefaultModelChangeOffer;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Tells the signed-in person when a default model has been unreachable often
 * enough to ask about a replacement. Reading this never changes a default.
 */
#[OA\Tag(name: 'Configuration')]
final class DefaultModelOfferController extends AbstractController
{
    public function __construct(
        private readonly DefaultModelChangeOffer $offers,
    ) {
    }

    #[Route('/api/v1/config/models/default-offers', name: 'api_config_models_default_offers', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/config/models/default-offers',
        summary: 'Defaults that were unreachable more than twice in the last hour',
        description: 'Lists the signed-in person\'s defaults whose model was unreachable more than twice in the last 60 minutes, plus other models they could switch that one default to. Locked defaults and the embedding model are omitted. Nothing is saved until the person posts a new default themselves.',
        security: [['Bearer' => []]],
        tags: ['Configuration']
    )]
    #[OA\Response(
        response: 200,
        description: 'Offers to change a default. Empty when nothing has failed often enough.',
        content: new OA\JsonContent(
            required: ['success', 'offers'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'offers',
                    type: 'array',
                    items: new OA\Items(
                        required: ['capability', 'modelId', 'modelName', 'unavailableCount', 'alternatives'],
                        properties: [
                            new OA\Property(property: 'capability', type: 'string', example: 'CHAT'),
                            new OA\Property(property: 'modelId', type: 'integer', example: 55),
                            new OA\Property(property: 'modelName', type: 'string', example: 'gpt 5.5'),
                            new OA\Property(property: 'unavailableCount', type: 'integer', description: 'Unreachable attempts in the last 60 minutes', example: 3),
                            new OA\Property(
                                property: 'alternatives',
                                type: 'array',
                                items: new OA\Items(
                                    required: ['id', 'name', 'service'],
                                    properties: [
                                        new OA\Property(property: 'id', type: 'integer', example: 88),
                                        new OA\Property(property: 'name', type: 'string', example: 'gpt 6 sol'),
                                        new OA\Property(property: 'service', type: 'string', example: 'OpenAI'),
                                    ],
                                    type: 'object'
                                )
                            ),
                        ],
                        type: 'object'
                    )
                ),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json(['success' => true] + $this->offers->forUser((int) $user->getId()));
    }
}
