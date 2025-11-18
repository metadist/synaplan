<?php

namespace App\Controller;

use App\AI\Service\AiFacade;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class TestController extends AbstractController
{
    #[Route('/api/test/chat', name: 'api_test_chat', methods: ['GET'])]
    public function testChat(AiFacade $aiFacade): JsonResponse
    {
        $response = $aiFacade->chat('Hello, how are you?');
        
        return $this->json([
            'prompt' => 'Hello, how are you?',
            'response' => $response,
            'provider' => 'test',
        ]);
    }
    
    #[Route('/api/test/embed', name: 'api_test_embed', methods: ['GET'])]
    public function testEmbed(AiFacade $aiFacade): JsonResponse
    {
        $embedding = $aiFacade->embed('This is a test text');
        
        return $this->json([
            'text' => 'This is a test text',
            'dimensions' => count($embedding),
            'sample' => array_slice($embedding, 0, 5),
        ]);
    }
}

