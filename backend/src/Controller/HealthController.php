<?php

namespace App\Controller;

use App\AI\Service\ProviderRegistry;
use App\Service\Infrastructure\RedisService;
use App\Service\WhisperService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
        description: 'Returns AI provider availability, Whisper.cpp readiness, and Redis reachability. Returns 503 when Redis is unreachable in dev/prod (PHPUnit always reports `skipped: true`).',
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
                        new OA\Property(property: 'skipped', type: 'boolean', nullable: true, description: 'true when running under APP_ENV=test (Redis ping is intentionally bypassed).'),
                        new OA\Property(property: 'message', type: 'string', nullable: true),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(
        response: 503,
        description: 'Redis required but unreachable. Load balancers should route traffic away from this node.',
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
        RedisService $redisService,
        KernelInterface $kernel,
    ): JsonResponse {
        $providers = [];
        foreach ($registry->getAllProviders() as $provider) {
            $providers[$provider->getName()] = $provider->getStatus();
        }

        $whisperAvailable = $whisperService->isAvailable();
        $whisperModels = $whisperAvailable ? $whisperService->getAvailableModels() : [];

        $redis = $this->redisHealth($redisService, $kernel);

        // 503 only when Redis is genuinely down (not when intentionally
        // skipped under APP_ENV=test). Returning 503 lets load balancers
        // (Cloudflare, AWS ALB, etc.) drop the affected node from rotation
        // — sessions, messenger, cache, and locks all rely on Redis, so
        // serving requests from a node without Redis would silently corrupt
        // state across the cluster.
        $redisSkipped = $redis['skipped'] ?? false;
        $status = 'ok';
        $httpStatus = Response::HTTP_OK;
        if (!$redisSkipped && false === $redis['available']) {
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
    private function redisHealth(RedisService $redisService, KernelInterface $kernel): array
    {
        // PHPUnit must not require a running Redis (`HealthControllerTest`
        // boots the full kernel). Skipping is announced explicitly so
        // monitoring dashboards can distinguish "test bypass" from
        // "production outage".
        if ('test' === $kernel->getEnvironment()) {
            return ['available' => true, 'skipped' => true];
        }

        if ($redisService->ping()) {
            return ['available' => true];
        }

        // Don't leak internal connection errors (DSN, cluster topology, …)
        // to anonymous health probes in production. Devs still get the
        // exact reason locally.
        $isProd = 'prod' === $kernel->getEnvironment();
        $error = $redisService->getLastConnectionError();

        return [
            'available' => false,
            'message' => $isProd || null === $error
                ? 'Redis unreachable'
                : $error->getMessage(),
        ];
    }
}
