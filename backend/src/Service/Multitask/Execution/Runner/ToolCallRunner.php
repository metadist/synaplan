<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Runner;

use App\Entity\CustomTool;
use App\Entity\User;
use App\Repository\CustomToolRepository;
use App\Repository\McpServerConfigRepository;
use App\Repository\UserRepository;
use App\Service\Mcp\McpClient;
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
use App\Service\Tool\Execution\CustomHttpToolExecutor;
use App\Service\Tool\Execution\GateRequest;
use App\Service\Tool\Execution\McpToolExecutor;
use App\Service\Tool\Execution\ToolCaller;
use App\Service\Tool\Execution\ToolChannel;
use App\Service\Tool\Execution\ToolInvocation;
use App\Service\Tool\Execution\ToolInvoker;
use App\Service\Tool\Execution\ToolInvokerSlot;
use App\Service\Tool\Execution\ToolOutcome;
use App\Service\Tool\Execution\ToolOutcomeKind;
use App\Service\Tool\Policy\PolicyContext;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolExecutionGate;
use App\Service\Tool\ToolRegistry;
use App\Service\Tool\ToolsConfig;
use App\Service\Tool\ToolSource;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Runs a registered custom or MCP tool from an authored Saved Task step
 * or a planner `tool_call` when the user has custom HTTP tools.
 */
final readonly class ToolCallRunner implements TaskRunner
{
    private ToolInvokerSlot $toolInvoker;

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
        private ?ToolsConfig $toolsConfig = null,
    ) {
        $this->toolInvoker = new ToolInvokerSlot();
    }

    #[Required]
    public function setToolInvoker(ToolInvoker $toolInvoker): void
    {
        $this->toolInvoker->set($toolInvoker);
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
                'Call one of the user\'s custom tools (HTTP APIs they connected). ONLY when the user asks to use that tool. Set params.tool to a name from the list below. Never invent a name.',
                dynamicNote: fn (?int $userId, array $context): ?string => $this->renderCustomTools($userId),
                requiresDynamicNote: true,
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
        if (ToolSource::Custom === $descriptor->source && !$this->customHttpAllowed((int) $userId)) {
            return NodeResult::failed('Custom tools are turned off');
        }

        $rawInputs = is_array($node->params['inputs'] ?? null) ? $node->params['inputs'] : $node->inputs;
        $arguments = $this->inputs->resolveAll($rawInputs, $context);
        $approved = $context->isApproved($node->id);
        $actor = $this->users->find((int) $userId);
        if (!$approved && null !== $this->executionGate && !$actor instanceof User) {
            return NodeResult::failed('This account cannot run Saved Tasks');
        }

        $override = is_string($node->params['approval'] ?? null) ? $node->params['approval'] : null;
        $runId = is_numeric($context->options['saved_task_run_id'] ?? null) ? (int) $context->options['saved_task_run_id'] : 0;
        $requestedBy = $runId > 0
            ? sprintf('task_run:%d:%s', $runId, $node->id)
            : 'chat:'.(int) $context->message->getId();
        try {
            $outcome = $this->tools()->invoke(
                $descriptor,
                new ToolInvocation(
                    $toolName,
                    $arguments,
                    new ToolCaller((int) $userId, ToolChannel::SAVED_TASK, unattended: true),
                    decision: new GateRequest(
                        $actor instanceof User ? $actor : null,
                        PolicyContext::Unattended,
                        $requestedBy,
                        allowUnattended: true === ($context->options['allow_unattended'] ?? false),
                        nodeApproval: $override,
                        resolvedRequest: $actor instanceof User ? $this->resolvedRequest((int) $userId, $toolName, $arguments) : null,
                        alreadyApproved: $approved,
                    ),
                    user: $actor instanceof User ? $actor : null,
                    decideOnly: ToolSource::Custom !== $descriptor->source && ToolSource::Mcp !== $descriptor->source,
                ),
            );
        } catch (ToolNotRegisteredException $e) {
            return NodeResult::failed($e->getMessage());
        }

        return $this->toResult($descriptor, $toolName, $arguments, $outcome);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function toResult(ToolDescriptor $descriptor, string $toolName, array $arguments, ToolOutcome $outcome): NodeResult
    {
        if (ToolOutcomeKind::NeedsApproval === $outcome->kind && null !== $outcome->approval) {
            $approval = $outcome->approval;

            return NodeResult::waitingApproval((int) $approval->getId(), $arguments, [
                'tool' => $approval->getTool(),
                'query' => $approval->getTool(),
                'preview' => $approval->getPreview(),
                'expires_at' => $approval->getExpiresAt(),
                'side_effect' => $approval->getSideEffect(),
            ]);
        }
        if (ToolOutcomeKind::Refused === $outcome->kind || (ToolOutcomeKind::Failed === $outcome->kind && true === ($outcome->details['missing'] ?? false))) {
            return NodeResult::failed($outcome->resultText);
        }
        if (ToolSource::Custom !== $descriptor->source && ToolSource::Mcp !== $descriptor->source) {
            return NodeResult::failed('This tool cannot run as a Saved Task step');
        }
        if (true === ($outcome->details['unavailable'] ?? false)) {
            return NodeResult::failed((new ToolNotRegisteredException($toolName))->getMessage());
        }
        if (true === ($outcome->details['transport'] ?? false)) {
            return NodeResult::failed('could not reach the connected system: '.$outcome->resultText);
        }
        if (ToolOutcomeKind::Failed === $outcome->kind && ToolSource::Custom === $descriptor->source) {
            return NodeResult::failed($outcome->resultText);
        }
        if (true === ($outcome->details['toolError'] ?? false)) {
            return NodeResult::failed('the connected system reported an error: '.mb_substr($this->mcpNodeText($outcome), 0, 300));
        }
        if (ToolSource::Custom === $descriptor->source) {
            $summary = $outcome->details['summary'] ?? $outcome->resultText;

            return NodeResult::ok(is_string($summary) ? $summary : $outcome->resultText, [], [
                'tool' => $toolName,
                'query' => $toolName,
                'fields' => is_array($outcome->details['fields'] ?? null) ? $outcome->details['fields'] : [],
                'summary' => is_string($summary) ? $summary : $outcome->resultText,
            ]);
        }

        $text = $this->mcpNodeText($outcome);

        return NodeResult::ok('' !== $text ? $text : 'The tool finished', [], [
            'tool' => $toolName,
            'summary' => $text,
        ]);
    }

    /**
     * The node output is the full tool text. The invoker clamps model-facing
     * text to {@see \App\Service\Tool\Execution\ToolLimits::RESULT_CHARS};
     * a later Saved Task step still needs the complete content.
     */
    private function mcpNodeText(ToolOutcome $outcome): string
    {
        $content = $outcome->details['content'] ?? null;
        if (!is_array($content)) {
            return $outcome->resultText;
        }
        $parts = [];
        foreach ($content as $item) {
            if (!is_array($item)) {
                continue;
            }
            if ('text' === ($item['type'] ?? null) && is_string($item['text'] ?? null) && '' !== $item['text']) {
                $parts[] = $item['text'];
            }
        }

        return trim(implode("\n", $parts));
    }

    private function tools(): ToolInvoker
    {
        return $this->toolInvoker->get() ?? new ToolInvoker(
            [
                new McpToolExecutor($this->mcpClient, $this->mcpServers),
                new CustomHttpToolExecutor($this->logger, $this->customTools, $this->httpExecutor, $this->toolsConfig),
            ],
            gate: $this->executionGate,
        );
    }

    /**
     * The request that will be sent, with the credential replaced. Stored on
     * the approval so the card shows the request, not a paraphrase.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{method: string, url: string, headers: array<string, string>, body: string|null}|null
     */
    private function resolvedRequest(int $userId, string $toolName, array $arguments): ?array
    {
        $descriptor = $this->registry->get($userId, $toolName);
        if (null === $descriptor || ToolSource::Custom !== $descriptor->source) {
            return null;
        }
        $toolId = $descriptor->meta['toolId'] ?? null;
        $tool = is_numeric($toolId) ? $this->customTools->find((int) $toolId) : null;
        if (!$tool instanceof CustomTool) {
            return null;
        }
        try {
            return $this->httpExecutor->resolve($tool, $arguments, false);
        } catch (InvalidToolTemplateException) {
            return null;
        }
    }

    /**
     * Planner-facing list of this user's custom HTTP tools. Hidden while the
     * runner is constructed without DI (characterization catalog) or the
     * account has none — the planner must not invent a tool_call (issue #1884).
     */
    private function renderCustomTools(?int $userId): ?string
    {
        if (!(new \ReflectionProperty($this, 'registry'))->isInitialized($this)) {
            return null;
        }
        if (null === $userId || $userId < 1 || !$this->customHttpAllowed($userId)) {
            return null;
        }

        $lines = [];
        foreach ($this->registry->forUser($userId) as $descriptor) {
            if (ToolSource::Custom !== $descriptor->source) {
                continue;
            }
            $label = '' !== $descriptor->title ? $descriptor->title : $descriptor->name;
            $lines[] = sprintf('  - params.tool=%s (%s, %s)', $descriptor->name, $label, $descriptor->sideEffect->value);
        }
        if ([] === $lines) {
            return null;
        }

        return "  Custom tools:\n".implode("\n", $lines);
    }

    /**
     * TOOLS.REGISTRY_ENABLED is the kill switch that restores the pre-registry
     * catalogs. Custom HTTP also has its own flag; both must be on.
     */
    private function customHttpAllowed(int $userId): bool
    {
        $prop = new \ReflectionProperty($this, 'toolsConfig');
        if (!$prop->isInitialized($this) || null === $this->toolsConfig) {
            return true;
        }

        return $this->toolsConfig->isRegistryEnabled($userId)
            && $this->toolsConfig->isCustomHttpEnabled($userId);
    }
}
