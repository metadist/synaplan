<?php

namespace App\Controller;

use App\AI\Service\ProviderRegistry;
use App\Service\WhisperService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Health')]
class HealthController extends AbstractController
{
    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    #[OA\Get(
        path: '/api/health',
        summary: 'Health check endpoint',
        description: 'Returns system health status, AI providers, and Whisper.cpp availability',
        tags: ['Health']
    )]
    #[OA\Response(
        response: 200,
        description: 'System health status',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'status', type: 'string', example: 'ok'),
                new OA\Property(property: 'timestamp', type: 'integer', example: 1730386800),
                new OA\Property(
                    property: 'providers',
                    type: 'object',
                    additionalProperties: new OA\AdditionalProperties(
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'available', type: 'boolean'),
                            new OA\Property(property: 'message', type: 'string', nullable: true),
                        ]
                    ),
                    example: [
                        'openai' => ['available' => true, 'message' => null],
                        'ollama' => ['available' => true, 'message' => null],
                    ]
                ),
                new OA\Property(
                    property: 'whisper',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'available', type: 'boolean'),
                        new OA\Property(property: 'models', type: 'array', items: new OA\Items(type: 'string')),
                    ]
                ),
            ]
        )
    )]
    public function health(ProviderRegistry $registry, WhisperService $whisperService): JsonResponse
    {
        $providers = [];
        foreach ($registry->getAllProviders() as $provider) {
            $providers[$provider->getName()] = $provider->getStatus();
        }

        // Check Whisper.cpp availability
        $whisperAvailable = $whisperService->isAvailable();
        $whisperModels = $whisperAvailable ? $whisperService->getAvailableModels() : [];

        return $this->json([
            'status' => 'ok',
            'timestamp' => time(),
            'providers' => $providers,
            'whisper' => [
                'available' => $whisperAvailable,
                'models' => $whisperModels,
            ],
        ]);
    }
}
