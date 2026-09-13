<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Runner;

use App\Entity\ComputeRun;
use App\Entity\File;
use App\Entity\User;
use App\Repository\ComputeRunRepository;
use App\Repository\FileRepository;
use App\Repository\UserRepository;
use App\Service\Compute\ComputeArtefactStore;
use App\Service\Compute\ComputeClient;
use App\Service\Compute\ComputeConfig;
use App\Service\Compute\ComputeRefusedException;
use App\Service\Compute\Contract\ComputeRunRequest;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\TaskRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Multitask\Skill\SkillDescriptor;
use App\Service\RateLimitService;
use Psr\Log\LoggerInterface;

/**
 * Planner-visible file-work step. Hidden when compute is off (C1).
 */
final readonly class CodeRunRunner implements TaskRunner
{
    public const QUOTA_COPY = 'You have used this week\'s file-work limit. Nothing new was saved.';

    public function __construct(
        private ComputeConfig $computeConfig,
        private ComputeClient $client,
        private ComputeArtefactStore $artefacts,
        private ComputeRunRepository $runs,
        private FileRepository $files,
        private UserRepository $users,
        private RateLimitService $rateLimits,
        private LoggerInterface $logger,
        private string $uploadDir,
    ) {
    }

    public function supportedCapabilities(): array
    {
        return [Capability::CodeRun];
    }

    public function describe(): array
    {
        return [
            new SkillDescriptor(
                Capability::CodeRun,
                'Run a short Python or Node script on the attached files and return result files',
                available: fn (): bool => $this->computeEnabled(),
            ),
        ];
    }

    public function run(TaskNode $node, NodeContext $context): NodeResult
    {
        $userId = $context->userId ?? 0;
        if (!$this->computeEnabled($userId)) {
            return NodeResult::failed('File work is not available on this installation.');
        }

        $user = $this->users->find($userId);
        if (!$user instanceof User) {
            return NodeResult::failed('File work is not available on this installation.');
        }

        $quota = $this->rateLimits->checkLimit($user, 'COMPUTE_RUNS');
        if (!(bool) ($quota['allowed'] ?? false)) {
            return NodeResult::failed(self::QUOTA_COPY);
        }
        if ($this->runs->countActiveForUser($userId) >= $this->concurrentCap($user)) {
            return NodeResult::failed(self::QUOTA_COPY);
        }

        $inputs = $context->resolveInputs($node);
        $script = $this->script($node, $inputs);
        if (null === $script) {
            return NodeResult::failed('The file-work step had no script to run. Nothing new was saved.');
        }

        $image = $this->image($node);
        $program = 'node' === $image ? 'node' : 'python';
        $scriptName = 'node' === $image ? 'main.js' : 'main.py';
        $limits = $this->computeConfig->clampLimits(is_array($node->params['limits'] ?? null) ? $node->params['limits'] : []);
        try {
            $parts = $this->inputFiles($node, $userId);
        } catch (ComputeRefusedException $e) {
            return NodeResult::failed($e->isQuota() ? self::QUOTA_COPY : $e->getMessage());
        }
        $parts[] = ['name' => $scriptName, 'contents' => $script];
        $fileRefs = [];
        foreach ($parts as $part) {
            $fileRefs[] = ['name' => $part['name'], 'role' => 'input'];
        }

        $placeholder = 'q'.bin2hex(random_bytes(12));
        $audit = new ComputeRun($userId, $placeholder, 'chat', $image, $program, $limits);
        $audit->setMessageId($context->message->getId());
        $this->runs->save($audit);

        try {
            $request = new ComputeRunRequest(
                protocol: 1,
                owner: 'user:'.$userId,
                workspace: ['kind' => 'run'],
                image: $image,
                entry: ['program' => $program, 'args' => [$scriptName]],
                files: $fileRefs,
                limits: $limits,
                egress: ['allow' => []],
            );
            $runId = $this->client->submitRun($request, $parts);
            $audit->setRunId($runId);
            $audit->setStatus(ComputeRun::STATUS_RUNNING);
            $this->runs->save($audit);

            $status = $this->wait($runId, $limits['timeoutSec']);
            $audit->setExitCode($status->exitCode);
            $audit->setReason($status->reason);
            $audit->setDurationMs($status->durationMs);
            $audit->setBytesIn($status->usage['bytesIn']);
            $audit->setBytesOut($status->usage['bytesOut']);

            if ('succeeded' !== $status->status) {
                $audit->markFinished(ComputeRun::STATUS_FAILED);
                $this->runs->save($audit);

                return NodeResult::failed($this->failureCopy($status->reason));
            }

            $stored = $this->artefacts->ingest($runId, $context->message, $limits['outputMb'] * 1024 * 1024);
            $ids = [];
            $descriptors = [];
            foreach ($stored as $file) {
                $id = $file->getId();
                if (null !== $id) {
                    $ids[] = $id;
                    $descriptors[] = [
                        'path' => '/api/v1/files/'.$id,
                        'type' => $file->getFileType(),
                        'name' => $file->getFileName(),
                    ];
                }
            }
            $audit->setArtefactIds($ids);
            $audit->markFinished(ComputeRun::STATUS_SUCCEEDED);
            $this->runs->save($audit);
            $this->rateLimits->recordUsage($user, 'COMPUTE_RUNS', ['cpuSec' => $status->usage['cpuSec']]);

            return NodeResult::ok(
                [] === $stored ? 'File work finished. No new files were saved.' : 'File work finished.',
                $descriptors,
                ['compute_run_id' => $runId, 'exit_code' => $status->exitCode],
            );
        } catch (ComputeRefusedException $e) {
            $audit->setReason($e->errorCode());
            $audit->markFinished(ComputeRun::STATUS_FAILED);
            $this->runs->save($audit);
            $this->logger->info('CodeRunRunner: sidecar refused', ['code' => $e->errorCode()]);

            return NodeResult::failed($e->isQuota() ? self::QUOTA_COPY : $this->failureCopy($e->errorCode()));
        } catch (\Throwable $e) {
            $audit->markFinished(ComputeRun::STATUS_FAILED);
            $this->runs->save($audit);
            $this->logger->error('CodeRunRunner: run failed', ['error' => $e->getMessage()]);

            return NodeResult::failed('File work could not finish. Nothing new was saved.');
        }
    }

    /**
     * @param array<string, mixed> $inputs
     */
    private function script(TaskNode $node, array $inputs): ?string
    {
        foreach ([$node->params['script'] ?? null, $inputs['script'] ?? null, $inputs['text'] ?? null] as $value) {
            if (is_string($value) && '' !== trim($value)) {
                return $value;
            }
        }

        return null;
    }

    private function image(TaskNode $node): string
    {
        $image = is_string($node->params['image'] ?? null) ? $node->params['image'] : 'python';

        return 'node' === $image ? 'node' : 'python';
    }

    /**
     * @return list<array{name: string, contents: string}>
     */
    private function inputFiles(TaskNode $node, int $userId): array
    {
        $ids = $node->params['inputFileIds'] ?? [];
        if (!is_array($ids)) {
            return [];
        }
        $parts = [];
        foreach ($ids as $id) {
            if (!is_numeric($id)) {
                continue;
            }
            $file = $this->files->find((int) $id);
            if (!$file instanceof File || $file->getUserId() !== $userId) {
                throw new ComputeRefusedException('workspace_not_owned', 'A selected file is not yours. Nothing new was saved.');
            }
            $absolute = $this->absolutePath($file);
            if (null === $absolute || !is_file($absolute)) {
                continue;
            }
            $parts[] = [
                'name' => $file->getFileName(),
                'contents' => (string) file_get_contents($absolute),
            ];
        }

        return $parts;
    }

    private function absolutePath(File $file): ?string
    {
        $path = $file->getFilePath();
        if ('' === $path) {
            return null;
        }
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return rtrim($this->uploadDir, '/').'/'.$path;
    }

    private function wait(string $runId, int $timeoutSec): \App\Service\Compute\Contract\ComputeRunStatus
    {
        $deadline = time() + max(1, $timeoutSec) + 5;
        $status = $this->client->status($runId);
        while (!$status->isTerminal() && time() < $deadline) {
            usleep(400000);
            $status = $this->client->status($runId);
        }

        return $status;
    }

    private function concurrentCap(User $user): int
    {
        $level = strtoupper($user->getRateLimitLevel());
        $raw = match ($level) {
            'PRO' => 2,
            'TEAM', 'BUSINESS', 'ADMIN' => 4,
            default => 1,
        };

        return max(1, $raw);
    }

    private function computeEnabled(?int $userId = null): bool
    {
        if (!(new \ReflectionProperty($this, 'computeConfig'))->isInitialized($this)) {
            return false;
        }

        return $this->computeConfig->isEnabled($userId);
    }

    private function failureCopy(?string $reason): string
    {
        return match ($reason) {
            'timeout' => 'File work ran out of time. Nothing new was saved.',
            'oom', 'pids_limit', 'output_limit' => 'File work hit a resource limit. Nothing new was saved.',
            default => 'File work could not finish. Nothing new was saved.',
        };
    }
}
