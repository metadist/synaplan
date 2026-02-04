<?php

declare(strict_types=1);

namespace Plugin\Brogent\Service;

use App\Service\PluginDataService;
use Psr\Log\LoggerInterface;

/**
 * BroGent service - handles device pairing, run orchestration, and events.
 *
 * Uses PluginDataService for storage with data types:
 * - device: Paired browser extension devices
 * - task: Task definitions
 * - run: Task execution instances
 * - pairing_code: Temporary pairing codes
 * - approval: Pending approval requests
 */
final class BrogentService
{
    private const PLUGIN_NAME = 'brogent';
    private const PAIRING_CODE_EXPIRY = 300; // 5 minutes
    private const RUN_LEASE_DURATION = 300; // 5 minutes

    public function __construct(
        private PluginDataService $pluginData,
        private LoggerInterface $logger,
    ) {
    }

    // ========================================================================
    // PAIRING
    // ========================================================================

    /**
     * Generate a new pairing code.
     *
     * @return array{pairingCode: string, expiresAt: string}
     */
    public function generatePairingCode(int $userId): array
    {
        // Generate readable code: XXXX-XXXX
        $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 4).'-'.substr(bin2hex(random_bytes(4)), 0, 4));
        $expiresAt = (new \DateTimeImmutable())->modify('+'.self::PAIRING_CODE_EXPIRY.' seconds');

        $this->pluginData->set($userId, self::PLUGIN_NAME, 'pairing_code', $code, [
            'code' => $code,
            'expiresAt' => $expiresAt->format(\DateTimeInterface::ATOM),
            'createdAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'claimed' => false,
        ]);

        return [
            'pairingCode' => $code,
            'expiresAt' => $expiresAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Claim a pairing code and create a device.
     *
     * @param array{name?: string, platform?: string, browser?: string, extensionVersion?: string} $deviceInfo
     *
     * @return array{deviceId: string, deviceToken: string, scopes: array<string>}
     */
    public function claimPairingCode(int $userId, string $code, array $deviceInfo): array
    {
        // Normalize code for lookup (PluginData sanitizes keys)
        $normalizedCode = $this->normalizeKey($code);
        $pairingData = $this->pluginData->get($userId, self::PLUGIN_NAME, 'pairing_code', $normalizedCode);

        if (!$pairingData) {
            throw new \InvalidArgumentException('Invalid pairing code');
        }

        if ($pairingData['claimed'] ?? false) {
            throw new \InvalidArgumentException('Pairing code already used');
        }

        $expiresAt = new \DateTimeImmutable($pairingData['expiresAt']);
        if ($expiresAt < new \DateTimeImmutable()) {
            throw new \InvalidArgumentException('Pairing code expired');
        }

        // Mark code as claimed (use normalized key)
        $pairingData['claimed'] = true;
        $this->pluginData->set($userId, self::PLUGIN_NAME, 'pairing_code', $normalizedCode, $pairingData);

        // Generate device ID and token
        $deviceId = 'dev_'.bin2hex(random_bytes(12));
        $deviceToken = 'sk_dev_'.bin2hex(random_bytes(24));

        $scopes = ['runs:claim', 'runs:events', 'artifacts:upload'];

        // Store device
        $this->pluginData->set($userId, self::PLUGIN_NAME, 'device', $deviceId, [
            'deviceId' => $deviceId,
            'token' => $deviceToken,
            'name' => $deviceInfo['name'] ?? 'Unknown Device',
            'platform' => $deviceInfo['platform'] ?? 'unknown',
            'browser' => $deviceInfo['browser'] ?? 'unknown',
            'extensionVersion' => $deviceInfo['extensionVersion'] ?? '0.0.0',
            'scopes' => $scopes,
            'lastSeen' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'createdAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);

        return [
            'deviceId' => $deviceId,
            'deviceToken' => $deviceToken,
            'scopes' => $scopes,
        ];
    }

    /**
     * Validate a device token.
     */
    public function validateDeviceToken(int $userId, string $deviceId, string $token): bool
    {
        $device = $this->pluginData->get($userId, self::PLUGIN_NAME, 'device', $deviceId);

        if (!$device) {
            return false;
        }

        return hash_equals($device['token'] ?? '', $token);
    }

    /**
     * Update device last seen timestamp.
     */
    public function updateDeviceLastSeen(int $userId, string $deviceId): void
    {
        $device = $this->pluginData->get($userId, self::PLUGIN_NAME, 'device', $deviceId);

        if ($device) {
            $device['lastSeen'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
            $this->pluginData->set($userId, self::PLUGIN_NAME, 'device', $deviceId, $device);
        }
    }

    /**
     * List all paired devices.
     *
     * @return array<array<string, mixed>>
     */
    public function listDevices(int $userId): array
    {
        $devices = $this->pluginData->list($userId, self::PLUGIN_NAME, 'device');

        // Remove tokens from response
        return array_values(array_map(function ($device) {
            unset($device['token']);

            return $device;
        }, $devices));
    }

    /**
     * Delete a device.
     */
    public function deleteDevice(int $userId, string $deviceId): bool
    {
        return $this->pluginData->delete($userId, self::PLUGIN_NAME, 'device', $deviceId);
    }

    // ========================================================================
    // TASKS
    // ========================================================================

    /**
     * List all tasks.
     *
     * @return array<array<string, mixed>>
     */
    public function listTasks(int $userId): array
    {
        $tasks = $this->pluginData->list($userId, self::PLUGIN_NAME, 'task');

        return array_values(array_filter($tasks, fn ($t) => $t['enabled'] ?? true));
    }

    /**
     * Get a task by ID.
     *
     * @return array<string, mixed>|null
     */
    public function getTask(int $userId, string $taskId): ?array
    {
        return $this->pluginData->get($userId, self::PLUGIN_NAME, 'task', $taskId);
    }

    /**
     * Create or update a task.
     *
     * @param array<string, mixed> $taskData
     */
    public function saveTask(int $userId, string $taskId, array $taskData): void
    {
        $taskData['taskId'] = $taskId;
        $taskData['updatedAt'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        if (!isset($taskData['createdAt'])) {
            $taskData['createdAt'] = $taskData['updatedAt'];
        }

        $this->pluginData->set($userId, self::PLUGIN_NAME, 'task', $taskId, $taskData);
    }

    // ========================================================================
    // RUNS
    // ========================================================================

    /**
     * Create a new run.
     *
     * @param array<string, mixed> $inputs
     *
     * @return array<string, mixed>
     */
    public function createRun(int $userId, string $taskId, array $inputs = [], ?string $targetDeviceId = null): array
    {
        $task = $this->getTask($userId, $taskId);

        if (!$task) {
            throw new \InvalidArgumentException('Task not found');
        }

        $runId = 'run_'.bin2hex(random_bytes(12));
        $now = new \DateTimeImmutable();

        $run = [
            'runId' => $runId,
            'taskId' => $taskId,
            'taskVersion' => $task['version'] ?? 1,
            'site' => $task['site'] ?? null,
            'inputs' => $inputs,
            'steps' => $task['steps'] ?? [],
            'status' => 'queued',
            'targetDeviceId' => $targetDeviceId,
            'deviceId' => null,
            'leaseId' => null,
            'leaseExpiresAt' => null,
            'events' => [],
            'artifacts' => [],
            'pendingApproval' => null,
            'createdAt' => $now->format(\DateTimeInterface::ATOM),
            'startedAt' => null,
            'finishedAt' => null,
        ];

        $this->pluginData->set($userId, self::PLUGIN_NAME, 'run', $runId, $run);

        return $run;
    }

    /**
     * List runs.
     *
     * @return array<array<string, mixed>>
     */
    public function listRuns(int $userId, ?string $status = null, int $limit = 20): array
    {
        $runs = $this->pluginData->list($userId, self::PLUGIN_NAME, 'run');

        // Filter by status
        if ($status) {
            $runs = array_filter($runs, fn ($r) => ($r['status'] ?? '') === $status);
        }

        // Sort by createdAt desc
        usort($runs, fn ($a, $b) => strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? ''));

        // Limit
        return array_slice(array_values($runs), 0, $limit);
    }

    /**
     * Get a run by ID.
     *
     * @return array<string, mixed>|null
     */
    public function getRun(int $userId, string $runId): ?array
    {
        return $this->pluginData->get($userId, self::PLUGIN_NAME, 'run', $runId);
    }

    /**
     * Claim a queued run for a device.
     *
     * @return array<string, mixed>|null
     */
    public function claimRun(int $userId, string $deviceId): ?array
    {
        $runs = $this->pluginData->list($userId, self::PLUGIN_NAME, 'run');

        // Find first queued run (optionally targeted to this device or any device)
        $queuedRun = null;
        foreach ($runs as $run) {
            if (($run['status'] ?? '') !== 'queued') {
                continue;
            }

            // Check if targeted to specific device
            $target = $run['targetDeviceId'] ?? null;
            if ($target && $target !== $deviceId) {
                continue;
            }

            $queuedRun = $run;
            break;
        }

        if (!$queuedRun) {
            return null;
        }

        // Generate lease
        $leaseId = 'lease_'.bin2hex(random_bytes(12));
        $leaseExpiresAt = (new \DateTimeImmutable())->modify('+'.self::RUN_LEASE_DURATION.' seconds');
        $now = new \DateTimeImmutable();

        $queuedRun['status'] = 'claimed';
        $queuedRun['deviceId'] = $deviceId;
        $queuedRun['leaseId'] = $leaseId;
        $queuedRun['leaseExpiresAt'] = $leaseExpiresAt->format(\DateTimeInterface::ATOM);
        $queuedRun['claimedAt'] = $now->format(\DateTimeInterface::ATOM);

        $this->pluginData->set($userId, self::PLUGIN_NAME, 'run', $queuedRun['runId'], $queuedRun);

        // Return run with lease info
        return array_merge($queuedRun, [
            'lease' => [
                'leaseId' => $leaseId,
                'expiresAt' => $leaseExpiresAt->format(\DateTimeInterface::ATOM),
            ],
        ]);
    }

    /**
     * Validate a run lease.
     */
    public function validateLease(int $userId, string $runId, string $leaseId): bool
    {
        $run = $this->getRun($userId, $runId);

        if (!$run) {
            return false;
        }

        if (($run['leaseId'] ?? '') !== $leaseId) {
            return false;
        }

        $expiresAt = new \DateTimeImmutable($run['leaseExpiresAt'] ?? 'now');

        return $expiresAt > new \DateTimeImmutable();
    }

    /**
     * Extend a run lease.
     *
     * @return array{leaseId: string, expiresAt: string}|null
     */
    public function extendLease(int $userId, string $runId, string $leaseId): ?array
    {
        if (!$this->validateLease($userId, $runId, $leaseId)) {
            return null;
        }

        $run = $this->getRun($userId, $runId);
        $newLeaseId = 'lease_'.bin2hex(random_bytes(12));
        $expiresAt = (new \DateTimeImmutable())->modify('+'.self::RUN_LEASE_DURATION.' seconds');

        $run['leaseId'] = $newLeaseId;
        $run['leaseExpiresAt'] = $expiresAt->format(\DateTimeInterface::ATOM);

        $this->pluginData->set($userId, self::PLUGIN_NAME, 'run', $runId, $run);

        return [
            'leaseId' => $newLeaseId,
            'expiresAt' => $expiresAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Add events to a run.
     *
     * @param array<array<string, mixed>> $events
     */
    public function addEvents(int $userId, string $runId, array $events): void
    {
        $run = $this->getRun($userId, $runId);

        if (!$run) {
            throw new \InvalidArgumentException('Run not found');
        }

        $existingEvents = $run['events'] ?? [];

        foreach ($events as $event) {
            // Check for duplicate eventId
            $eventId = $event['eventId'] ?? null;
            if ($eventId) {
                $isDuplicate = false;
                foreach ($existingEvents as $existing) {
                    if (($existing['eventId'] ?? '') === $eventId) {
                        $isDuplicate = true;
                        break;
                    }
                }
                if ($isDuplicate) {
                    continue;
                }
            }

            $existingEvents[] = $event;

            // Update run status based on event type
            $eventType = $event['type'] ?? '';
            if ($eventType === 'run_started') {
                $run['status'] = 'running';
                $run['startedAt'] = $event['ts'] ?? (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
            } elseif ($eventType === 'run_finished') {
                $run['status'] = 'succeeded';
                $run['finishedAt'] = $event['ts'] ?? (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
            } elseif ($eventType === 'run_failed') {
                $run['status'] = 'failed';
                $run['finishedAt'] = $event['ts'] ?? (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
                $run['error'] = $event['data']['error'] ?? null;
            } elseif ($eventType === 'approval_required') {
                $run['status'] = 'waiting_for_user';
            }
        }

        $run['events'] = $existingEvents;
        $this->pluginData->set($userId, self::PLUGIN_NAME, 'run', $runId, $run);
    }

    // ========================================================================
    // APPROVALS
    // ========================================================================

    /**
     * Request approval for a dangerous action.
     *
     * @param array{approvalId: string, kind: string, summary: string, risk?: string} $approval
     */
    public function requestApproval(int $userId, string $runId, array $approval): void
    {
        $run = $this->getRun($userId, $runId);

        if (!$run) {
            throw new \InvalidArgumentException('Run not found');
        }

        $approvalId = $approval['approvalId'] ?? 'appr_'.bin2hex(random_bytes(12));

        $approvalData = [
            'approvalId' => $approvalId,
            'runId' => $runId,
            'kind' => $approval['kind'] ?? 'unknown',
            'summary' => $approval['summary'] ?? '',
            'risk' => $approval['risk'] ?? 'medium',
            'status' => 'pending',
            'decision' => null,
            'requestedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        // Store approval
        $this->pluginData->set($userId, self::PLUGIN_NAME, 'approval', $approvalId, $approvalData);

        // Update run with pending approval
        $run['pendingApproval'] = $approvalData;
        $run['status'] = 'waiting_for_user';
        $this->pluginData->set($userId, self::PLUGIN_NAME, 'run', $runId, $run);
    }

    /**
     * Get approval status.
     *
     * @return array{status: string, decision: ?array<string, mixed>}
     */
    public function getApprovalStatus(int $userId, string $runId, string $approvalId): array
    {
        $approval = $this->pluginData->get($userId, self::PLUGIN_NAME, 'approval', $approvalId);

        if (!$approval) {
            return ['status' => 'not_found', 'decision' => null];
        }

        return [
            'status' => $approval['status'] ?? 'pending',
            'decision' => $approval['decision'] ?? null,
        ];
    }

    /**
     * Set approval decision.
     */
    public function setApprovalDecision(int $userId, string $runId, string $approvalId, string $decision, ?string $note = null): void
    {
        $approval = $this->pluginData->get($userId, self::PLUGIN_NAME, 'approval', $approvalId);

        if (!$approval) {
            throw new \InvalidArgumentException('Approval not found');
        }

        $approval['status'] = $decision;
        $approval['decision'] = [
            'by' => 'user',
            'ts' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'note' => $note,
        ];

        $this->pluginData->set($userId, self::PLUGIN_NAME, 'approval', $approvalId, $approval);

        // Update run
        $run = $this->getRun($userId, $runId);
        if ($run) {
            $run['pendingApproval'] = null;

            if ($decision === 'approved') {
                $run['status'] = 'running';
            } elseif ($decision === 'rejected') {
                $run['status'] = 'cancelled';
                $run['finishedAt'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
            }

            $this->pluginData->set($userId, self::PLUGIN_NAME, 'run', $runId, $run);
        }
    }

    /**
     * Normalize a key for PluginData storage.
     *
     * PluginData sanitizes keys to alphanumeric + underscore only,
     * so we need to apply the same transformation for lookups.
     */
    private function normalizeKey(string $key): string
    {
        return preg_replace('/[^a-z0-9_]/', '', strtolower(trim($key))) ?? '';
    }
}
