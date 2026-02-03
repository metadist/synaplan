<?php

declare(strict_types=1);

namespace Plugin\Brogent\Controller;

use App\Entity\User;
use App\Service\PluginDataService;
use OpenApi\Attributes as OA;
use Plugin\Brogent\Service\BrogentService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * BroGent Plugin API Controller.
 *
 * Provides browser automation endpoints:
 * - Device pairing
 * - Run orchestration (queue, claim, heartbeat, cancel)
 * - Event sink for progress and artifacts
 * - Approval workflow
 *
 * Routes: /api/v1/user/{userId}/plugins/brogent/...
 */
#[Route('/api/v1/user/{userId}/plugins/brogent', name: 'api_plugin_brogent_')]
#[OA\Tag(name: 'BroGent Plugin')]
class BrogentController extends AbstractController
{
    private const PROTOCOL_VERSION = 1;

    public function __construct(
        private BrogentService $brogentService,
        private PluginDataService $pluginData,
        private LoggerInterface $logger,
    ) {
    }

    // ========================================================================
    // PAIRING ENDPOINTS
    // ========================================================================

    /**
     * Generate a pairing code for browser extension.
     */
    #[Route('/pair/code', name: 'pair_code', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/brogent/pair/code',
        summary: 'Generate pairing code',
        description: 'Creates a short-lived pairing code for browser extension registration',
        security: [['Bearer' => []]],
        tags: ['BroGent Plugin']
    )]
    #[OA\Response(
        response: 200,
        description: 'Pairing code generated',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'protocolVersion', type: 'integer', example: 1),
                new OA\Property(property: 'pairingCode', type: 'string', example: 'ABCD-EFGH'),
                new OA\Property(property: 'expiresAt', type: 'string', format: 'date-time'),
            ]
        )
    )]
    public function generatePairingCode(
        int $userId,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $result = $this->brogentService->generatePairingCode($userId);

        $this->logger->info('BroGent pairing code generated', [
            'user_id' => $userId,
            'code_prefix' => substr($result['pairingCode'], 0, 4).'-****',
        ]);

        return $this->json([
            'protocolVersion' => self::PROTOCOL_VERSION,
            'pairingCode' => $result['pairingCode'],
            'expiresAt' => $result['expiresAt'],
        ]);
    }

    /**
     * Claim a pairing code and register device.
     */
    #[Route('/pair/claim', name: 'pair_claim', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/brogent/pair/claim',
        summary: 'Claim pairing code',
        description: 'Browser extension claims a pairing code to register as a device',
        tags: ['BroGent Plugin']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['protocolVersion', 'pairingCode', 'device'],
            properties: [
                new OA\Property(property: 'protocolVersion', type: 'integer', example: 1),
                new OA\Property(property: 'pairingCode', type: 'string', example: 'ABCD-EFGH'),
                new OA\Property(
                    property: 'device',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'name', type: 'string', example: 'Chrome on MacBook'),
                        new OA\Property(property: 'platform', type: 'string', example: 'macOS'),
                        new OA\Property(property: 'browser', type: 'string', example: 'chrome'),
                        new OA\Property(property: 'extensionVersion', type: 'string', example: '0.1.0'),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Device registered',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'protocolVersion', type: 'integer'),
                new OA\Property(property: 'deviceId', type: 'string'),
                new OA\Property(property: 'deviceToken', type: 'string'),
                new OA\Property(property: 'scopes', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(
                    property: 'polling',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'intervalMs', type: 'integer'),
                        new OA\Property(property: 'jitterMs', type: 'integer'),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(response: 400, description: 'Invalid or expired pairing code')]
    public function claimPairingCode(
        Request $request,
        int $userId,
    ): JsonResponse {
        // Note: This endpoint doesn't require authentication - it uses the pairing code
        try {
            $data = $request->toArray();
        } catch (\JsonException $e) {
            return $this->json([
                'protocolVersion' => self::PROTOCOL_VERSION,
                'error' => 'Invalid JSON payload',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (empty($data['pairingCode']) || empty($data['device'])) {
            return $this->json([
                'protocolVersion' => self::PROTOCOL_VERSION,
                'error' => 'pairingCode and device are required',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->brogentService->claimPairingCode(
                $userId,
                $data['pairingCode'],
                $data['device']
            );

            $this->logger->info('BroGent device paired', [
                'user_id' => $userId,
                'device_id' => $result['deviceId'],
                'device_name' => $data['device']['name'] ?? 'Unknown',
            ]);

            return $this->json([
                'protocolVersion' => self::PROTOCOL_VERSION,
                'deviceId' => $result['deviceId'],
                'deviceToken' => $result['deviceToken'],
                'scopes' => $result['scopes'],
                'polling' => [
                    'intervalMs' => 2000,
                    'jitterMs' => 500,
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json([
                'protocolVersion' => self::PROTOCOL_VERSION,
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * List paired devices.
     */
    #[Route('/devices', name: 'devices_list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/brogent/devices',
        summary: 'List paired devices',
        security: [['Bearer' => []]],
        tags: ['BroGent Plugin']
    )]
    public function listDevices(
        int $userId,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $devices = $this->brogentService->listDevices($userId);

        return $this->json([
            'protocolVersion' => self::PROTOCOL_VERSION,
            'devices' => $devices,
        ]);
    }

    /**
     * Unpair a device.
     */
    #[Route('/devices/{deviceId}', name: 'devices_delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/user/{userId}/plugins/brogent/devices/{deviceId}',
        summary: 'Unpair a device',
        security: [['Bearer' => []]],
        tags: ['BroGent Plugin']
    )]
    public function unpairDevice(
        int $userId,
        string $deviceId,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $deleted = $this->brogentService->deleteDevice($userId, $deviceId);

        if (!$deleted) {
            return $this->json([
                'protocolVersion' => self::PROTOCOL_VERSION,
                'error' => 'Device not found',
            ], Response::HTTP_NOT_FOUND);
        }

        $this->logger->info('BroGent device unpaired', [
            'user_id' => $userId,
            'device_id' => $deviceId,
        ]);

        return $this->json([
            'protocolVersion' => self::PROTOCOL_VERSION,
            'success' => true,
        ]);
    }

    // ========================================================================
    // TASK ENDPOINTS
    // ========================================================================

    /**
     * List available tasks.
     */
    #[Route('/tasks', name: 'tasks_list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/brogent/tasks',
        summary: 'List available tasks',
        security: [['Bearer' => []]],
        tags: ['BroGent Plugin']
    )]
    public function listTasks(
        int $userId,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $tasks = $this->brogentService->listTasks($userId);

        return $this->json([
            'protocolVersion' => self::PROTOCOL_VERSION,
            'tasks' => $tasks,
        ]);
    }

    /**
     * Get task details.
     */
    #[Route('/tasks/{taskId}', name: 'tasks_get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/brogent/tasks/{taskId}',
        summary: 'Get task details',
        security: [['Bearer' => []]],
        tags: ['BroGent Plugin']
    )]
    public function getTask(
        int $userId,
        string $taskId,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $task = $this->brogentService->getTask($userId, $taskId);

        if (!$task) {
            return $this->json([
                'protocolVersion' => self::PROTOCOL_VERSION,
                'error' => 'Task not found',
            ], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'protocolVersion' => self::PROTOCOL_VERSION,
            'task' => $task,
        ]);
    }

    // ========================================================================
    // RUN ENDPOINTS
    // ========================================================================

    /**
     * Create a new run.
     */
    #[Route('/runs', name: 'runs_create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/brogent/runs',
        summary: 'Create a new run',
        description: 'Queue a task for execution by a paired device',
        security: [['Bearer' => []]],
        tags: ['BroGent Plugin']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['taskId'],
            properties: [
                new OA\Property(property: 'taskId', type: 'string'),
                new OA\Property(property: 'inputs', type: 'object'),
                new OA\Property(property: 'deviceId', type: 'string', description: 'Target specific device'),
            ]
        )
    )]
    public function createRun(
        Request $request,
        int $userId,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $data = $request->toArray();
        } catch (\JsonException $e) {
            return $this->json([
                'protocolVersion' => self::PROTOCOL_VERSION,
                'error' => 'Invalid JSON payload',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (empty($data['taskId'])) {
            return $this->json([
                'protocolVersion' => self::PROTOCOL_VERSION,
                'error' => 'taskId is required',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $run = $this->brogentService->createRun(
                $userId,
                $data['taskId'],
                $data['inputs'] ?? [],
                $data['deviceId'] ?? null
            );

            $this->logger->info('BroGent run created', [
                'user_id' => $userId,
                'run_id' => $run['runId'],
                'task_id' => $data['taskId'],
            ]);

            return $this->json([
                'protocolVersion' => self::PROTOCOL_VERSION,
                'run' => $run,
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->json([
                'protocolVersion' => self::PROTOCOL_VERSION,
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * List runs.
     */
    #[Route('/runs', name: 'runs_list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/brogent/runs',
        summary: 'List runs',
        security: [['Bearer' => []]],
        tags: ['BroGent Plugin']
    )]
    public function listRuns(
        Request $request,
        int $userId,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $status = $request->query->get('status');
        $limit = (int) $request->query->get('limit', '20');

        $runs = $this->brogentService->listRuns($userId, $status, $limit);

        return $this->json([
            'protocolVersion' => self::PROTOCOL_VERSION,
            'runs' => $runs,
        ]);
    }

    /**
     * Claim a queued run (extension polling endpoint).
     */
    #[Route('/runs/claim', name: 'runs_claim', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/brogent/runs/claim',
        summary: 'Claim a queued run',
        description: 'Extension polls this endpoint to claim work',
        tags: ['BroGent Plugin']
    )]
    #[OA\Parameter(
        name: 'deviceId',
        in: 'query',
        required: true,
        description: 'Device ID claiming the run',
        schema: new OA\Schema(type: 'string')
    )]
    public function claimRun(
        Request $request,
        int $userId,
    ): JsonResponse {
        // Authenticate via device token (X-API-Key header)
        $deviceToken = $request->headers->get('X-API-Key');
        $deviceId = $request->query->get('deviceId');

        if (!$deviceToken || !$deviceId) {
            return $this->json([
                'protocolVersion' => self::PROTOCOL_VERSION,
                'error' => 'Device authentication required',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Validate device token
        if (!$this->brogentService->validateDeviceToken($userId, $deviceId, $deviceToken)) {
            return $this->json([
                'protocolVersion' => self::PROTOCOL_VERSION,
                'error' => 'Invalid device token',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Update last seen
        $this->brogentService->updateDeviceLastSeen($userId, $deviceId);

        // Try to claim a run
        $run = $this->brogentService->claimRun($userId, $deviceId);

        if (!$run) {
            return $this->json([
                'protocolVersion' => self::PROTOCOL_VERSION,
                'run' => null,
            ]);
        }

        $this->logger->info('BroGent run claimed', [
            'user_id' => $userId,
            'run_id' => $run['runId'],
            'device_id' => $deviceId,
        ]);

        return $this->json([
            'protocolVersion' => self::PROTOCOL_VERSION,
            'run' => $run,
        ]);
    }

    /**
     * Submit run events.
     */
    #[Route('/runs/{runId}/events', name: 'runs_events', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/brogent/runs/{runId}/events',
        summary: 'Submit run events',
        description: 'Extension submits progress events for a run',
        tags: ['BroGent Plugin']
    )]
    public function submitEvents(
        Request $request,
        int $userId,
        string $runId,
    ): JsonResponse {
        // Authenticate via device token
        $deviceToken = $request->headers->get('X-API-Key');

        try {
            $data = $request->toArray();
        } catch (\JsonException $e) {
            return $this->json([
                'protocolVersion' => self::PROTOCOL_VERSION,
                'error' => 'Invalid JSON payload',
            ], Response::HTTP_BAD_REQUEST);
        }

        $leaseId = $data['leaseId'] ?? null;

        if (!$deviceToken || !$leaseId) {
            return $this->json([
                'protocolVersion' => self::PROTOCOL_VERSION,
                'error' => 'Device authentication and leaseId required',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Validate lease
        if (!$this->brogentService->validateLease($userId, $runId, $leaseId)) {
            return $this->json([
                'protocolVersion' => self::PROTOCOL_VERSION,
                'error' => 'Invalid or expired lease',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $events = $data['events'] ?? [];

        try {
            $this->brogentService->addEvents($userId, $runId, $events);

            return $this->json([
                'protocolVersion' => self::PROTOCOL_VERSION,
                'accepted' => true,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('BroGent event submission failed', [
                'user_id' => $userId,
                'run_id' => $runId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->json([
                'protocolVersion' => self::PROTOCOL_VERSION,
                'accepted' => false,
                'error' => 'Event submission failed',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Extend run lease (heartbeat).
     */
    #[Route('/runs/{runId}/heartbeat', name: 'runs_heartbeat', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/brogent/runs/{runId}/heartbeat',
        summary: 'Extend run lease',
        tags: ['BroGent Plugin']
    )]
    public function heartbeat(
        Request $request,
        int $userId,
        string $runId,
    ): JsonResponse {
        $deviceToken = $request->headers->get('X-API-Key');

        try {
            $data = $request->toArray();
        } catch (\JsonException $e) {
            return $this->json([
                'protocolVersion' => self::PROTOCOL_VERSION,
                'error' => 'Invalid JSON payload',
            ], Response::HTTP_BAD_REQUEST);
        }

        $leaseId = $data['leaseId'] ?? null;

        if (!$deviceToken || !$leaseId) {
            return $this->json([
                'protocolVersion' => self::PROTOCOL_VERSION,
                'error' => 'Device authentication and leaseId required',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $newLease = $this->brogentService->extendLease($userId, $runId, $leaseId);

        if (!$newLease) {
            return $this->json([
                'protocolVersion' => self::PROTOCOL_VERSION,
                'error' => 'Invalid or expired lease',
            ], Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'protocolVersion' => self::PROTOCOL_VERSION,
            'lease' => $newLease,
        ]);
    }

    // ========================================================================
    // APPROVAL ENDPOINTS
    // ========================================================================

    /**
     * Request approval for a dangerous action.
     */
    #[Route('/runs/{runId}/request-approval', name: 'runs_request_approval', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/brogent/runs/{runId}/request-approval',
        summary: 'Request user approval',
        description: 'Extension requests approval for a dangerous action',
        tags: ['BroGent Plugin']
    )]
    public function requestApproval(
        Request $request,
        int $userId,
        string $runId,
    ): JsonResponse {
        $deviceToken = $request->headers->get('X-API-Key');

        try {
            $data = $request->toArray();
        } catch (\JsonException $e) {
            return $this->json([
                'protocolVersion' => self::PROTOCOL_VERSION,
                'error' => 'Invalid JSON payload',
            ], Response::HTTP_BAD_REQUEST);
        }

        $leaseId = $data['leaseId'] ?? null;
        $approval = $data['approval'] ?? null;

        if (!$deviceToken || !$leaseId || !$approval) {
            return $this->json([
                'protocolVersion' => self::PROTOCOL_VERSION,
                'error' => 'Device authentication, leaseId, and approval required',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Validate lease
        if (!$this->brogentService->validateLease($userId, $runId, $leaseId)) {
            return $this->json([
                'protocolVersion' => self::PROTOCOL_VERSION,
                'error' => 'Invalid or expired lease',
            ], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $this->brogentService->requestApproval($userId, $runId, $approval);

            $this->logger->info('BroGent approval requested', [
                'user_id' => $userId,
                'run_id' => $runId,
                'approval_id' => $approval['approvalId'] ?? null,
                'kind' => $approval['kind'] ?? null,
            ]);

            return $this->json([
                'protocolVersion' => self::PROTOCOL_VERSION,
                'accepted' => true,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('BroGent approval request failed', [
                'user_id' => $userId,
                'run_id' => $runId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->json([
                'protocolVersion' => self::PROTOCOL_VERSION,
                'error' => 'Approval request failed',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Check approval status (extension polling).
     */
    #[Route('/runs/{runId}/approval', name: 'runs_approval_status', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/brogent/runs/{runId}/approval',
        summary: 'Check approval status',
        tags: ['BroGent Plugin']
    )]
    public function getApprovalStatus(
        Request $request,
        int $userId,
        string $runId,
    ): JsonResponse {
        $approvalId = $request->query->get('approvalId');

        if (!$approvalId) {
            return $this->json([
                'protocolVersion' => self::PROTOCOL_VERSION,
                'error' => 'approvalId required',
            ], Response::HTTP_BAD_REQUEST);
        }

        $status = $this->brogentService->getApprovalStatus($userId, $runId, $approvalId);

        return $this->json([
            'protocolVersion' => self::PROTOCOL_VERSION,
            'status' => $status['status'] ?? 'pending',
            'decision' => $status['decision'] ?? null,
        ]);
    }

    /**
     * Approve a pending action (from UI).
     */
    #[Route('/runs/{runId}/approve', name: 'runs_approve', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/brogent/runs/{runId}/approve',
        summary: 'Approve a pending action',
        security: [['Bearer' => []]],
        tags: ['BroGent Plugin']
    )]
    public function approveAction(
        Request $request,
        int $userId,
        string $runId,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $data = $request->toArray();
        } catch (\JsonException $e) {
            return $this->json([
                'protocolVersion' => self::PROTOCOL_VERSION,
                'error' => 'Invalid JSON payload',
            ], Response::HTTP_BAD_REQUEST);
        }

        $approvalId = $data['approvalId'] ?? null;

        if (!$approvalId) {
            return $this->json([
                'protocolVersion' => self::PROTOCOL_VERSION,
                'error' => 'approvalId required',
            ], Response::HTTP_BAD_REQUEST);
        }

        $this->brogentService->setApprovalDecision($userId, $runId, $approvalId, 'approved', $data['note'] ?? null);

        $this->logger->info('BroGent action approved', [
            'user_id' => $userId,
            'run_id' => $runId,
            'approval_id' => $approvalId,
        ]);

        return $this->json([
            'protocolVersion' => self::PROTOCOL_VERSION,
            'success' => true,
        ]);
    }

    /**
     * Reject a pending action (from UI).
     */
    #[Route('/runs/{runId}/reject', name: 'runs_reject', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/brogent/runs/{runId}/reject',
        summary: 'Reject a pending action',
        security: [['Bearer' => []]],
        tags: ['BroGent Plugin']
    )]
    public function rejectAction(
        Request $request,
        int $userId,
        string $runId,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $data = $request->toArray();
        } catch (\JsonException $e) {
            return $this->json([
                'protocolVersion' => self::PROTOCOL_VERSION,
                'error' => 'Invalid JSON payload',
            ], Response::HTTP_BAD_REQUEST);
        }

        $approvalId = $data['approvalId'] ?? null;

        if (!$approvalId) {
            return $this->json([
                'protocolVersion' => self::PROTOCOL_VERSION,
                'error' => 'approvalId required',
            ], Response::HTTP_BAD_REQUEST);
        }

        $this->brogentService->setApprovalDecision($userId, $runId, $approvalId, 'rejected', $data['note'] ?? null);

        $this->logger->info('BroGent action rejected', [
            'user_id' => $userId,
            'run_id' => $runId,
            'approval_id' => $approvalId,
        ]);

        return $this->json([
            'protocolVersion' => self::PROTOCOL_VERSION,
            'success' => true,
        ]);
    }

    /**
     * Get run details.
     */
    #[Route('/runs/{runId}', name: 'runs_get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/brogent/runs/{runId}',
        summary: 'Get run details',
        security: [['Bearer' => []]],
        tags: ['BroGent Plugin']
    )]
    public function getRun(
        int $userId,
        string $runId,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $run = $this->brogentService->getRun($userId, $runId);

        if (!$run) {
            return $this->json([
                'protocolVersion' => self::PROTOCOL_VERSION,
                'error' => 'Run not found',
            ], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'protocolVersion' => self::PROTOCOL_VERSION,
            'run' => $run,
        ]);
    }

    /**
     * Verify user has access to this plugin instance.
     */
    private function canAccessPlugin(?User $user, int $userId): bool
    {
        if (null === $user) {
            return false;
        }

        return $user->getId() === $userId;
    }
}
