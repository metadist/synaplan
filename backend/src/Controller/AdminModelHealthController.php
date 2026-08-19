<?php

declare(strict_types=1);

namespace App\Controller;

use App\AI\Health\ModelHealthConfig;
use App\AI\Health\ModelHealthEvaluator;
use App\AI\Health\ModelHealthOverview;
use App\AI\Health\ModelHealthRecorder;
use App\Repository\ModelHealthRepository;
use App\Repository\ModelRepository;
use App\Service\ModelConfigService;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin endpoints behind the model status page.
 *
 * Endpoints:
 *   GET  /api/v1/admin/model-health                       ─ current status of every catalogued model
 *   POST /api/v1/admin/model-health/refresh               ─ re-probe now, optionally one provider
 *   POST /api/v1/admin/model-health/models/{id}/exempt    ─ pause automatic disabling for one model
 *
 * SECURITY: all endpoints require ROLE_ADMIN. The read endpoint never talks to
 * a provider; only the explicit refresh does.
 */
#[Route('/api/v1/admin/model-health')]
#[IsGranted('ROLE_ADMIN', message: 'Admin access required')]
#[OA\Tag(name: 'Admin Model Health')]
final class AdminModelHealthController extends AbstractController
{
    public function __construct(
        private readonly ModelHealthOverview $overview,
        private readonly ModelHealthEvaluator $evaluator,
        private readonly ModelHealthRecorder $recorder,
        private readonly ModelHealthRepository $healthRepository,
        private readonly ModelHealthConfig $config,
        private readonly ModelRepository $modelRepository,
        private readonly ModelConfigService $modelConfigService,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'admin_model_health_status', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/admin/model-health',
        summary: 'Availability of every catalogued AI model (admin only)',
        description: 'Returns the last stored verdict per model together with the rolling success/failure counters from live traffic. Reads stored state only and never calls a provider.',
        security: [['Bearer' => []]],
        tags: ['Admin Model Health']
    )]
    #[OA\Response(
        response: 200,
        description: 'Model availability snapshot',
        content: new OA\JsonContent(
            required: ['success', 'summary', 'providers'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'summary',
                    required: ['total', 'online', 'degraded', 'offline', 'unconfigured', 'unknown', 'needsAttention', 'lastCheck', 'autoDisableEnabled', 'monitoringEnabled'],
                    properties: [
                        new OA\Property(property: 'total', type: 'integer', example: 84),
                        new OA\Property(property: 'online', type: 'integer', example: 71),
                        new OA\Property(property: 'degraded', type: 'integer', example: 2),
                        new OA\Property(property: 'offline', type: 'integer', example: 3),
                        new OA\Property(property: 'unconfigured', type: 'integer', example: 8),
                        new OA\Property(property: 'unknown', type: 'integer', example: 0),
                        new OA\Property(property: 'needsAttention', type: 'integer', example: 5),
                        new OA\Property(property: 'lastCheck', type: 'integer', description: 'Unix timestamp of the most recent check, 0 when none ran yet', example: 1755600000),
                        new OA\Property(property: 'autoDisableEnabled', type: 'boolean', example: false),
                        new OA\Property(property: 'monitoringEnabled', type: 'boolean', example: true),
                    ],
                    type: 'object'
                ),
                new OA\Property(
                    property: 'providers',
                    type: 'array',
                    items: new OA\Items(
                        required: ['name', 'needsAttention', 'models'],
                        properties: [
                            new OA\Property(property: 'name', type: 'string', example: 'groq'),
                            new OA\Property(property: 'needsAttention', type: 'integer', example: 2),
                            new OA\Property(
                                property: 'models',
                                type: 'array',
                                items: new OA\Items(
                                    required: ['id', 'name', 'providerId', 'capability', 'state', 'reason', 'source', 'lastCheck', 'lastSuccess', 'lastFailure', 'successes', 'failures', 'errorRatePercent', 'active', 'selectable', 'autoDisabled', 'exemptUntil'],
                                    properties: [
                                        new OA\Property(property: 'id', type: 'integer', example: 42),
                                        new OA\Property(property: 'name', type: 'string', example: 'Llama 3.3 70B'),
                                        new OA\Property(property: 'providerId', type: 'string', example: 'llama-3.3-70b-versatile'),
                                        new OA\Property(property: 'capability', type: 'string', example: 'chat'),
                                        new OA\Property(property: 'state', type: 'string', enum: ['online', 'degraded', 'offline', 'unconfigured', 'unknown'], example: 'online'),
                                        new OA\Property(property: 'reason', type: 'string', description: 'Human-readable explanation, empty when healthy', example: ''),
                                        new OA\Property(property: 'source', type: 'string', enum: ['probe', 'traffic'], example: 'probe'),
                                        new OA\Property(property: 'lastCheck', type: 'integer', example: 1755600000),
                                        new OA\Property(property: 'lastSuccess', type: 'integer', example: 1755600000),
                                        new OA\Property(property: 'lastFailure', type: 'integer', example: 0),
                                        new OA\Property(property: 'successes', type: 'integer', description: 'Successful calls in the rolling window', example: 12),
                                        new OA\Property(property: 'failures', type: 'integer', description: 'Failed calls in the rolling window', example: 0),
                                        new OA\Property(property: 'errorRatePercent', type: 'integer', example: 0),
                                        new OA\Property(property: 'active', type: 'boolean', example: true),
                                        new OA\Property(property: 'selectable', type: 'boolean', example: true),
                                        new OA\Property(property: 'autoDisabled', type: 'boolean', description: 'Switched off by the monitor rather than by an operator', example: false),
                                        new OA\Property(property: 'exemptUntil', type: 'integer', description: 'Unix timestamp until which automatic disabling is paused, 0 when not paused', example: 0),
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
    #[OA\Response(response: 403, description: 'Admin access required')]
    public function status(): JsonResponse
    {
        return $this->json(['success' => true] + $this->overview->build());
    }

    #[Route('/refresh', name: 'admin_model_health_refresh', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/admin/model-health/refresh',
        summary: 'Re-check model availability now (admin only)',
        description: 'Asks every provider for its published model list and re-evaluates the traffic counters. Uses free catalog endpoints only, so it never runs inference and never costs anything. Pass a provider to check just one.',
        security: [['Bearer' => []]],
        tags: ['Admin Model Health'],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'provider', type: 'string', description: 'Restrict the check to this provider', example: 'groq', nullable: true),
                ],
                type: 'object'
            )
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'What the check did. Fetch GET /api/v1/admin/model-health afterwards for the new snapshot.',
        content: new OA\JsonContent(
            required: ['success', 'checked', 'alertsRaised', 'alertsResolved'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'checked', type: 'integer', description: 'Number of models re-evaluated', example: 84),
                new OA\Property(property: 'alertsRaised', type: 'integer', example: 1),
                new OA\Property(property: 'alertsResolved', type: 'integer', example: 0),
            ]
        )
    )]
    #[OA\Response(response: 403, description: 'Admin access required')]
    #[OA\Response(response: 503, description: 'The check could not be completed')]
    public function refresh(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $request->getContent(), true) ?: [];
        $provider = isset($payload['provider']) && is_string($payload['provider']) && '' !== trim($payload['provider'])
            ? [trim($payload['provider'])]
            : [];

        try {
            $run = $this->evaluator->run(dryRun: false, onlyServices: $provider);
        } catch (\Throwable $e) {
            $this->logger->error('Manual model health check failed', ['error' => $e->getMessage()]);

            return $this->json([
                'error' => 'The availability check could not be completed: '.$e->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $this->modelConfigService->invalidateModelHealth();

        return $this->json([
            'success' => true,
            'checked' => count($run->verdicts),
            'alertsRaised' => count($run->alertsRaised),
            'alertsResolved' => count($run->alertsResolved),
        ]);
    }

    // Negative ids are the catalog's "let the provider registry decide"
    // placeholders and are real, routable BMODELS rows, so the pattern has to
    // allow the sign — a \d+ requirement would 404 on nine of them.
    #[Route('/models/{id}/exempt', name: 'admin_model_health_exempt', requirements: ['id' => '-?\d+'], methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/admin/model-health/models/{id}/exempt',
        summary: 'Pause or resume automatic disabling for one model (admin only)',
        description: 'While a model is exempt, the monitor keeps reporting its state but never switches it off. Use this for a model an operator knows is fine even though the provider does not publish it.',
        security: [['Bearer' => []]],
        tags: ['Admin Model Health'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['exempt'],
                properties: [
                    new OA\Property(property: 'exempt', type: 'boolean', description: 'true pauses automatic disabling, false resumes it', example: true),
                ],
                type: 'object'
            )
        )
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 200,
        description: 'New exemption state',
        content: new OA\JsonContent(
            required: ['success', 'modelId', 'exemptUntil'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'modelId', type: 'integer', example: 42),
                new OA\Property(property: 'exemptUntil', type: 'integer', description: 'Unix timestamp until which automatic disabling is paused, 0 when not paused', example: 1756204800),
            ]
        )
    )]
    #[OA\Response(response: 400, description: 'Missing or invalid "exempt" flag')]
    #[OA\Response(response: 403, description: 'Admin access required')]
    #[OA\Response(response: 404, description: 'Model not found')]
    public function exempt(int $id, Request $request): JsonResponse
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $request->getContent(), true) ?: [];
        if (!isset($payload['exempt']) || !is_bool($payload['exempt'])) {
            return $this->json(['error' => 'Field "exempt" must be a boolean'], Response::HTTP_BAD_REQUEST);
        }

        if (null === $this->modelRepository->find($id)) {
            return $this->json(['error' => 'Model not found'], Response::HTTP_NOT_FOUND);
        }

        $health = $this->healthRepository->findOrCreate($id);
        $until = $payload['exempt'] ? time() + $this->config->suppressionSeconds() : 0;
        $health->setSuppressUntil($until)->setUpdated(time());

        $this->entityManager->flush();
        $this->modelConfigService->invalidateModelHealth();

        $this->logger->info('Automatic disabling exemption changed', [
            'model_id' => $id,
            'exempt_until' => $until,
        ]);

        return $this->json([
            'success' => true,
            'modelId' => $id,
            'exemptUntil' => $until,
        ]);
    }

    #[Route('/models/{id}/reset', name: 'admin_model_health_reset', requirements: ['id' => '-?\d+'], methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/admin/model-health/models/{id}/reset',
        summary: 'Clear the rolling error counters for one model (admin only)',
        description: 'Forgets the recorded successes and failures of the current window. Use after fixing the cause so the model is judged on fresh traffic instead of waiting out the window.',
        security: [['Bearer' => []]],
        tags: ['Admin Model Health']
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 200,
        description: 'Counters cleared',
        content: new OA\JsonContent(
            required: ['success', 'modelId'],
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'modelId', type: 'integer', example: 42),
            ]
        )
    )]
    #[OA\Response(response: 403, description: 'Admin access required')]
    #[OA\Response(response: 404, description: 'Model not found')]
    public function reset(int $id): JsonResponse
    {
        if (null === $this->modelRepository->find($id)) {
            return $this->json(['error' => 'Model not found'], Response::HTTP_NOT_FOUND);
        }

        $this->recorder->reset($id);

        return $this->json(['success' => true, 'modelId' => $id]);
    }
}
