<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Runner;

use App\Entity\ComputeRun;
use App\Entity\File;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ComputeRunRepository;
use App\Repository\FileRepository;
use App\Repository\UserRepository;
use App\Service\Agent\Policy\AssistantSkillGate;
use App\Service\Compute\ComputeArtefactStore;
use App\Service\Compute\ComputeClient;
use App\Service\Compute\ComputeConfig;
use App\Service\Compute\ComputeEgressResolver;
use App\Service\Compute\ComputeRefusedException;
use App\Service\Compute\ComputeRequestBuilder;
use App\Service\Compute\ComputeWorkspaceService;
use App\Service\Compute\Contract\ComputeRunStatus;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\TaskRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Multitask\Skill\SkillDescriptor;
use App\Service\RateLimitService;
use App\Service\Runtime\RuntimeProfile;
use App\Service\Tool\Exception\ToolNotRegisteredException;
use App\Service\Tool\Policy\PolicyContext;
use App\Service\Tool\Policy\PolicyOutcome;
use App\Service\Tool\ToolExecutionGate;
use Psr\Log\LoggerInterface;

/**
 * Planner-visible file-work step. Hidden when compute is off (C1).
 * Every door (planner, both gateways, saved tasks) writes the same audit row.
 */
final readonly class CodeRunRunner implements TaskRunner
{
    public const QUOTA_COPY = 'You have used this week\'s file-work limit. Nothing new was saved.';
    public const FORBID_COPY = AssistantSkillGate::REFUSAL;
    public const EGRESS_NEEDS_APPROVALS_COPY = 'Reaching a website from file work needs your approval, and approvals are off on this installation. Nothing new was saved.';
    private const SCRIPT_PYTHON = '_synaplan_main.py';
    private const SCRIPT_NODE = '_synaplan_main.js';
    private const SIDECAR_CONCURRENT_CEILING = 8;
    /** Max stdout characters surfaced in the chat reply (chatty scripts are clipped). */
    private const STDOUT_REPLY_CAP = 4000;
    /** Max stderr characters surfaced when a run fails (the tail carries the actual error). */
    private const STDERR_REPLY_CAP = 1500;

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
        private ?ToolExecutionGate $executionGate = null,
        private ?ComputeWorkspaceService $workspaces = null,
        private ?ComputeEgressResolver $egress = null,
        private ?ComputeRequestBuilder $requests = null,
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
                dynamicNote: fn (?int $userId, array $context): ?string => $this->plannerNote($userId),
                available: fn (): bool => $this->computeEnabled(),
            ),
        ];
    }

    /**
     * The planner-facing contract for the B3 node params. Each line appears
     * only while its flag is on for this user, so a planner on an install
     * without workspaces or egress never learns the fields exist (U11).
     */
    private function plannerNote(?int $userId): ?string
    {
        if (!$this->computeEnabled($userId)) {
            return null;
        }
        $lines = [
            '  params.script (required): the COMPLETE program as multi-line text with real newlines. params.image: "python" (default) or "node". Omit params.inputFileIds: this turn\'s attached files are mounted automatically (you never see their numeric ids — never invent any).',
            '  NEVER join statements with semicolons and NEVER emit a one-liner: a compound statement (with/for/if/def/try) after ";" is a syntax error and fails the run. print() the answer (stdout is returned) and write any result files to /out/ (e.g. open("/out/result.csv","w")) — only files under /out are saved and offered for download; the working directory is discarded.',
        ];
        if ($this->workspacesEnabled($userId)) {
            $lines[] = '  params.useWorkspace: true — keep this run\'s files in the user\'s persistent folder (mounted at /workspace and readable by later runs). Set it when the user wants to continue earlier file work or keep results for later; otherwise omit it.';
        }
        if (null !== $this->egress && $this->computeConfig->egressEnabled($userId)) {
            $lines[] = sprintf(
                '  params.egressHosts: list of public website host names (max %d) the script must reach, e.g. ["api.example.com"]. Every other network access is blocked and the user is asked before the run. Omit it when the script needs no internet.',
                $this->computeConfig->egressMaxHosts(),
            );
        }

        return implode("\n", $lines);
    }

    public function run(TaskNode $node, NodeContext $context): NodeResult
    {
        $userId = $context->userId ?? 0;
        $user = $this->users->find($userId);
        if (!$user instanceof User) {
            return NodeResult::failed('File work is not available on this installation.');
        }

        $script = $this->script($node, $context->resolveInputs($node));
        if (null === $script) {
            return NodeResult::failed('The file-work step had no script to run. Nothing new was saved.');
        }

        $savedTaskRunId = is_numeric($context->options['saved_task_run_id'] ?? null)
            ? (int) $context->options['saved_task_run_id']
            : null;
        $invokedVia = null !== $savedTaskRunId ? ComputeRun::VIA_SAVED_TASK : ComputeRun::VIA_PLANNER;
        $policyContext = null !== $savedTaskRunId ? PolicyContext::Unattended : PolicyContext::Interactive;
        $profile = $this->profileFrom($context);
        $timeout = is_array($node->params['limits'] ?? null)
            ? (int) ($node->params['limits']['timeoutSec'] ?? 0)
            : 0;
        $inputIds = [];
        foreach ($node->params['inputFileIds'] ?? [] as $id) {
            if (is_numeric($id)) {
                $inputIds[] = (int) $id;
            }
        }

        // The planner references attachments as `$message.files` and cannot know
        // their numeric ids, so an interactive "run code on the attached file"
        // turn arrives with no inputFileIds — the script then can't find the
        // file (FileNotFoundError). Fall back to the message's own attachments
        // so the user-selected files are mounted into the sandbox by name. Uses
        // findFilesByMessageIds so BOTH the ManyToMany attachments (web chat)
        // and the legacy single-file column (channel messages, File.messageId)
        // are covered.
        if ([] === $inputIds) {
            foreach ($context->message->getFiles() as $file) {
                $fileId = $file->getId();
                if (null !== $fileId) {
                    $inputIds[] = (int) $fileId;
                }
            }
            $messageId = $context->message->getId();
            if ([] === $inputIds && $context->message->getFile() > 0 && null !== $messageId) {
                foreach ($this->files->findFilesByMessageIds($userId, [$messageId], 20) as $file) {
                    $fileId = $file->getId();
                    if (null !== $fileId) {
                        $inputIds[] = (int) $fileId;
                    }
                }
            }
        }

        $result = $this->executeDirect(
            $user,
            $this->image($node),
            $script,
            $inputIds,
            $timeout > 0 ? $timeout : null,
            $invokedVia,
            $profile,
            $context->message,
            is_numeric($context->classification['prompt_id'] ?? null) ? (int) $context->classification['prompt_id'] : $profile?->promptId,
            $savedTaskRunId,
            null,
            $policyContext,
            true === ($context->options['allow_unattended'] ?? false),
            $context->isApproved($node->id),
            $context->message->getId(),
            $node,
            true === ($node->params['useWorkspace'] ?? false),
            $this->hostList($node->params['egressHosts'] ?? []),
        );

        return $this->toNodeResult($result);
    }

    /**
     * Shared executor for the planner, both gateways, and saved tasks.
     *
     * @param list<int>    $inputFileIds
     * @param list<string> $egressHosts  Host names the run may reach; resolved and pinned only when egress is on
     *
     * @return array{
     *     outcome: string,
     *     status: string,
     *     exit_code: ?int,
     *     stdout: string,
     *     stderr: string,
     *     artefacts: list<array{file_id: int, name: string, mime: string, size: int}>,
     *     error: ?string,
     *     approval_id: ?int,
     *     compute_run_id: ?string,
     *     used_workspace?: bool
     * }
     */
    public function executeDirect(
        User $user,
        string $language,
        string $code,
        array $inputFileIds,
        ?int $timeoutSec,
        string $invokedVia,
        ?RuntimeProfile $assistant = null,
        ?Message $message = null,
        ?int $promptId = null,
        ?int $savedTaskRunId = null,
        ?int $approvalId = null,
        PolicyContext $policyContext = PolicyContext::Interactive,
        bool $allowUnattended = false,
        bool $alreadyApproved = false,
        ?int $messageId = null,
        ?TaskNode $node = null,
        bool $useWorkspace = false,
        array $egressHosts = [],
    ): array {
        $userId = (int) $user->getId();
        if (!$this->computeEnabled($userId)) {
            return $this->failedOutcome('File work is not available on this installation.');
        }

        if (!AssistantSkillGate::allows($assistant, Capability::CodeRun->value)) {
            return $this->failedOutcome(self::FORBID_COPY, AssistantSkillGate::REASON);
        }

        try {
            $egress = $this->resolveEgress($userId, $egressHosts);
        } catch (ComputeRefusedException $e) {
            return $this->failedOutcome($e->getMessage(), $e->errorCode());
        }
        $forceApproveForEgress = [] !== $egress['allow'] && $this->computeConfig->egressRequiresApproval($userId);
        if ($forceApproveForEgress && !$alreadyApproved && !$this->canRequestApproval($userId)) {
            // Fail closed: the operator asked for a human in the loop before
            // any website is reached, and no approval can be produced here.
            return $this->failedOutcome(self::EGRESS_NEEDS_APPROVALS_COPY, 'egress_not_allowed');
        }

        if (!$alreadyApproved) {
            $gated = $this->consultPolicy(
                $user,
                $language,
                $code,
                $inputFileIds,
                $timeoutSec,
                $invokedVia,
                $policyContext,
                $allowUnattended,
                $savedTaskRunId,
                $messageId,
                $node,
                $forceApproveForEgress,
                array_map(static fn (array $host): string => $host['host'], $egress['allow']),
            );
            if (null !== $gated) {
                return $gated;
            }
        }

        $quota = $this->rateLimits->checkLimit($user, 'COMPUTE_RUNS');
        if (!(bool) ($quota['allowed'] ?? false)) {
            return $this->failedOutcome(self::QUOTA_COPY);
        }
        $cpuCap = $this->rateLimits->computeIntSetting($user, 'COMPUTE_CPU_SECONDS_DAILY', $this->defaultCpuCap($user));
        $usedCpu = intdiv($this->runs->sumDurationMsSince($userId, new \DateTimeImmutable('today')), 1000);
        if ($cpuCap <= 0 || $usedCpu >= $cpuCap) {
            return $this->failedOutcome(self::QUOTA_COPY);
        }

        $image = 'node' === $language ? 'node' : 'python';
        $program = $image;
        $scriptName = 'node' === $image ? self::SCRIPT_NODE : self::SCRIPT_PYTHON;
        $limits = $this->computeConfig->clampLimits(
            null !== $timeoutSec ? ['timeoutSec' => $timeoutSec] : [],
        );

        try {
            $parts = $this->inputFilesFromIds($inputFileIds, $userId, $scriptName);
        } catch (ComputeRefusedException $e) {
            return $this->failedOutcome($e->isQuota() ? self::QUOTA_COPY : $e->getMessage(), $e->errorCode());
        }
        $parts[] = ['name' => $scriptName, 'contents' => $code];
        $fileRefs = [];
        foreach ($parts as $part) {
            $fileRefs[] = ['name' => $part['name'], 'role' => 'input'];
        }

        $placeholder = 'q'.bin2hex(random_bytes(12));
        $audit = null !== $approvalId ? $this->runs->findQueuedByApproval($approvalId) : null;
        if (!$audit instanceof ComputeRun) {
            $audit = new ComputeRun($userId, $placeholder, $invokedVia, $image, $program, $limits);
        }
        $audit->setMessageId($messageId ?? $message?->getId());
        $audit->setPromptId($promptId ?? $assistant?->promptId);
        $audit->setSavedTaskRunId($savedTaskRunId);
        if (null !== $approvalId) {
            $audit->setApprovalId($approvalId);
        }
        $this->runs->save($audit);
        if ($this->runs->countActiveForUser($userId) > $this->concurrentCap($user)) {
            $audit->setReason('capacity_exceeded');
            $audit->markFinished(ComputeRun::STATUS_FAILED);
            $this->runs->save($audit);

            return $this->failedOutcome(self::QUOTA_COPY);
        }

        $usedWorkspace = false;
        try {
            $workspace = ['kind' => 'run'];
            if ($useWorkspace && $this->workspacesEnabled($userId) && null !== $this->workspaces) {
                $row = $this->workspaces->ensure($user);
                $workspace = ['kind' => 'user', 'id' => $row->getWorkspaceId()];
                $audit->setWorkspaceId($row->getWorkspaceId());
                $usedWorkspace = true;
            }
            $audit->setEgressHosts(array_map(
                static fn (array $host): string => $host['host'],
                $egress['allow'],
            ));
            $this->runs->save($audit);

            $builder = $this->requests ?? new ComputeRequestBuilder();
            $request = $builder->build(
                ComputeWorkspaceService::ownerString($userId),
                $workspace,
                $image,
                ['program' => $program, 'args' => [$scriptName]],
                $fileRefs,
                $limits,
                $egress,
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
            $logs = $this->client->collectLogs($runId);

            if ('succeeded' !== $status->status) {
                $audit->markFinished(ComputeRun::STATUS_FAILED);
                $this->runs->save($audit);

                // The stderr tail is the actual cause (a stack trace / syntax
                // error). Log it so a failed run is diagnosable even when the DAG
                // falls back to the legacy router and discards the node message.
                $stderrTail = trim((string) $logs['stderr']);
                if ('' !== $stderrTail) {
                    $this->logger->warning('CodeRunRunner: run failed', [
                        'compute_run_id' => $runId,
                        'exit_code' => $status->exitCode,
                        'reason' => $status->reason,
                        'stderr' => mb_substr($stderrTail, -self::STDERR_REPLY_CAP),
                    ]);
                }

                return [
                    'outcome' => 'failed',
                    'status' => ComputeRun::STATUS_FAILED,
                    'exit_code' => $status->exitCode,
                    'stdout' => $logs['stdout'],
                    'stderr' => $logs['stderr'],
                    'artefacts' => [],
                    'error' => $this->failureCopy($status->reason, usedWorkspace: true === $usedWorkspace),
                    'approval_id' => $audit->getApprovalId(),
                    'compute_run_id' => $runId,
                    'used_workspace' => $usedWorkspace,
                ];
            }

            $stored = null !== $message
                ? $this->artefacts->ingest($runId, $message, $limits['outputMb'] * 1024 * 1024)
                : $this->artefacts->ingestForUser($runId, $userId, $limits['outputMb'] * 1024 * 1024, $messageId);
            $ids = [];
            $artefacts = [];
            foreach ($stored as $file) {
                $id = $file->getId();
                if (null !== $id) {
                    $ids[] = $id;
                    $artefacts[] = [
                        'file_id' => $id,
                        'name' => $file->getFileName(),
                        'mime' => $file->getFileMime(),
                        'size' => $file->getFileSize(),
                    ];
                }
            }
            $audit->setArtefactIds($ids);
            $audit->markFinished(ComputeRun::STATUS_SUCCEEDED);
            $this->runs->save($audit);
            $this->rateLimits->recordUsage($user, 'COMPUTE_RUNS', ['cpuSec' => $status->usage['cpuSec']]);
            $this->refreshWorkspace($user);

            return [
                'outcome' => 'ok',
                'status' => ComputeRun::STATUS_SUCCEEDED,
                'exit_code' => $status->exitCode,
                'stdout' => $logs['stdout'],
                'stderr' => $logs['stderr'],
                'artefacts' => $artefacts,
                'error' => null,
                'approval_id' => $audit->getApprovalId(),
                'compute_run_id' => $runId,
                'used_workspace' => $usedWorkspace,
            ];
        } catch (ComputeRefusedException $e) {
            $audit->setReason($e->errorCode());
            $audit->markFinished(ComputeRun::STATUS_FAILED);
            $this->runs->save($audit);
            $this->logger->info('CodeRunRunner: sidecar refused', ['code' => $e->errorCode()]);

            return $this->failedOutcome($e->isQuota() ? self::QUOTA_COPY : $this->failureCopy($e->errorCode(), ran: false, usedWorkspace: $usedWorkspace), $e->errorCode(), $usedWorkspace);
        } catch (\Throwable $e) {
            $audit->markFinished(ComputeRun::STATUS_FAILED);
            $this->runs->save($audit);
            $this->logger->error('CodeRunRunner: run failed', ['error' => $e->getMessage()]);

            return $this->failedOutcome(
                $usedWorkspace
                    ? 'File work could not finish. Check Workspace for anything this run already wrote.'
                    : 'File work could not finish. Nothing new was saved.',
                usedWorkspace: $usedWorkspace,
            );
        }
    }

    /**
     * @param list<int>    $inputFileIds
     * @param list<string> $egressHosts
     *
     * @return array{
     *     outcome: string,
     *     status: string,
     *     exit_code: ?int,
     *     stdout: string,
     *     stderr: string,
     *     artefacts: list<array{file_id: int, name: string, mime: string, size: int}>,
     *     error: ?string,
     *     approval_id: ?int,
     *     compute_run_id: ?string,
     *     used_workspace?: bool
     * }|null
     */
    private function consultPolicy(
        User $user,
        string $language,
        string $code,
        array $inputFileIds,
        ?int $timeoutSec,
        string $invokedVia,
        PolicyContext $policyContext,
        bool $allowUnattended,
        ?int $savedTaskRunId,
        ?int $messageId,
        ?TaskNode $node,
        bool $forceApproveForEgress = false,
        array $egressHosts = [],
    ): ?array {
        if (null === $this->executionGate) {
            return null;
        }

        $userId = (int) $user->getId();
        $args = [
            'language' => $language,
        ];
        if ([] !== $egressHosts) {
            // A scalar so the approval preview shows which websites the run may
            // reach (U7: "what will it touch?") — arrays are left out of previews.
            $args['websites'] = implode(', ', $egressHosts);
        }
        $args += [
            'code' => $code,
            'input_file_ids' => $inputFileIds,
            'timeout_sec' => $timeoutSec,
        ];
        $requestedBy = null !== $savedTaskRunId && null !== $node
            ? sprintf('task_run:%d:%s', $savedTaskRunId, $node->id)
            : 'chat:'.(int) ($messageId ?? 0);
        $override = is_string($node?->params['approval'] ?? null) ? $node->params['approval'] : null;

        try {
            // A run with egress must reach a human: the gate lifts Auto to
            // Approve so neither always-allow nor allow_unattended can skip it
            // (Block from the policy or the node still wins).
            $decision = $this->executionGate->inspect(
                $userId,
                Capability::CodeRun->value,
                $args,
                $user,
                $policyContext,
                $requestedBy,
                null,
                $allowUnattended,
                null,
                $override,
                $forceApproveForEgress,
            );
        } catch (ToolNotRegisteredException) {
            return null;
        }

        if (PolicyOutcome::Block === $decision['outcome']) {
            return $this->failedOutcome((string) $decision['refusal']);
        }

        if (PolicyOutcome::Approve === $decision['outcome'] && null !== $decision['approval']) {
            $approvalId = (int) $decision['approval']->getId();
            $image = 'node' === $language ? 'node' : 'python';
            $limits = $this->computeConfig->clampLimits(null !== $timeoutSec ? ['timeoutSec' => $timeoutSec] : []);
            $audit = new ComputeRun(
                $userId,
                'q'.bin2hex(random_bytes(12)),
                $invokedVia,
                $image,
                $image,
                $limits,
            );
            $audit->setApprovalId($approvalId);
            $audit->setMessageId($messageId);
            $audit->setSavedTaskRunId($savedTaskRunId);
            $this->runs->save($audit);

            return [
                'outcome' => 'waiting_approval',
                'status' => ComputeRun::STATUS_QUEUED,
                'exit_code' => null,
                'stdout' => '',
                'stderr' => '',
                'artefacts' => [],
                'error' => null,
                'approval_id' => $approvalId,
                'compute_run_id' => $audit->getRunId(),
            ];
        }

        return null;
    }

    /**
     * @param array{
     *     outcome: string,
     *     status: string,
     *     exit_code: ?int,
     *     stdout: string,
     *     stderr: string,
     *     artefacts: list<array{file_id: int, name: string, mime: string, size: int}>,
     *     error: ?string,
     *     approval_id: ?int,
     *     compute_run_id: ?string,
     *     used_workspace?: bool
     * } $result
     */
    private function toNodeResult(array $result): NodeResult
    {
        if ('waiting_approval' === $result['outcome'] && null !== $result['approval_id']) {
            return NodeResult::waitingApproval($result['approval_id'], [], [
                'tool' => Capability::CodeRun->value,
                'compute_run_id' => $result['compute_run_id'],
            ]);
        }
        if ('ok' !== $result['outcome']) {
            // Honest outcome (U8): when the user asked to run code and it ran but
            // errored, show WHAT failed — the stderr tail carries the real cause
            // (stack trace / syntax error) — not just a generic "it failed" line.
            $message = (string) $result['error'];
            $stderr = trim((string) $result['stderr']);
            if ('' !== $stderr) {
                if (mb_strlen($stderr) > self::STDERR_REPLY_CAP) {
                    $stderr = '…'.mb_substr($stderr, -self::STDERR_REPLY_CAP);
                }
                $message .= "\n\n```\n".$stderr."\n```";
            }

            return NodeResult::failed($message, [
                'used_workspace' => true === ($result['used_workspace'] ?? false),
                'exit_code' => $result['exit_code'],
                'compute_run_id' => $result['compute_run_id'],
            ]);
        }

        $descriptors = [];
        foreach ($result['artefacts'] as $artefact) {
            $descriptors[] = [
                'path' => '/api/v1/files/'.$artefact['file_id'].'/download',
                'type' => $artefact['mime'],
                'name' => $artefact['name'],
            ];
        }

        // The script's stdout is the answer the user asked for ("tell me the
        // number of rows" → "3"). Surface it as the reply text — without it the
        // run succeeds but the user only sees "File work finished" and never the
        // result. Capped so a chatty script cannot flood the chat bubble.
        $stdout = trim((string) $result['stdout']);
        if ('' !== $stdout) {
            if (mb_strlen($stdout) > self::STDOUT_REPLY_CAP) {
                $stdout = mb_substr($stdout, 0, self::STDOUT_REPLY_CAP)."\n…";
            }
            $text = [] === $descriptors
                ? $stdout
                : $stdout."\n\n".'Saved '.count($descriptors).' file(s).';
        } else {
            $text = [] === $descriptors
                ? 'File work finished. No new files were saved.'
                : 'File work finished.';
        }

        return NodeResult::ok(
            $text,
            $descriptors,
            [
                'compute_run_id' => $result['compute_run_id'],
                'exit_code' => $result['exit_code'],
                'used_workspace' => true === ($result['used_workspace'] ?? false),
            ],
        );
    }

    /**
     * @return array{
     *     outcome: 'failed',
     *     status: string,
     *     exit_code: null,
     *     stdout: string,
     *     stderr: string,
     *     artefacts: list<array{file_id: int, name: string, mime: string, size: int}>,
     *     error: string,
     *     approval_id: null,
     *     compute_run_id: null,
     *     used_workspace: bool
     * }
     */
    private function failedOutcome(string $error, ?string $reason = null, bool $usedWorkspace = false): array
    {
        unset($reason);

        return [
            'outcome' => 'failed',
            'status' => ComputeRun::STATUS_FAILED,
            'exit_code' => null,
            'stdout' => '',
            'stderr' => '',
            'artefacts' => [],
            'error' => $error,
            'approval_id' => null,
            'compute_run_id' => null,
            'used_workspace' => $usedWorkspace,
        ];
    }

    private function profileFrom(NodeContext $context): ?RuntimeProfile
    {
        $profile = $context->options['runtime_profile'] ?? $context->classification['runtime_profile'] ?? null;

        return $profile instanceof RuntimeProfile ? $profile : null;
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
     * @param list<int> $ids
     *
     * @return list<array{name: string, contents: string}>
     */
    private function inputFilesFromIds(array $ids, int $userId, string $reservedName): array
    {
        $parts = [];
        $used = [$reservedName => true];
        foreach ($ids as $id) {
            $file = $this->files->find($id);
            if (!$file instanceof File || $file->getUserId() !== $userId) {
                throw new ComputeRefusedException('workspace_not_owned', 'A selected file is not yours. Nothing new was saved.');
            }
            $absolute = $this->absolutePath($file);
            if (null === $absolute || !is_file($absolute) || !is_readable($absolute)) {
                throw new ComputeRefusedException('internal_error', 'A selected file could not be read. Nothing new was saved.');
            }
            $contents = file_get_contents($absolute);
            if (false === $contents) {
                throw new ComputeRefusedException('internal_error', 'A selected file could not be read. Nothing new was saved.');
            }
            $parts[] = [
                'name' => $this->uniquePartName($file->getFileName(), $used),
                'contents' => $contents,
            ];
        }

        return $parts;
    }

    /**
     * @param array<string, true> $used
     */
    private function uniquePartName(string $wanted, array &$used): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $wanted) ?: 'file';
        $safe = substr($safe, 0, 120);
        if (!isset($used[$safe])) {
            $used[$safe] = true;

            return $safe;
        }
        $ext = pathinfo($safe, PATHINFO_EXTENSION);
        $base = pathinfo($safe, PATHINFO_FILENAME) ?: 'file';
        $i = 2;
        do {
            $suffix = '' !== $ext ? '.'.$ext : '';
            $candidate = substr($base, 0, 110).'_'.$i.$suffix;
            ++$i;
        } while (isset($used[$candidate]));
        $used[$candidate] = true;

        return $candidate;
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

    private function wait(string $runId, int $timeoutSec): ComputeRunStatus
    {
        $deadline = time() + max(1, $timeoutSec) + 5;
        $status = $this->client->status($runId);
        while (!$status->isTerminal() && time() < $deadline) {
            usleep(400000);
            $status = $this->client->status($runId);
        }
        if ($status->isTerminal()) {
            return $status;
        }
        try {
            $this->client->cancel($runId);
        } catch (\Throwable) {
        }
        try {
            $final = $this->client->status($runId);
            if ($final->isTerminal()) {
                return $final;
            }
        } catch (\Throwable) {
        }

        return new ComputeRunStatus(
            runId: $runId,
            status: 'failed',
            usage: $status->usage,
            truncated: $status->truncated,
            exitCode: $status->exitCode,
            reason: 'timeout',
            startedAt: $status->startedAt,
            finishedAt: $status->finishedAt,
            durationMs: $status->durationMs,
        );
    }

    private function concurrentCap(User $user): int
    {
        $raw = $this->rateLimits->computeIntSetting($user, 'COMPUTE_CONCURRENT', $this->defaultConcurrentCap($user));

        return max(0, min(self::SIDECAR_CONCURRENT_CEILING, $raw));
    }

    private function defaultConcurrentCap(User $user): int
    {
        return match (strtoupper($this->rateLimits->resolveRateLimitLevel($user))) {
            'PRO' => 2,
            'TEAM', 'BUSINESS', 'ADMIN' => 4,
            default => 1,
        };
    }

    private function defaultCpuCap(User $user): int
    {
        return match (strtoupper($this->rateLimits->resolveRateLimitLevel($user))) {
            'PRO' => 300,
            'TEAM' => 900,
            'BUSINESS', 'ADMIN' => 3600,
            'ANONYMOUS' => 0,
            default => 60,
        };
    }

    /**
     * @return list<string>
     */
    private function hostList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $hosts = [];
        foreach ($raw as $host) {
            if (is_string($host) && '' !== trim($host)) {
                $hosts[] = trim($host);
            }
        }

        return $hosts;
    }

    /**
     * @param list<string> $hosts
     *
     * @return array{allow: list<array{host: string, port: int, ips: list<string>}>}
     */
    private function resolveEgress(int $userId, array $hosts): array
    {
        if (null === $this->egress || !$this->computeConfig->egressEnabled($userId)) {
            return ['allow' => []];
        }

        return $this->egress->resolve($hosts, $userId);
    }

    private function workspacesEnabled(?int $userId): bool
    {
        // Only reached from executeDirect() after computeEnabled() passed, so
        // ComputeConfig is guaranteed to be injected here.
        return null !== $this->workspaces && $this->computeConfig->workspacesEnabled($userId);
    }

    private function canRequestApproval(int $userId): bool
    {
        return null !== $this->executionGate && $this->executionGate->approvalsEnabled($userId);
    }

    private function refreshWorkspace(User $user): void
    {
        if (null === $this->workspaces) {
            return;
        }
        $row = $this->workspaces->forUser($user);
        if (null === $row) {
            return;
        }
        try {
            $this->workspaces->refreshUsage($row);
        } catch (\Throwable) {
            // Usage is advisory; a failed refresh must not fail a finished run.
        }
    }

    private function computeEnabled(?int $userId = null): bool
    {
        // SkillCatalogFactory instantiates runners without constructors so
        // describe() can be catalogued. File work stays hidden until DI
        // has injected ComputeConfig.
        if (!(new \ReflectionProperty($this, 'computeConfig'))->isInitialized($this)) {
            return false;
        }

        return $this->computeConfig->isEnabled($userId);
    }

    /**
     * One sentence per terminal reason. When the run used the persistent
     * folder, never claim "nothing was saved" — a timeout or crash can leave
     * files, and a quota rollback keeps files that were already there
     * (including ones this run changed).
     */
    private function failureCopy(?string $reason, bool $ran = true, bool $usedWorkspace = false): string
    {
        $wrote = $ran && $usedWorkspace;

        return match ($reason) {
            'timeout' => $wrote
                ? 'File work ran out of time. Anything this run already wrote to your folder is still there — open Workspace to check.'
                : 'File work ran out of time. Nothing new was saved.',
            'oom', 'pids_limit', 'output_limit' => $wrote
                ? 'File work hit a resource limit. Anything this run already wrote to your folder is still there — open Workspace to check.'
                : 'File work hit a resource limit. Nothing new was saved.',
            'workspace_busy' => 'Another file-work run is still using your folder. Wait for it to finish, then try again. Nothing new was saved.',
            'egress_unavailable' => 'File work could not reach the approved websites, so the run did not start. Nothing was sent or saved.',
            'workspace_quota_exceeded' => $ran
                ? 'This run wrote more than your file-work folder allows. The new files it created were removed. Files that were already there stay, including any this run changed.'
                : 'Your file-work folder is full. Delete files or the folder under Files → Workspace, then try again. Nothing new was saved.',
            default => $wrote
                ? 'File work could not finish. Check Workspace for anything this run already wrote.'
                : 'File work could not finish. Nothing new was saved.',
        };
    }
}
