<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\DesktopJob;
use App\Entity\User;
use App\Repository\ApiKeyRepository;
use App\Repository\DesktopDeviceRepository;
use App\Service\Desktop\DesktopAgentConfig;
use App\Service\Desktop\DesktopJobContract;
use App\Service\Desktop\DesktopJobStore;
use App\Service\Desktop\Exception\PairingException;
use App\Service\Desktop\Exception\PairingLimitException;
use App\Service\Desktop\PairingCodeService;
use App\Service\Desktop\PairingService;
use App\Service\Infrastructure\RedisService;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Synaplan Desktop pairing + device registry (Sprint A2).
 *
 * Every route is flag-gated by {@see DesktopAgentConfig}: when the feature is
 * OFF the whole surface answers 404 (invariant C8, same pattern as Saved
 * Tasks). Session-cookie users mint and manage codes/devices; the `/pair`
 * exchange is the only unauthenticated route (a fresh client has no session
 * yet) and is rate-limited per IP.
 *
 * The job-enqueue route (`POST /jobs`, Sprint A3) queues `skill.run` work for a
 * paired computer to lease on its next check-in.
 */
#[Route('/api/v1/desktop', name: 'desktop_')]
#[OA\Tag(name: 'Desktop')]
final class DesktopController extends AbstractController
{
    private const PAIR_IP_LIMIT = 60;
    private const PAIR_IP_WINDOW = 3600;

    public function __construct(
        private readonly DesktopAgentConfig $desktopAgentConfig,
        private readonly PairingCodeService $pairingCodeService,
        private readonly PairingService $pairingService,
        private readonly DesktopDeviceRepository $deviceRepository,
        private readonly ApiKeyRepository $apiKeyRepository,
        private readonly DesktopJobStore $jobStore,
        private readonly RedisService $redis,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/pairing-codes', name: 'pairing_code_create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/desktop/pairing-codes',
        operationId: 'createDesktopPairingCode',
        summary: 'Create a one-time pairing code for Synaplan Desktop',
        description: 'Mints a short-lived (10 min) pairing code the user types into Synaplan Desktop to pair the computer. Rate-limited per user.',
        tags: ['Desktop'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Pairing code created',
                content: new OA\JsonContent(
                    required: ['success', 'code', 'expiresAt'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'code', type: 'string', example: 'AB3K7Q2M', description: 'The 8-character pairing code (shown once).'),
                        new OA\Property(property: 'expiresAt', type: 'integer', format: 'int64', example: 1756500000, description: 'Unix timestamp (seconds) when the code expires.'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Feature disabled'),
            new OA\Response(response: 429, description: 'Too many pairing codes'),
        ]
    )]
    public function createPairingCode(#[CurrentUser] ?User $user): JsonResponse
    {
        $this->guard($user?->getId());

        if (!$user instanceof User) {
            return $this->json(['success' => false, 'error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $result = $this->pairingCodeService->create((int) $user->getId());
        } catch (PairingLimitException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], Response::HTTP_TOO_MANY_REQUESTS);
        }

        return $this->json([
            'success' => true,
            'code' => $result['code'],
            'expiresAt' => $result['expiresAt'],
        ], Response::HTTP_CREATED);
    }

    #[Route('/pair', name: 'pair', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/desktop/pair',
        operationId: 'pairDesktopDevice',
        summary: 'Exchange a pairing code for a scoped desktop API key',
        description: 'Consumes a pairing code and returns a one-time scoped API key bound to a new device row. The key is shown once and never again.',
        tags: ['Desktop'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code'],
                properties: [
                    new OA\Property(property: 'code', type: 'string', example: 'AB3K7Q2M'),
                    new OA\Property(property: 'deviceName', type: 'string', example: "Jan's laptop"),
                    new OA\Property(property: 'capabilities', type: 'array', items: new OA\Items(type: 'string'), example: ['skill.run']),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Device paired',
                content: new OA\JsonContent(
                    required: ['success', 'deviceId', 'key', 'apiBaseUrl'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'deviceId', type: 'integer', example: 1),
                        new OA\Property(property: 'key', type: 'string', example: 'sk_...', description: 'The scoped API key — shown once.'),
                        new OA\Property(property: 'apiBaseUrl', type: 'string', example: 'https://web.synaplan.com'),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Invalid or expired code'),
            new OA\Response(response: 404, description: 'Feature disabled'),
            new OA\Response(response: 429, description: 'Too many attempts'),
        ]
    )]
    public function pair(Request $request): JsonResponse
    {
        // No session user here; the client is pairing for the first time. Gate on
        // the global flag so an OFF instance answers 404 (C8).
        $this->guard(null);

        if (!$this->allowPairAttempt($request)) {
            return $this->json(['success' => false, 'error' => 'Too many pairing attempts. Please wait and try again.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            $data = [];
        }

        $code = \is_string($data['code'] ?? null) ? $data['code'] : '';
        $deviceName = \is_string($data['deviceName'] ?? null) ? $data['deviceName'] : '';
        $capabilities = \is_array($data['capabilities'] ?? null) ? array_values($data['capabilities']) : [];

        $userId = $this->pairingCodeService->consume($code);

        // Same message for unknown and expired codes — no user enumeration.
        if (null === $userId) {
            return $this->json(['success' => false, 'error' => 'Invalid or expired pairing code.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->pairingService->pair($userId, $deviceName, $capabilities);
        } catch (PairingException $e) {
            $this->logger->warning('Desktop pairing failed after code consume', ['owner_id' => $userId, 'error' => $e->getMessage()]);

            return $this->json(['success' => false, 'error' => 'Invalid or expired pairing code.'], Response::HTTP_BAD_REQUEST);
        }

        $this->logger->info('Desktop device paired', [
            'owner_id' => $userId,
            'device_id' => $result['deviceId'],
        ]);

        return $this->json([
            'success' => true,
            'deviceId' => $result['deviceId'],
            'key' => $result['key'],
            'apiBaseUrl' => $result['apiBaseUrl'],
        ], Response::HTTP_CREATED);
    }

    #[Route('/devices', name: 'device_list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/desktop/devices',
        operationId: 'listDesktopDevices',
        summary: "List the current user's paired computers",
        tags: ['Desktop'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paired devices',
                content: new OA\JsonContent(
                    required: ['success', 'devices'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'devices',
                            type: 'array',
                            items: new OA\Items(
                                required: ['id', 'name', 'status', 'lastSeen', 'created', 'capabilities'],
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 1),
                                    new OA\Property(property: 'name', type: 'string', example: "Jan's laptop"),
                                    new OA\Property(property: 'keyPrefix', type: 'string', example: 'sk_1234...', nullable: true),
                                    new OA\Property(property: 'status', type: 'string', enum: ['active', 'revoked'], example: 'active'),
                                    new OA\Property(property: 'lastSeen', type: 'integer', format: 'int64', example: 0, description: 'Unix timestamp of the last check-in (0 = never).'),
                                    new OA\Property(property: 'created', type: 'integer', format: 'int64', example: 1756500000),
                                    new OA\Property(property: 'capabilities', type: 'array', items: new OA\Items(type: 'string'), example: ['skill.run']),
                                ]
                            )
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Feature disabled'),
        ]
    )]
    public function listDevices(#[CurrentUser] ?User $user): JsonResponse
    {
        $this->guard($user?->getId());

        if (!$user instanceof User) {
            return $this->json(['success' => false, 'error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $devices = $this->deviceRepository->findByOwner((int) $user->getId());

        return $this->json([
            'success' => true,
            'devices' => array_map(function ($device): array {
                $apiKey = $this->apiKeyRepository->find($device->getApiKeyId());
                $keyPrefix = null !== $apiKey ? substr($apiKey->getKey(), 0, 8).'...' : null;

                return [
                    'id' => $device->getId(),
                    'name' => $device->getName(),
                    'keyPrefix' => $keyPrefix,
                    'status' => $device->getStatus(),
                    'lastSeen' => $device->getLastSeen(),
                    'created' => $device->getCreated(),
                    'capabilities' => $device->getCapabilities(),
                ];
            }, $devices),
        ]);
    }

    #[Route('/devices/{id}', name: 'device_revoke', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[OA\Delete(
        path: '/api/v1/desktop/devices/{id}',
        operationId: 'revokeDesktopDevice',
        summary: 'Revoke a paired computer (kills its API key)',
        tags: ['Desktop'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Device revoked',
                content: new OA\JsonContent(
                    required: ['success'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Feature disabled or device not found'),
        ]
    )]
    public function revokeDevice(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $this->guard($user?->getId());

        if (!$user instanceof User) {
            return $this->json(['success' => false, 'error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $device = $this->deviceRepository->findOwnedById($id, (int) $user->getId());
        if (null === $device) {
            // 404 (not 403) for another user's id — no existence disclosure.
            throw new NotFoundHttpException('Device not found');
        }

        $this->pairingService->revoke($device);

        return $this->json(['success' => true]);
    }

    #[Route('/jobs', name: 'job_enqueue', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/desktop/jobs',
        operationId: 'enqueueDesktopJob',
        summary: 'Queue a skill.run job for a paired computer',
        description: 'Enqueues a job for the user\'s device to lease on its next check-in. The server never verifies the device has the skill; an uninstalled skill fails honestly on the device.',
        tags: ['Desktop'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['type', 'input'],
                properties: [
                    new OA\Property(property: 'deviceId', type: 'integer', nullable: true, example: 1, description: 'Target device; omit/null to let any of the user\'s devices pick it up.'),
                    new OA\Property(property: 'type', type: 'string', enum: ['skill.run'], example: 'skill.run'),
                    new OA\Property(
                        property: 'input',
                        type: 'object',
                        required: ['skill'],
                        properties: [
                            new OA\Property(property: 'skill', type: 'string', example: 'pptx', description: 'Installed skill name (^[a-z0-9-]{1,64}$).'),
                            new OA\Property(property: 'prompt', type: 'string', example: 'Make 3 slides about Q3'),
                            new OA\Property(property: 'fileIds', type: 'array', items: new OA\Items(type: 'integer'), example: []),
                        ]
                    ),
                    new OA\Property(property: 'chatId', type: 'integer', nullable: true, example: 99, description: 'Chat to post the completion note into.'),
                    new OA\Property(property: 'messageId', type: 'integer', nullable: true, example: 1234),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Job queued',
                content: new OA\JsonContent(
                    required: ['success', 'jobId', 'status'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'jobId', type: 'integer', example: 1),
                        new OA\Property(property: 'status', type: 'string', example: 'queued'),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Invalid input'),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Feature disabled or device not found'),
        ]
    )]
    public function enqueueJob(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $this->guard($user?->getId());

        if (!$user instanceof User) {
            return $this->json(['success' => false, 'error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            $data = [];
        }

        $type = \is_string($data['type'] ?? null) ? $data['type'] : '';
        if (!DesktopJobContract::isValidType($type)) {
            return $this->json(['success' => false, 'error' => 'Unsupported job type.'], Response::HTTP_BAD_REQUEST);
        }

        $input = \is_array($data['input'] ?? null) ? $data['input'] : [];
        $skill = \is_string($input['skill'] ?? null) ? trim($input['skill']) : '';
        if (1 !== preg_match('/^[a-z0-9-]{1,64}$/', $skill)) {
            return $this->json(['success' => false, 'error' => 'Invalid skill name.'], Response::HTTP_BAD_REQUEST);
        }

        $prompt = \is_string($input['prompt'] ?? null) ? $input['prompt'] : '';
        $fileIds = [];
        if (\is_array($input['fileIds'] ?? null)) {
            foreach ($input['fileIds'] as $fileId) {
                if (is_numeric($fileId)) {
                    $fileIds[] = (int) $fileId;
                }
            }
        }

        $deviceId = null;
        if (isset($data['deviceId']) && is_numeric($data['deviceId'])) {
            $device = $this->deviceRepository->findOwnedById((int) $data['deviceId'], (int) $user->getId());
            if (null === $device) {
                throw new NotFoundHttpException('Device not found');
            }
            if (!$device->isActive()) {
                return $this->json(['success' => false, 'error' => 'Device is not active.'], Response::HTTP_BAD_REQUEST);
            }
            $deviceId = (int) $device->getId();
        }

        $chatId = isset($data['chatId']) && is_numeric($data['chatId']) ? (int) $data['chatId'] : null;
        $messageId = isset($data['messageId']) && is_numeric($data['messageId']) ? (int) $data['messageId'] : null;
        $idempotency = \is_string($data['idempotencyKey'] ?? null) ? $data['idempotencyKey'] : null;

        $job = $this->jobStore->enqueueSkillRun(
            (int) $user->getId(),
            $deviceId,
            $skill,
            $prompt,
            $fileIds,
            $chatId,
            $messageId,
            $idempotency,
        );

        return $this->json([
            'success' => true,
            'jobId' => $job->getId(),
            'status' => $job->getStatus(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/jobs', name: 'job_list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/desktop/jobs',
        operationId: 'listDesktopJobs',
        summary: "List the current user's recent desktop jobs",
        tags: ['Desktop'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Recent jobs',
                content: new OA\JsonContent(
                    required: ['success', 'jobs'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'jobs',
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                required: ['id', 'type', 'skill', 'status', 'created'],
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 1),
                                    new OA\Property(property: 'deviceId', type: 'integer', nullable: true, example: 1),
                                    new OA\Property(property: 'type', type: 'string', example: 'skill.run'),
                                    new OA\Property(property: 'skill', type: 'string', example: 'pptx'),
                                    new OA\Property(property: 'status', type: 'string', enum: ['queued', 'leased', 'succeeded', 'failed', 'cancelled'], example: 'queued'),
                                    new OA\Property(property: 'errorCode', type: 'string', nullable: true, example: null),
                                    new OA\Property(property: 'result', type: 'object', nullable: true),
                                    new OA\Property(property: 'chatId', type: 'integer', nullable: true, example: 99),
                                    new OA\Property(property: 'created', type: 'integer', format: 'int64', example: 1756500000),
                                    new OA\Property(property: 'updated', type: 'integer', format: 'int64', example: 1756500050),
                                ]
                            )
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Feature disabled'),
        ]
    )]
    public function listJobs(#[CurrentUser] ?User $user): JsonResponse
    {
        $this->guard($user?->getId());

        if (!$user instanceof User) {
            return $this->json(['success' => false, 'error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $jobs = $this->jobStore->recentForOwner((int) $user->getId());

        return $this->json([
            'success' => true,
            'jobs' => array_map([$this, 'jobStatusView'], $jobs),
        ]);
    }

    #[Route('/jobs/{id}', name: 'job_status', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[OA\Get(
        path: '/api/v1/desktop/jobs/{id}',
        operationId: 'getDesktopJob',
        summary: 'Poll a desktop job status (for the waiting/failed card)',
        tags: ['Desktop'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Job status',
                content: new OA\JsonContent(
                    required: ['success', 'job'],
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'job',
                            type: 'object',
                            required: ['id', 'type', 'skill', 'status', 'created'],
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 1),
                                new OA\Property(property: 'deviceId', type: 'integer', nullable: true, example: 1),
                                new OA\Property(property: 'type', type: 'string', example: 'skill.run'),
                                new OA\Property(property: 'skill', type: 'string', example: 'pptx'),
                                new OA\Property(property: 'status', type: 'string', enum: ['queued', 'leased', 'succeeded', 'failed', 'cancelled'], example: 'queued'),
                                new OA\Property(property: 'errorCode', type: 'string', nullable: true, example: null),
                                new OA\Property(property: 'result', type: 'object', nullable: true),
                                new OA\Property(property: 'chatId', type: 'integer', nullable: true, example: 99),
                                new OA\Property(property: 'created', type: 'integer', format: 'int64', example: 1756500000),
                                new OA\Property(property: 'updated', type: 'integer', format: 'int64', example: 1756500050),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Not authenticated'),
            new OA\Response(response: 404, description: 'Feature disabled or job not found'),
        ]
    )]
    public function getJob(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $this->guard($user?->getId());

        if (!$user instanceof User) {
            return $this->json(['success' => false, 'error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $job = $this->jobStore->findOwnedJob($id, (int) $user->getId());
        if (null === $job) {
            throw new NotFoundHttpException('Job not found');
        }

        return $this->json(['success' => true, 'job' => $this->jobStatusView($job)]);
    }

    /**
     * @return array{id: int, deviceId: int|null, type: string, skill: string, status: string, errorCode: string|null, result: array<string, mixed>|null, chatId: int|null, created: int, updated: int}
     */
    private function jobStatusView(DesktopJob $job): array
    {
        return [
            'id' => (int) $job->getId(),
            'deviceId' => $job->getDeviceId(),
            'type' => $job->getType(),
            'skill' => (string) ($job->getInput()['skill'] ?? ''),
            'status' => $job->getStatus(),
            'errorCode' => $job->getErrorCode(),
            'result' => $job->getResult(),
            'chatId' => $job->getChatId(),
            'created' => $job->getCreated(),
            'updated' => $job->getUpdated(),
        ];
    }

    /**
     * 404 the whole surface when the Desktop feature is off (C8).
     */
    private function guard(?int $userId): void
    {
        if (!$this->desktopAgentConfig->isEnabled($userId)) {
            throw new NotFoundHttpException();
        }
    }

    private function allowPairAttempt(Request $request): bool
    {
        $ip = $request->getClientIp() ?? 'unknown';
        $key = 'desktop_pair_attempt:'.sha1($ip);
        $count = $this->redis->increment($key);
        if (1 === $count) {
            $this->redis->expire($key, self::PAIR_IP_WINDOW);
        }

        return null === $count || $count <= self::PAIR_IP_LIMIT;
    }
}
