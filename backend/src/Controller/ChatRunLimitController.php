<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\ChatRunLimit;
use App\Entity\User;
use App\Service\Chat\Loop\ChatLoopConfig;
use App\Service\Chat\Loop\ChatRunCostLimit;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The person's own cost limit for one long chat answer: a share of their
 * monthly budget (03 §1.3).
 */
#[Route('/api/v1/config/chat-run-limit')]
#[OA\Tag(name: 'Chat')]
final class ChatRunLimitController extends AbstractController
{
    public function __construct(
        private readonly ChatRunCostLimit $limit,
        private readonly ChatLoopConfig $loop,
    ) {
    }

    #[Route('', name: 'chat_run_limit_get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/config/chat-run-limit',
        summary: 'The share of the monthly budget one long chat answer may spend',
        security: [['Bearer' => []]],
        tags: ['Chat']
    )]
    #[OA\Response(
        response: 200,
        description: 'The chosen share, the choices and what they mean in money',
        content: new OA\JsonContent(ref: new Model(type: ChatRunLimit::class))
    )]
    #[OA\Response(response: 401, description: 'Authentication required')]
    public function show(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json(['enabled' => $this->loop->isEnabled(), ...$this->limit->summary($user)]);
    }

    #[Route('', name: 'chat_run_limit_save', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/config/chat-run-limit',
        summary: 'Choose the share of the monthly budget one long chat answer may spend',
        security: [['Bearer' => []]],
        tags: ['Chat']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['percent'],
            properties: [
                new OA\Property(property: 'percent', type: 'integer', nullable: true, example: 25, description: 'One of the offered shares; null goes back to the default'),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'The saved share',
        content: new OA\JsonContent(ref: new Model(type: ChatRunLimit::class))
    )]
    #[OA\Response(response: 400, description: 'The body is not a JSON object with a percent')]
    #[OA\Response(response: 401, description: 'Authentication required')]
    #[OA\Response(response: 422, description: 'The share is not one of the offered ones')]
    public function save(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }
        try {
            $data = json_decode($request->getContent(), true, 4, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $data = null;
        }
        if (!\is_array($data) || !\array_key_exists('percent', $data) || (null !== $data['percent'] && !\is_int($data['percent']))) {
            return $this->json(['error' => 'percent must be a whole number or null'], Response::HTTP_BAD_REQUEST);
        }
        try {
            $this->limit->save($user, $data['percent']);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->show($user);
    }
}
