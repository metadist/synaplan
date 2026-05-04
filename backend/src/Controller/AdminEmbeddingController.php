<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Config;
use App\Entity\RevectorizeRun;
use App\Entity\User;
use App\Message\ReVectorizeMessage;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Repository\RevectorizeRunRepository;
use App\Service\Embedding\EmbeddingCostEstimator;
use App\Service\Embedding\EmbeddingMetadataService;
use App\Service\Embedding\EmbeddingModelChangeGuard;
use App\Service\Embedding\Exception\CooldownActiveException;
use App\Service\Embedding\Exception\PremiumRequiredException;
use App\Service\Message\SynapseIndexer;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin endpoints for the SafeModelChange (embedding model switch) flow.
 *
 * Endpoints:
 *   GET  /api/v1/admin/embedding/status                ─ current model, can-change, active run
 *   GET  /api/v1/admin/embedding/cost-estimate?to=…    ─ pre-flight cost estimate per scope
 *   POST /api/v1/admin/embedding/switch                ─ switch + queue ReVectorize job
 *   GET  /api/v1/admin/embedding/runs                  ─ recent re-index history
 *   GET  /api/v1/admin/embedding/runs/{id}             ─ live status of a single run
 *
 * SECURITY: All endpoints require ROLE_ADMIN. Free / Premium gating via
 * EmbeddingModelChangeGuard is still enforced inside the switch handler
 * — admins are always considered "paid", but the same guard runs from
 * the ConfigController's user-scope save flow where it does block.
 */
#[Route('/api/v1/admin/embedding')]
#[IsGranted('ROLE_ADMIN', message: 'Admin access required')]
#[OA\Tag(name: 'Admin Embedding')]
final class AdminEmbeddingController extends AbstractController
{
    public function __construct(
        private readonly EmbeddingMetadataService $embeddingMetadata,
        private readonly EmbeddingCostEstimator $costEstimator,
        private readonly EmbeddingModelChangeGuard $changeGuard,
        private readonly RevectorizeRunRepository $runRepository,
        private readonly ModelRepository $modelRepository,
        private readonly ConfigRepository $configRepository,
        private readonly SynapseIndexer $synapseIndexer,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/status', name: 'admin_embedding_status', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/admin/embedding/status',
        summary: 'SafeModelChange status snapshot (admin only)',
        description: 'Returns the active embedding model, whether the current user may switch, the most recent run for any scope, and any currently active (queued/running) run.',
        security: [['Bearer' => []]],
        tags: ['Admin Embedding']
    )]
    #[OA\Response(response: 200, description: 'Status snapshot', content: new OA\JsonContent(type: 'object'))]
    public function status(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $current = $this->embeddingMetadata->getCurrentModel();
        $guard = $this->changeGuard->getStatus($user);
        $active = $this->runRepository->findActive();
        $latest = $this->runRepository->findLatestForScope(RevectorizeRun::SCOPE_ALL);

        return $this->json([
            'success' => true,
            'currentModel' => [
                'modelId' => $current['model_id'],
                'provider' => $current['provider'],
                'model' => $current['model'],
                'vectorDim' => $current['vector_dim'],
            ],
            'guard' => $guard,
            'activeRun' => null !== $active ? $this->serializeRun($active) : null,
            'latestRun' => null !== $latest ? $this->serializeRun($latest) : null,
        ]);
    }

    #[Route('/cost-estimate', name: 'admin_embedding_cost_estimate', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/admin/embedding/cost-estimate',
        summary: 'Pre-flight cost estimate for an embedding-model switch',
        description: 'Returns per-scope chunk and token counts plus an estimated USD cost using the static pricing table. Severity (info/warning/critical) drives the UI confirmation flow.',
        security: [['Bearer' => []]],
        tags: ['Admin Embedding']
    )]
    #[OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Cost estimate', content: new OA\JsonContent(type: 'object'))]
    #[OA\Response(response: 400, description: 'Missing or invalid model id')]
    public function costEstimate(Request $request): JsonResponse
    {
        $toModelId = (int) $request->query->get('to', '0');
        if ($toModelId <= 0) {
            return $this->json(['error' => 'Invalid query parameter: to'], Response::HTTP_BAD_REQUEST);
        }

        $model = $this->modelRepository->find($toModelId);
        if (!$model) {
            return $this->json(['error' => 'Model not found'], Response::HTTP_NOT_FOUND);
        }

        $estimate = $this->costEstimator->estimateChange($toModelId);

        return $this->json(['success' => true] + $estimate);
    }

    #[Route('/switch', name: 'admin_embedding_switch', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/admin/embedding/switch',
        summary: 'Switch the active VECTORIZE model and queue a re-vectorize run',
        description: 'Atomically updates BCONFIG[DEFAULTMODEL.VECTORIZE], creates a BREVECTORIZE_RUNS row, and dispatches a ReVectorizeMessage to the async_index queue. Returns the run id so the UI can poll for progress.',
        security: [['Bearer' => []]],
        tags: ['Admin Embedding'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['toModelId'],
                properties: [
                    new OA\Property(property: 'toModelId', type: 'integer'),
                    new OA\Property(property: 'scope', type: 'string', enum: ['documents', 'memories', 'synapse', 'all'], example: 'all'),
                    new OA\Property(property: 'confirmCritical', type: 'boolean', description: 'Required when severity is critical (>10M tokens).'),
                ]
            )
        )
    )]
    #[OA\Response(response: 200, description: 'Switch queued', content: new OA\JsonContent(type: 'object'))]
    #[OA\Response(response: 400, description: 'Invalid request')]
    #[OA\Response(response: 403, description: 'Premium subscription required')]
    #[OA\Response(response: 409, description: 'Critical severity requires confirmCritical=true')]
    #[OA\Response(response: 429, description: 'Cooldown active')]
    public function switch(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $body = json_decode((string) $request->getContent(), true) ?: [];
        $toModelId = (int) ($body['toModelId'] ?? 0);
        $scope = (string) ($body['scope'] ?? RevectorizeRun::SCOPE_ALL);
        $confirmCritical = (bool) ($body['confirmCritical'] ?? false);

        if ($toModelId <= 0) {
            return $this->json(['error' => 'Invalid field: toModelId'], Response::HTTP_BAD_REQUEST);
        }

        if (!in_array($scope, [
            RevectorizeRun::SCOPE_DOCUMENTS,
            RevectorizeRun::SCOPE_MEMORIES,
            RevectorizeRun::SCOPE_SYNAPSE,
            RevectorizeRun::SCOPE_ALL,
        ], true)) {
            return $this->json(['error' => 'Invalid field: scope'], Response::HTTP_BAD_REQUEST);
        }

        $model = $this->modelRepository->find($toModelId);
        if (!$model) {
            return $this->json(['error' => 'Model not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->changeGuard->assertCanChange($user, $scope);
        } catch (PremiumRequiredException $e) {
            return $this->json([
                'error' => 'requires_premium',
                'message' => $e->getMessage(),
                'currentLevel' => $e->currentLevel,
            ], Response::HTTP_FORBIDDEN);
        } catch (CooldownActiveException $e) {
            return $this->json([
                'error' => 'cooldown_active',
                'message' => $e->getMessage(),
                'cooldownEndsAt' => $e->cooldownEndsAt,
                'secondsRemaining' => $e->secondsRemaining,
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $estimate = $this->costEstimator->estimateChange($toModelId);
        if ('critical' === $estimate['severity'] && !$confirmCritical) {
            return $this->json([
                'error' => 'confirmation_required',
                'message' => 'This switch is classified as critical. Re-send with confirmCritical=true.',
                'estimate' => $estimate,
            ], Response::HTTP_CONFLICT);
        }

        $fromModelId = $estimate['fromModelId'];

        // Persist the switch BEFORE dispatching the job so the worker
        // sees the new active model when it starts. Doing this in the
        // opposite order would mean fresh writes during the re-index
        // window land in the OLD vector space and immediately become
        // stale.
        $this->setVectorizeDefault($toModelId);
        $this->embeddingMetadata->invalidate();

        $run = (new RevectorizeRun())
            ->setUserId($user->getId() ?? 0)
            ->setScope($scope)
            ->setModelFromId($fromModelId)
            ->setModelToId($toModelId)
            ->setStatus(RevectorizeRun::STATUS_QUEUED)
            ->setChunksTotal($estimate['totals']['chunks'])
            ->setTokensEstimated($estimate['totals']['tokensEstimated'])
            ->setCostEstimatedUsd((string) $estimate['totals']['costEstimatedUsd'])
            ->setSeverity($estimate['severity']);

        $this->runRepository->save($run);

        $this->messageBus->dispatch(new ReVectorizeMessage($run->getId() ?? 0));

        $this->logger->info('Admin: VECTORIZE model switched, re-vectorize queued', [
            'user_id' => $user->getId(),
            'from' => $fromModelId,
            'to' => $toModelId,
            'scope' => $scope,
            'run_id' => $run->getId(),
            'severity' => $estimate['severity'],
        ]);

        return $this->json([
            'success' => true,
            'runId' => $run->getId(),
            'estimate' => $estimate,
        ]);
    }

    #[Route('/runs', name: 'admin_embedding_runs', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/admin/embedding/runs',
        summary: 'Recent re-vectorize run history (admin only)',
        security: [['Bearer' => []]],
        tags: ['Admin Embedding']
    )]
    #[OA\Response(response: 200, description: 'Run history', content: new OA\JsonContent(type: 'object'))]
    public function runs(): JsonResponse
    {
        $runs = $this->runRepository->findRecent(50);

        return $this->json([
            'success' => true,
            'runs' => array_map(fn (RevectorizeRun $r) => $this->serializeRun($r), $runs),
        ]);
    }

    #[Route('/runs/{id}', name: 'admin_embedding_run_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[OA\Get(
        path: '/api/v1/admin/embedding/runs/{id}',
        summary: 'Live status of a single re-vectorize run (admin only)',
        security: [['Bearer' => []]],
        tags: ['Admin Embedding']
    )]
    #[OA\Response(response: 200, description: 'Run detail', content: new OA\JsonContent(type: 'object'))]
    #[OA\Response(response: 404, description: 'Run not found')]
    public function runDetail(int $id): JsonResponse
    {
        $run = $this->runRepository->find($id);
        if (!$run) {
            return $this->json(['error' => 'Run not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'success' => true,
            'run' => $this->serializeRun($run),
        ]);
    }

    #[Route('/synapse/status', name: 'admin_embedding_synapse_status', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/admin/embedding/synapse/status',
        summary: 'Synapse Routing embedding-model status (admin only)',
        description: 'Returns the model currently bound to DEFAULTMODEL.SYNAPSE_VECTORIZE, the catalog of selectable embedding models, and the most recent synapse re-index run for progress display.',
        security: [['Bearer' => []]],
        tags: ['Admin Embedding']
    )]
    #[OA\Response(response: 200, description: 'Synapse status snapshot', content: new OA\JsonContent(type: 'object'))]
    public function synapseStatus(): JsonResponse
    {
        $synapseInfo = $this->synapseIndexer->getEmbeddingModelInfo();
        $available = $this->modelRepository->findBy(['tag' => 'vectorize', 'active' => 1, 'selectable' => 1]);
        $latestSynapseRun = $this->runRepository->findLatestForScope(RevectorizeRun::SCOPE_SYNAPSE);
        $activeRun = $this->runRepository->findActive();

        return $this->json([
            'success' => true,
            'currentModel' => [
                'modelId' => $synapseInfo['model_id'],
                'provider' => $synapseInfo['provider'],
                'model' => $synapseInfo['model'],
                'vectorDim' => $synapseInfo['vector_dim'],
            ],
            'availableModels' => array_map(static fn ($m) => [
                'id' => $m->getId(),
                'name' => $m->getName(),
                'service' => $m->getService(),
                'providerId' => $m->getProviderId(),
            ], $available),
            'latestRun' => null !== $latestSynapseRun ? $this->serializeRun($latestSynapseRun) : null,
            'activeRun' => null !== $activeRun ? $this->serializeRun($activeRun) : null,
        ]);
    }

    #[Route('/synapse/switch', name: 'admin_embedding_synapse_switch', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/admin/embedding/synapse/switch',
        summary: 'Switch the Synapse Routing embedding model and trigger a topic re-index (admin only)',
        description: 'Updates DEFAULTMODEL.SYNAPSE_VECTORIZE, recreates synapse_topics with the new model dimension, and dispatches a ReVectorizeMessage with scope=synapse so all topics get re-embedded with the new model. Cheap by design — topic counts are tiny compared to documents/memories — so no premium gating or cooldown applies.',
        security: [['Bearer' => []]],
        tags: ['Admin Embedding'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['toModelId'],
                properties: [new OA\Property(property: 'toModelId', type: 'integer')]
            )
        )
    )]
    #[OA\Response(response: 200, description: 'Switch queued', content: new OA\JsonContent(type: 'object'))]
    #[OA\Response(response: 400, description: 'Invalid request')]
    #[OA\Response(response: 404, description: 'Model not found')]
    public function synapseSwitch(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $body = json_decode((string) $request->getContent(), true) ?: [];
        $toModelId = (int) ($body['toModelId'] ?? 0);
        if ($toModelId <= 0) {
            return $this->json(['error' => 'Invalid field: toModelId'], Response::HTTP_BAD_REQUEST);
        }

        $model = $this->modelRepository->find($toModelId);
        if (!$model || 'vectorize' !== strtolower($model->getTag())) {
            return $this->json(['error' => 'Model not found or not a vectorize model'], Response::HTTP_NOT_FOUND);
        }

        $currentBefore = $this->synapseIndexer->getEmbeddingModelInfo();
        $fromModelId = $currentBefore['model_id'];

        // Persist the new binding BEFORE dispatching so the worker
        // sees the new active model when it boots; doing it the other
        // way around lets fresh writes during the switch window land
        // in the OLD vector space and get marked stale immediately.
        $this->setSynapseDefault($toModelId);

        $run = (new RevectorizeRun())
            ->setUserId($user->getId() ?? 0)
            ->setScope(RevectorizeRun::SCOPE_SYNAPSE)
            ->setModelFromId($fromModelId)
            ->setModelToId($toModelId)
            ->setStatus(RevectorizeRun::STATUS_QUEUED)
            ->setChunksTotal(0)
            ->setTokensEstimated(0)
            ->setCostEstimatedUsd('0')
            ->setSeverity('info');

        $this->runRepository->save($run);
        $this->messageBus->dispatch(new ReVectorizeMessage($run->getId() ?? 0));

        $this->logger->info('Admin: SYNAPSE_VECTORIZE model switched, re-vectorize queued', [
            'user_id' => $user->getId(),
            'from' => $fromModelId,
            'to' => $toModelId,
            'run_id' => $run->getId(),
        ]);

        return $this->json([
            'success' => true,
            'runId' => $run->getId(),
            'fromModelId' => $fromModelId,
            'toModelId' => $toModelId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRun(RevectorizeRun $run): array
    {
        return [
            'id' => $run->getId(),
            'userId' => $run->getUserId(),
            'scope' => $run->getScope(),
            'fromModelId' => $run->getModelFromId(),
            'toModelId' => $run->getModelToId(),
            'status' => $run->getStatus(),
            'severity' => $run->getSeverity(),
            'chunksTotal' => $run->getChunksTotal(),
            'chunksProcessed' => $run->getChunksProcessed(),
            'chunksFailed' => $run->getChunksFailed(),
            'tokensEstimated' => $run->getTokensEstimated(),
            'tokensProcessed' => $run->getTokensProcessed(),
            'costEstimatedUsd' => $run->getCostEstimatedUsd(),
            'costActualUsd' => $run->getCostActualUsd(),
            'startedAt' => $run->getStartedAt(),
            'finishedAt' => $run->getFinishedAt(),
            'created' => $run->getCreated(),
            'updated' => $run->getUpdated(),
            'error' => $run->getError(),
        ];
    }

    private function setVectorizeDefault(int $modelId): void
    {
        $config = $this->configRepository->findOneBy([
            'ownerId' => 0,
            'group' => 'DEFAULTMODEL',
            'setting' => 'VECTORIZE',
        ]);

        if (!$config) {
            $config = new Config();
            $config->setOwnerId(0);
            $config->setGroup('DEFAULTMODEL');
            $config->setSetting('VECTORIZE');
        }

        $config->setValue((string) $modelId);
        $this->em->persist($config);
        $this->em->flush();
    }

    private function setSynapseDefault(int $modelId): void
    {
        $config = $this->configRepository->findOneBy([
            'ownerId' => 0,
            'group' => 'DEFAULTMODEL',
            'setting' => SynapseIndexer::SYNAPSE_CAPABILITY,
        ]);

        if (!$config) {
            $config = new Config();
            $config->setOwnerId(0);
            $config->setGroup('DEFAULTMODEL');
            $config->setSetting(SynapseIndexer::SYNAPSE_CAPABILITY);
        }

        $config->setValue((string) $modelId);
        $this->em->persist($config);
        $this->em->flush();
    }
}
