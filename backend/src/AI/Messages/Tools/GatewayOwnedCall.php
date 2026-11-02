<?php

declare(strict_types=1);

namespace App\AI\Messages\Tools;

use App\Entity\ComputeRun;
use App\Entity\User;
use App\Service\Runtime\RuntimeProfile;
use App\Service\Tool\Exception\ToolNotRegisteredException;
use App\Service\Tool\Execution\BuiltinToolExecutor;
use App\Service\Tool\Execution\CustomHttpToolExecutor;
use App\Service\Tool\Execution\GateMode;
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
use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolSource;
use Psr\Log\LoggerInterface;

/**
 * The owned half of one gateway turn: custom HTTP, built-in tools, and
 * MCP calls. The loop still owns the wire block and the usage row.
 *
 * @phpstan-type DispatchEntry array{kind: string, serverId: int, tool: string, annotations: array<string, mixed>}
 */
final readonly class GatewayOwnedCall
{
    /**
     * @param \Closure(string, string, bool): array<string, mixed> $block
     * @param \Closure(User, int, string, bool): void              $recordMcp
     * @param \Closure(User, string, string, string, bool): void   $recordNative
     */
    public function __construct(
        private LoggerInterface $logger,
        private GatewayOwnedDeps $deps,
        private ToolInvokerSlot $toolInvoker,
        private \Closure $block,
        private \Closure $recordMcp,
        private \Closure $recordNative,
    ) {
    }

    /**
     * @param DispatchEntry           $entry
     * @param array<string, mixed>    $arguments
     * @param (callable(): void)|null $ping      kept off a gate stop, matching the loop's old keep-alive: a ping means the tool call itself ran
     *
     * @return array<string, mixed>
     */
    public function mcp(array $entry, string $name, array $arguments, string $toolUseId, User $user, ?callable $ping = null): array
    {
        $this->assertRegistered($user, $name);
        $outcome = $this->tools()->invoke(
            $this->descriptorFor((int) $user->getId(), $name, $entry),
            $this->invocation($user, $name, $arguments),
        );
        $blocked = $this->blocked($toolUseId, $outcome);
        if (null !== $blocked) {
            return $blocked;
        }
        if (true === ($outcome->details['transport'] ?? false)) {
            $this->logger->warning('GatewayToolLoop: MCP tool call failed', [
                'server_id' => $entry['serverId'],
                'tool' => $entry['tool'],
                'error' => $outcome->resultText,
            ]);
            ($this->recordMcp)($user, $entry['serverId'], $entry['tool'], true);

            return $this->afterRun(($this->block)($toolUseId, 'Tool call failed: '.$outcome->resultText, true), $ping);
        }
        $isError = ToolOutcomeKind::Failed === $outcome->kind;
        ($this->recordMcp)($user, $entry['serverId'], $entry['tool'], $isError);

        return $this->afterRun(($this->block)($toolUseId, $this->present($outcome->resultText), $isError), $ping);
    }

    /**
     * @param DispatchEntry           $entry
     * @param array<string, mixed>    $arguments
     * @param (callable(): void)|null $ping
     *
     * @return array<string, mixed>
     */
    public function custom(array $entry, string $name, array $arguments, string $toolUseId, User $user, ?callable $ping = null): array
    {
        $this->assertRegistered($user, $name);
        $outcome = $this->tools()->invoke(
            $this->descriptorFor((int) $user->getId(), $name, $entry),
            $this->invocation($user, $name, $arguments),
        );
        $blocked = $this->blocked($toolUseId, $outcome);
        if (null !== $blocked) {
            return $blocked;
        }

        return $this->afterRun(($this->block)($toolUseId, $this->present($outcome->resultText), ToolOutcomeKind::Failed === $outcome->kind), $ping);
    }

    /**
     * @param DispatchEntry           $entry
     * @param array<string, mixed>    $arguments
     * @param (callable(): void)|null $ping
     *
     * @return array<string, mixed>
     */
    public function native(array $entry, string $name, array $arguments, string $toolUseId, User $user, WebFetchBudget $pages, ?RuntimeProfile $assistant = null, ?callable $ping = null): array
    {
        $this->assertRegistered($user, $name);
        $tool = $entry['tool'];
        $decideOnly = CodeExecutionTool::NAME === $tool;
        $outcome = $this->tools()->invoke(
            $this->descriptorFor((int) $user->getId(), $name, $entry),
            $this->invocation($user, $name, $arguments, $pages, $assistant, $decideOnly),
        );
        $blocked = $this->blocked($toolUseId, $outcome);
        if (null !== $blocked) {
            return $blocked;
        }
        if ($decideOnly) {
            if (null === $this->deps->codeExecutionTool) {
                return $this->afterRun(($this->block)($toolUseId, sprintf('Unknown Synaplan tool `%s`.', $tool), true), $ping);
            }
            $result = $this->deps->codeExecutionTool->execute(
                $arguments,
                $user,
                ComputeRun::VIA_GATEWAY_ANTHROPIC,
                $assistant,
                null,
                $assistant?->promptId,
            );
            ($this->recordNative)($user, 'COMPUTE_RUNS', $tool, 'file_work', $result['isError']);

            return $this->afterRun(($this->block)($toolUseId, $this->present($result['text']), $result['isError']), $ping);
        }
        $source = $outcome->details['usageSource'] ?? null;
        if (is_string($source)) {
            $input = $outcome->details['usageInput'] ?? '';
            ($this->recordNative)($user, $source, $tool, is_string($input) ? $input : '', ToolOutcomeKind::Failed === $outcome->kind);
        }

        return $this->afterRun(($this->block)($toolUseId, $this->present($outcome->resultText), ToolOutcomeKind::Failed === $outcome->kind), $ping);
    }

    /**
     * @param array<string, mixed>    $block
     * @param (callable(): void)|null $ping
     *
     * @return array<string, mixed>
     */
    private function afterRun(array $block, ?callable $ping): array
    {
        if (null !== $ping) {
            $ping();
        }

        return $block;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function blocked(string $toolUseId, ToolOutcome $outcome): ?array
    {
        if (ToolOutcomeKind::NeedsApproval === $outcome->kind) {
            return ($this->block)($toolUseId, sprintf('requires approval; request #%d created', (int) $outcome->approvalId), false);
        }
        if (ToolOutcomeKind::Refused === $outcome->kind) {
            return ($this->block)($toolUseId, $outcome->resultText, true);
        }

        return null;
    }

    private function assertRegistered(User $user, string $name): void
    {
        $userId = (int) $user->getId();
        if ($this->registryEnabled($userId) && null === $this->deps->toolRegistry?->get($userId, $name)) {
            throw new ToolNotRegisteredException($name);
        }
    }

    /**
     * A disabled registry must not call ToolRegistry::get(): that lists
     * every source, and a cold MCP catalog waits on each server's timeout.
     */
    private function registryEnabled(int $userId): bool
    {
        return null !== $this->deps->toolRegistry
            && null !== $this->deps->toolsConfig
            && $this->deps->toolsConfig->isRegistryEnabled($userId);
    }

    /**
     * @param DispatchEntry $entry
     */
    private function descriptorFor(int $userId, string $name, array $entry): ToolDescriptor
    {
        if ($this->registryEnabled($userId)) {
            $existing = $this->deps->toolRegistry?->get($userId, $name);
            if (null !== $existing) {
                return $existing;
            }
        }
        $source = match ($entry['kind']) {
            GatewayToolCatalog::KIND_CUSTOM => ToolSource::Custom,
            GatewayToolCatalog::KIND_NATIVE => ToolSource::Builtin,
            default => ToolSource::Mcp,
        };
        $toolId = $entry['annotations']['toolId'] ?? null;

        return new ToolDescriptor($name, $entry['tool'], '', [], SideEffect::Read, $source, $userId, null, [
            'serverId' => $entry['serverId'],
            'tool' => $entry['tool'],
            'toolId' => is_numeric($toolId) ? (int) $toolId : null,
        ]);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function invocation(
        User $user,
        string $name,
        array $arguments,
        ?WebFetchBudget $pages = null,
        ?RuntimeProfile $assistant = null,
        bool $decideOnly = false,
    ): ToolInvocation {
        return new ToolInvocation(
            $name,
            $arguments,
            new ToolCaller((int) $user->getId(), ToolChannel::MESSAGES),
            GateMode::AsTodayWhenApprovalsOn,
            decision: new GateRequest($user, PolicyContext::Interactive, 'chat:0'),
            decideOnly: $decideOnly,
            user: $user,
            pages: $pages,
            assistant: $assistant,
        );
    }

    private function tools(): ToolInvoker
    {
        return $this->toolInvoker->get() ?? new ToolInvoker(
            [
                new McpToolExecutor($this->deps->mcpClient, $this->deps->servers),
                new CustomHttpToolExecutor($this->logger, $this->deps->customTools, $this->deps->httpExecutor, $this->deps->toolsConfig),
                new BuiltinToolExecutor($this->deps->nativeTools, $this->deps->analyzeImageTool),
            ],
            gate: $this->deps->executionGate,
            toolsConfig: $this->deps->toolsConfig,
        );
    }

    private function present(string $text): string
    {
        if (mb_strlen($text) > GatewayToolLoop::MAX_TOOL_RESULT_CHARS) {
            $text = mb_substr($text, 0, GatewayToolLoop::MAX_TOOL_RESULT_CHARS).'…';
        }

        return '' !== $text ? $text : '(empty tool result)';
    }
}
