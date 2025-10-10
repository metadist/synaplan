<?php

namespace App\Controller;

use App\AI\Service\AiFacade;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Test Controller für Message Flow Testing mit TestProvider
 */
#[Route('/api/test', name: 'api_test_')]
class TestMessageController extends AbstractController
{
    public function __construct(
        private AiFacade $aiFacade
    ) {}

    /**
     * Test Chat Endpoint (kein User, kein DB - nur AI)
     */
    #[Route('/chat', name: 'chat', methods: ['POST'])]
    public function testChat(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $message = $data['message'] ?? 'Hello!';

        try {
            $response = $this->aiFacade->chat([
                ['role' => 'user', 'content' => $message]
            ], null, [
                'provider' => 'test',
                'stream' => false,
            ]);

            return $this->json([
                'success' => true,
                'input' => $message,
                'response' => $response['content'],
                'provider' => $response['provider'] ?? 'test',
                'model' => $response['model'] ?? 'test-model',
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test Image Generation
     */
    #[Route('/image', name: 'image', methods: ['POST'])]
    public function testImage(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $prompt = $data['prompt'] ?? 'A beautiful landscape';

        return $this->json([
            'success' => true,
            'prompt' => $prompt,
            'imageUrl' => 'https://picsum.photos/1024/768?random=' . rand(1, 100),
            'provider' => 'test',
            'metadata' => [
                'size' => '1024x768',
                'model' => 'test-image-gen-1.0'
            ]
        ]);
    }

    /**
     * Test Full Flow (ohne DB)
     */
    #[Route('/flow', name: 'flow', methods: ['POST'])]
    public function testFlow(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $message = $data['message'] ?? 'Test message';

        return $this->json([
            'success' => true,
            'flow' => [
                'input' => $message,
                'preprocessing' => 'complete',
                'classification' => [
                    'topic' => 'GENERAL',
                    'language' => 'EN',
                    'intent' => 'chat'
                ],
                'routing' => 'chat_handler',
                'response' => "Echo: {$message} (Test Provider)",
                'metadata' => [
                    'provider' => 'test',
                    'model' => 'test-model-1.0',
                    'tokens' => [
                        'prompt' => strlen($message) / 4,
                        'completion' => strlen($message) / 4,
                        'total' => strlen($message) / 2
                    ]
                ]
            ]
        ]);
    }
}

