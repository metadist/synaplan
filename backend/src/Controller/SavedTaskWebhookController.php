<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\SavedTask\SavedTaskWebhookIngress;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/webhooks/saved-tasks', name: 'api_webhooks_saved_tasks_')]
#[OA\Tag(name: 'Webhooks')]
final class SavedTaskWebhookController extends AbstractController
{
    public function __construct(
        private readonly SavedTaskWebhookIngress $ingress,
    ) {
    }

    #[Route('/{token}', name: 'run', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/webhooks/saved-tasks/{token}',
        summary: 'Start a Saved Task from another system',
        description: 'Public. Unknown, disabled, or turned-off tasks return the same 404. Optional HMAC in X-Synaplan-Signature.',
        tags: ['Webhooks']
    )]
    #[OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\RequestBody(
        required: false,
        content: new OA\JsonContent(type: 'object', additionalProperties: true)
    )]
    #[OA\Response(response: 202, description: 'Run accepted')]
    #[OA\Response(response: 401, description: 'Signature missing or wrong')]
    #[OA\Response(response: 404, description: 'Not found')]
    #[OA\Response(response: 429, description: 'Too many requests')]
    public function run(string $token, Request $request): JsonResponse
    {
        $result = $this->ingress->handle(
            $token,
            $request->getContent(),
            $request->headers->get('X-Synaplan-Signature'),
        );

        return $this->json($result['body'], $result['status']);
    }
}
