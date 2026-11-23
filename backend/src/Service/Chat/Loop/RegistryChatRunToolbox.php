<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\AI\Messages\Tools\KnowledgeSearchTool;
use App\AI\Messages\Tools\WebFetchBudget;
use App\AI\Messages\Tools\WebFetchTool;
use App\AI\Messages\Tools\WebSearchTool;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\Tool\Execution\GateRequest;
use App\Service\Tool\Execution\ToolCaller;
use App\Service\Tool\Execution\ToolChannel;
use App\Service\Tool\Execution\ToolInvocation;
use App\Service\Tool\Execution\ToolInvoker;
use App\Service\Tool\Execution\ToolOutcomeKind;
use App\Service\Tool\Policy\PolicyContext;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolRegistry;
use App\Service\Tool\ToolsConfig;
use App\Service\Tool\ToolSource;

/**
 * The tools of a chat run.
 *
 * From the registry: web search, reading a page, and the person's own HTTP
 * and MCP tools, each through {@see ToolInvoker}, so the approval policy
 * applies as everywhere else. The person's own tools are declared by name
 * only until the model opens them ({@see ConnectedToolCatalog}). Tools that read their folder or file from the
 * HTTP request, document tools and server-side code are not offered: a run
 * has no request. The project's sources are searched by the loop itself, in
 * the folder resolved for this unit, and only while the chat is in it.
 */
final readonly class RegistryChatRunToolbox implements ChatRunToolbox
{
    public const NOT_AVAILABLE = 'This tool is not available in this chat.';
    public const ALREADY_RAN = 'This action already ran earlier in this run, but its result was lost. Do not run it again; tell the person to check the outcome.';
    public const SOURCES_DESCRIPTION = 'Search the sources of this chat\'s project. Returns the file name, file id, chunk id, score and text.';

    private const BUILTINS = [WebSearchTool::NAME, WebFetchTool::NAME];
    private const MAX_NAME_LENGTH = 64;
    /** The loop answers these itself; a registry tool of the same name would be declared twice. */
    private const RESERVED = [KnowledgeSearchTool::NAME, ConnectedToolCatalog::NAME, ChatLoopTools::UPDATE_PLAN, ChatLoopTools::FINISH];
    private const READS = [WebSearchTool::NAME, WebFetchTool::NAME, KnowledgeSearchTool::NAME, ConnectedToolCatalog::NAME];

    public function __construct(
        private ToolRegistry $registry,
        private ToolsConfig $toolsConfig,
        private ToolInvoker $invoker,
        private KnowledgeSearchTool $knowledge,
        private MessagesGatewayConfig $gatewayConfig,
        private ChatRunApprovals $approvals,
    ) {
    }

    public function declarations(ChatRunScope $scope, array $opened = []): array
    {
        $out = [];
        $connected = false;
        foreach ($this->offered($scope) as $wire => $descriptor) {
            if (ToolSource::Builtin !== $descriptor->source) {
                $connected = true;
                if (!\in_array($wire, $opened, true)) {
                    continue;
                }
            }
            $out[] = self::fn($wire, $descriptor->description, $descriptor->inputSchema);
        }
        if ($connected) {
            $out[] = ConnectedToolCatalog::declaration();
        }
        if (null !== $scope->project) {
            $declaration = $this->knowledge->declaration();
            $out[] = self::fn(KnowledgeSearchTool::NAME, self::SOURCES_DESCRIPTION, $declaration['input_schema']);
        }

        return $out;
    }

    public function run(ChatRunScope $scope, ChatRunState $state, array $call, ?int $approvalId = null): ChatToolResult
    {
        if (KnowledgeSearchTool::NAME === $call['name']) {
            return $this->sources($scope, $call['args']);
        }
        if (ConnectedToolCatalog::NAME === $call['name']) {
            $connected = array_filter($this->offered($scope), static fn (ToolDescriptor $d): bool => ToolSource::Builtin !== $d->source);

            return ChatToolResult::text(ConnectedToolCatalog::run($connected, $state, $call['args']));
        }
        $descriptor = $this->find($scope, $call['name']);
        if (null === $descriptor) {
            return ChatToolResult::failed(self::NOT_AVAILABLE);
        }
        $args = $call['args'];
        if (null !== $approvalId) {
            $approved = $this->approvals->approvedArgs($approvalId, $state, $descriptor->name);
            if (null === $approved) {
                return ChatToolResult::text($this->approvals->alreadyRan($approvalId, $state) ? self::ALREADY_RAN : ChatRunStep::DECLINED);
            }
            $args = $approved;
        }
        $requestedBy = ChatRunApprovals::requestedBy($state->runId);
        $outcome = $this->invoker->invoke($descriptor, new ToolInvocation(
            $descriptor->name,
            $args,
            new ToolCaller($state->userId, ToolChannel::CHAT, requestId: $requestedBy, approvalId: $approvalId),
            recordUsage: true,
            decision: new GateRequest($scope->user, PolicyContext::Interactive, $requestedBy, alreadyApproved: null !== $approvalId),
            user: $scope->user,
            pages: new WebFetchBudget($this->gatewayConfig->webFetchMaxPerTurn($state->userId)),
        ));
        if (null !== $approvalId) {
            $this->approvals->executed($approvalId, ToolOutcomeKind::Done === $outcome->kind);
        }
        if (ToolOutcomeKind::NeedsApproval === $outcome->kind && null !== $outcome->approvalId && null === $approvalId) {
            return ChatToolResult::waitsFor($outcome->approvalId);
        }

        $text = '' !== $outcome->resultText ? $outcome->resultText : 'The tool returned nothing.';

        return ToolOutcomeKind::Done === $outcome->kind ? ChatToolResult::text($text) : ChatToolResult::failed($text);
    }

    public function isRead(string $name): bool
    {
        return \in_array($name, self::READS, true);
    }

    /**
     * @param array<string, mixed> $args
     */
    private function sources(ChatRunScope $scope, array $args): ChatToolResult
    {
        if (null === $scope->project) {
            return ChatToolResult::text(self::NOT_AVAILABLE);
        }
        $result = $this->knowledge->executeInFolder($args, $scope->user, $scope->project->groupKey);

        return ChatToolResult::text($result['text']);
    }

    private function find(ChatRunScope $scope, string $name): ?ToolDescriptor
    {
        return $this->offered($scope)[$name] ?? null;
    }

    /**
     * The offered tools by the name the model calls them. A provider takes
     * only letters, digits, `_` and `-`, so `custom:order` becomes
     * `custom_order`; the first tool keeps a name two would share.
     *
     * @return array<string, ToolDescriptor>
     */
    private function offered(ChatRunScope $scope): array
    {
        $userId = (int) $scope->user->getId();
        if (!$this->toolsConfig->isRegistryEnabled($userId)) {
            return [];
        }
        $out = [];
        foreach ($this->registry->forUser($userId) as $descriptor) {
            $wire = self::wire($descriptor->callName());
            if (self::allowed($descriptor) && !\in_array($wire, self::RESERVED, true) && !isset($out[$wire])) {
                $out[$wire] = $descriptor;
            }
        }

        return $out;
    }

    private static function wire(string $name): string
    {
        return substr((string) preg_replace('/[^a-zA-Z0-9_-]/', '_', $name), 0, self::MAX_NAME_LENGTH);
    }

    private static function allowed(ToolDescriptor $descriptor): bool
    {
        if (false === ($descriptor->meta['available'] ?? true)) {
            return false;
        }

        return match ($descriptor->source) {
            ToolSource::Builtin => \in_array($descriptor->name, self::BUILTINS, true),
            ToolSource::Custom, ToolSource::Mcp => true,
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>
     */
    private static function fn(string $name, string $description, array $parameters): array
    {
        return [
            'type' => 'function',
            'function' => ['name' => $name, 'description' => $description, 'parameters' => $parameters],
        ];
    }
}
