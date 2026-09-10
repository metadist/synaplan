<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Runner;

use App\Entity\User;
use App\Repository\CustomToolRepository;
use App\Repository\McpServerConfigRepository;
use App\Repository\UserRepository;
use App\Service\Mcp\McpClient;
use App\Service\Mcp\McpClientException;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\TaskRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Multitask\Skill\SkillDescriptor;
use App\Service\SavedTask\Graph\StepInputResolver;
use App\Service\Tool\Custom\HttpToolExecutor;
use App\Service\Tool\Custom\InvalidToolTemplateException;
use App\Service\Tool\Exception\ToolNotRegisteredException;
use App\Service\Tool\Policy\PolicyContext;
use App\Service\Tool\Policy\PolicyOutcome;
use App\Service\Tool\ToolExecutionGate;
use App\Service\Tool\ToolRegistry;
use App\Service\Tool\ToolSource;
use Psr\Log\LoggerInterface;

/**
 * Runs a registered custom or MCP tool from an authored Saved Task step.
 * Hidden from the planner catalog.
 */
final readonly class ToolCallRunner implements TaskRunner
{
    public function __construct(
        private ToolRegistry $registry,
        private StepInputResolver $inputs,
        private HttpToolExecutor $httpExecutor,
        private CustomToolRepository $customTools,
        private McpClient $mcpClient,
        private McpServerConfigRepository $mcpServers,
        private UserRepository $users,
        private LoggerInterface $logger,
        private ?ToolExecutionGate $executionGate = null,
    ) {
    }

    public function supportedCapabilities(): array
    {
        return [Capability::ToolCall];
    }

    /**
     * @return list<SkillDescriptor>
     */
    public function describe(): array
    {
        return [
            new SkillDescriptor(
                Capability::ToolCall,
                'Call a connected tool.',
                available: static fn (): bool => false,
            ),
        ];
    }

    public function run(TaskNode $node, NodeContext $context): NodeResult
    {
        $userId = $context->userId ?? $context->message->getUserId();
        $toolName = is_string($node->params['tool'] ?? null) ? trim($node->params['tool']) : '';
        if ($userId <= 0 || '' === $toolName) {
            return NodeResult::failed('This step needs a tool');
        }

        $descriptor = $this->registry->get((int) $userId, $toolName);
        if (null === $descriptor) {
            return NodeResult::failed((new ToolNotRegisteredException($toolName))->getMessage());
        }

        $rawInputs = is_array($node->params['inputs'] ?? null) ? $node->params['inputs'] : $node->inputs;
        $arguments = $this->inputs->resolveAll($rawInputs, $context);

        $gated = $this->consultGate($context, $node, $toolName, $arguments, (int) $userId);
        if (null !== $gated) {
            return $gated;
        }

        try {
            return match ($descriptor->source) {
                ToolSource::Custom => $this->runCustom($descriptor->meta, $arguments, (int) $userId, $toolName),
                ToolSource::Mcp => $this->runMcp($descriptor->meta, $arguments, (int) $userId, $toolName),
                default => NodeResult::failed('This tool cannot run as a Saved Task step'),
            };
        } catch (ToolNotRegisteredException $e) {
            return NodeResult::failed($e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function consultGate(NodeContext $context, TaskNode $node, string $toolName, array $arguments, int $userId): ?NodeResult
    {
        $override = is_string($node->params['approval'] ?? null) ? $node->params['approval'] : null;
        if (null === $this->executionGate) {
            return 'block' === $override
                ? NodeResult::failed('I cannot do that. This step is set to always ask — and it is blocked.')
                : null;
        }
        if ($context->isApproved($node->id)) {
            return null;
        }
        $actor = $this->users->find($userId);
        if (!$actor instanceof User) {
            return NodeResult::failed('This account cannot run Saved Tasks');
        }
        $runId = is_numeric($context->options['saved_task_run_id'] ?? null) ? (int) $context->options['saved_task_run_id'] : 0;
        $requestedBy = $runId > 0
            ? sprintf('task_run:%d:%s', $runId, $node->id)
            : 'chat:'.(int) $context->message->getId();
        try {
            $decision = $this->executionGate->inspect(
                $userId,
                $toolName,
                $arguments,
                $actor,
                PolicyContext::Unattended,
                $requestedBy,
                null,
                true === ($context->options['allow_unattended'] ?? false),
                null,
                $override,
            );
        } catch (ToolNotRegisteredException $e) {
            return NodeResult::failed($e->getMessage());
        }
        if (PolicyOutcome::Block === $decision['outcome']) {
            return NodeResult::failed((string) $decision['refusal']);
        }
        if (PolicyOutcome::Approve === $decision['outcome'] && null !== $decision['approval']) {
            $approval = $decision['approval'];

            return NodeResult::waitingApproval((int) $approval->getId(), $arguments, [
                'tool' => $approval->getTool(),
                'preview' => $approval->getPreview(),
                'expires_at' => $approval->getExpiresAt(),
                'side_effect' => $approval->getSideEffect(),
            ]);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $arguments
     */
    private function runCustom(array $meta, array $arguments, int $userId, string $toolName): NodeResult
    {
        $toolId = $meta['toolId'] ?? null;
        $tool = is_numeric($toolId) ? $this->customTools->find((int) $toolId) : null;
        if (null === $tool) {
            throw new ToolNotRegisteredException($toolName);
        }
        try {
            $result = $this->httpExecutor->execute($tool, $arguments, $userId);
        } catch (InvalidToolTemplateException $e) {
            $this->logger->info('ToolCallRunner: custom tool failed', [
                'tool' => $toolName,
                'error' => $e->getMessage(),
            ]);

            return NodeResult::failed($e->getMessage());
        }

        return NodeResult::ok($result['summary'], [], [
            'tool' => $toolName,
            'fields' => $result['fields'],
            'summary' => $result['summary'],
        ]);
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $arguments
     */
    private function runMcp(array $meta, array $arguments, int $userId, string $toolName): NodeResult
    {
        $serverId = (int) ($meta['serverId'] ?? 0);
        $mcpTool = is_string($meta['tool'] ?? null) ? $meta['tool'] : '';
        $server = $serverId > 0 ? $this->mcpServers->findByIdAndUser($serverId, $userId) : null;
        if (null === $server || !$server->isEnabled() || '' === $mcpTool) {
            throw new ToolNotRegisteredException($toolName);
        }
        try {
            $call = $this->mcpClient->callTool($server, $mcpTool, $arguments);
        } catch (McpClientException $e) {
            return NodeResult::failed('could not reach the connected system: '.$e->getMessage());
        }
        $text = $this->formatContent($call['content']);
        if ($call['isError']) {
            return NodeResult::failed('the connected system reported an error: '.mb_substr($text, 0, 300));
        }

        return NodeResult::ok('' !== $text ? $text : 'The tool finished', [], [
            'tool' => $toolName,
            'summary' => $text,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $content
     */
    private function formatContent(array $content): string
    {
        $parts = [];
        foreach ($content as $item) {
            if (is_string($item['text'] ?? null) && '' !== $item['text']) {
                $parts[] = $item['text'];
            }
        }

        return trim(implode("\n", $parts));
    }
}
