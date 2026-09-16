<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Approval;
use App\Message\ResumeApprovalCommand;
use App\Message\ResumeSavedTaskRunCommand;
use App\Repository\ApprovalRepository;
use App\Repository\CustomToolRepository;
use App\Repository\McpServerConfigRepository;
use App\Repository\UserRepository;
use App\Service\Mcp\McpClient;
use App\Service\Tool\ApprovalRealtimeNotifier;
use App\Service\Tool\ApprovalReference;
use App\Service\Tool\Custom\HttpToolExecutor;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolRegistry;
use App\Service\Tool\ToolSource;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Runs an approved tool call under the owner's identity.
 *
 * Saved Task runs are handed to {@see ResumeSavedTaskRunCommandHandler}, which
 * re-executes the waiting node inside the DAG. Interactive requests are
 * executed here directly (custom HTTP and MCP tools); the outcome is written to
 * BRESULTREF and published on the owner's realtime channel so the inbox and the
 * chat card can show it. Every failure marks the row `failed` — an approved row
 * must never stay `approved` forever.
 */
#[AsMessageHandler]
final readonly class ResumeApprovalCommandHandler
{
    private const RESULT_PREVIEW_CHARS = 2000;

    public function __construct(
        private ApprovalRepository $approvals,
        private ToolRegistry $registry,
        private UserRepository $users,
        private CustomToolRepository $customTools,
        private HttpToolExecutor $httpExecutor,
        private McpServerConfigRepository $mcpServers,
        private McpClient $mcpClient,
        private ApprovalRealtimeNotifier $realtime,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ResumeApprovalCommand $command): void
    {
        $approval = $this->approvals->find($command->approvalId);
        if (!$approval instanceof Approval || Approval::STATUS_APPROVED !== $approval->getStatus()) {
            return;
        }

        $reference = ApprovalReference::parse($approval->getRequestedBy());
        if (ApprovalReference::KIND_TASK_RUN === $reference->kind && null !== $reference->runId && null !== $reference->nodeId) {
            $this->bus->dispatch(new ResumeSavedTaskRunCommand($reference->runId, $reference->nodeId, (int) $approval->getId()));

            return;
        }

        if (null === $this->users->find($approval->getOwnerId())) {
            $this->fail($approval, 'owner_missing', null);

            return;
        }

        $descriptor = $this->registry->get($approval->getOwnerId(), $approval->getTool());
        if (null === $descriptor) {
            $this->logger->warning('ResumeApproval: tool left the registry', ['tool' => $approval->getTool()]);
            $this->fail($approval, 'tool_not_registered', null);

            return;
        }

        try {
            [$resultRef, $summary] = match ($descriptor->source) {
                ToolSource::Custom => $this->executeCustom($approval, $descriptor),
                ToolSource::Mcp => $this->executeMcp($approval, $descriptor),
                default => ['chat', null],
            };
        } catch (\Throwable $e) {
            $this->logger->warning('ResumeApproval: execution failed', [
                'approval_id' => $approval->getId(),
                'tool' => $approval->getTool(),
                'error' => $e->getMessage(),
            ]);
            $this->fail($approval, 'execution_failed', $e->getMessage());

            return;
        }

        $approval->markExecuted($resultRef);
        $this->approvals->save($approval);
        $this->realtime->executed($approval, $summary);
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function executeCustom(Approval $approval, ToolDescriptor $descriptor): array
    {
        $toolId = $descriptor->meta['toolId'] ?? null;
        $tool = is_numeric($toolId) ? $this->customTools->find((int) $toolId) : null;
        if (null === $tool) {
            throw new \RuntimeException(sprintf('Custom tool %s is no longer available', $approval->getTool()));
        }
        $result = $this->httpExecutor->execute($tool, $approval->getArgs() ?? [], $approval->getOwnerId());

        return ['custom:'.$tool->getId().':'.$result['status'], $this->clip($result['summary'])];
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function executeMcp(Approval $approval, ToolDescriptor $descriptor): array
    {
        $serverId = (int) ($descriptor->meta['serverId'] ?? 0);
        $toolName = (string) ($descriptor->meta['tool'] ?? '');
        $server = $serverId > 0 ? $this->mcpServers->findByIdAndUser($serverId, $approval->getOwnerId()) : null;
        if (null === $server || !$server->isEnabled() || '' === $toolName) {
            throw new \RuntimeException(sprintf('MCP server %d for tool %s is not available', $serverId, $approval->getTool()));
        }
        $call = $this->mcpClient->callTool($server, $toolName, $approval->getArgs() ?? []);
        if ($call['isError']) {
            throw new \RuntimeException(sprintf('MCP tool %s reported an error: %s', $toolName, $this->formatContent($call['content'])));
        }

        return ['mcp:'.$serverId.':'.$toolName, $this->clip($this->formatContent($call['content']))];
    }

    private function fail(Approval $approval, string $reason, ?string $detail): void
    {
        $approval->markFailed($reason);
        $this->approvals->save($approval);
        $this->realtime->executed($approval, null === $detail ? null : $this->clip($detail));
    }

    /**
     * @param list<array<string, mixed>> $content
     */
    private function formatContent(array $content): string
    {
        $parts = [];
        foreach ($content as $block) {
            if ('text' === ($block['type'] ?? null) && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            } elseif (is_array($block['resource'] ?? null) && is_string($block['resource']['text'] ?? null)) {
                $parts[] = $block['resource']['text'];
            }
        }

        return trim(implode("\n\n", $parts));
    }

    private function clip(string $text): ?string
    {
        $text = trim($text);
        if ('' === $text) {
            return null;
        }

        return mb_strlen($text) > self::RESULT_PREVIEW_CHARS
            ? mb_substr($text, 0, self::RESULT_PREVIEW_CHARS).'…'
            : $text;
    }
}
