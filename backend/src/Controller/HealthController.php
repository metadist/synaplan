<?php

namespace App\Controller;

use App\AI\Service\ProviderRegistry;
use App\Service\WhisperService;
use OpenApi\Attributes as OA;
use Predis\Client as PredisClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
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
                new OA\Property(
                    property: 'redis',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'available', type: 'boolean'),
                        new OA\Property(property: 'skipped', type: 'boolean', nullable: true),
                        new OA\Property(property: 'message', type: 'string', nullable: true),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(
        response: 503,
        description: 'Redis required but unreachable (non-test environments)',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'status', type: 'string', example: 'error'),
                new OA\Property(
                    property: 'redis',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'available', type: 'boolean', example: false),
                        new OA\Property(property: 'message', type: 'string'),
                    ]
                ),
            ]
        )
    )]
    public function health(
        ProviderRegistry $registry,
        WhisperService $whisperService,
        KernelInterface $kernel,
        #[Autowire('%env(REDIS_DSN)%')]
        string $redisDsn,
    ): JsonResponse {
        $providers = [];
        foreach ($registry->getAllProviders() as $provider) {
            $providers[$provider->getName()] = $provider->getStatus();
        }

        // Check Whisper.cpp availability
        $whisperAvailable = $whisperService->isAvailable();
        $whisperModels = $whisperAvailable ? $whisperService->getAvailableModels() : [];

        $redis = $this->redisHealth($kernel, $redisDsn);

        $httpStatus = Response::HTTP_OK;
        $status = 'ok';
        $redisSkipped = $redis['skipped'] ?? false;
        if (!$redisSkipped && false === ($redis['available'] ?? false)) {
            $status = 'error';
            $httpStatus = Response::HTTP_SERVICE_UNAVAILABLE;
        }

        return $this->json([
            'status' => $status,
            'timestamp' => time(),
            'providers' => $providers,
            'whisper' => [
                'available' => $whisperAvailable,
                'models' => $whisperModels,
            ],
            'redis' => $redis,
        ], $httpStatus);
    }

    /**
     * @return array{available: bool, skipped?: bool, message?: string}
     */
    private function redisHealth(KernelInterface $kernel, string $redisDsn): array
    {
        if ('test' === $kernel->getEnvironment()) {
            return ['available' => true, 'skipped' => true];
        }

        try {
            $client = new PredisClient($redisDsn);
            $client->ping();

            return ['available' => true];
        } catch (\Throwable $e) {
            $expose = 'prod' !== $kernel->getEnvironment();

            return [
                'available' => false,
                'message' => $expose ? $e->getMessage() : 'Redis unreachable',
            ];
        }
    }
}
