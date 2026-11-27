<?php

declare(strict_types=1);

namespace Plugin\HelloWorld\Controller;

use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HelloController extends AbstractController
{
    #[Route('/api/v1/user/{userId}/plugins/hello_world/hello', name: 'plugin_hello_world_hello', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/hello_world/hello',
        summary: 'Hello World plugin sample',
        security: [['ApiKey' => []]],
        tags: ['Plugins'],
    )]
    #[OA\Response(
        response: 200,
        description: 'A greeting from the sample plugin',
        content: new OA\JsonContent(
            required: ['message', 'userId', 'plugin'],
            properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Hello from plugin!'),
                new OA\Property(property: 'userId', type: 'integer', example: 1),
                new OA\Property(property: 'plugin', type: 'string', example: 'hello_world'),
            ]
        )
    )]
    public function hello(int $userId): JsonResponse
    {
        return new JsonResponse([
            'message' => 'Hello from plugin!',
            'userId' => $userId,
            'plugin' => 'hello_world',
        ]);
    }
}
