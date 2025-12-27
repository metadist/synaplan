<?php

namespace Plugin\hello_world\backend\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class HelloController extends AbstractController
{
    #[Route('/api/v1/user/{userId}/plugins/hello_world/hello', name: 'plugin_hello_world_hello', methods: ['GET'])]
    public function hello(int $userId): JsonResponse
    {
        return new JsonResponse([
            'message' => 'Hello from plugin!',
            'userId' => $userId,
            'plugin' => 'hello_world'
        ]);
    }
}

