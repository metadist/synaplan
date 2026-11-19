<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\AI\Service\AiFacade;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Chat\ProviderTranscriptCapture;
use App\Service\Document\Tool\DocumentSession;
use App\Service\Document\Tool\DocumentToolRegistry;
use App\Service\Document\Tool\DocumentToolResult;
use App\Service\Tool\Exception\ToolNotRegisteredException;
use App\Service\Tool\Execution\GateRequest;
use App\Service\Tool\Execution\ToolCaller;
use App\Service\Tool\Execution\ToolInvocation;
use App\Service\Tool\Execution\ToolInvoker;
use App\Service\Tool\Execution\ToolLimits;
use App\Service\Tool\Loop\AgentToolLoop;
use App\Service\Tool\Loop\ToolLoopLimits;
use App\Service\Tool\Policy\PolicyContext;
use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolRegistry;
use App\Service\Tool\ToolsConfig;
use App\Service\Tool\ToolSource;

/**
 * Multi-round document tool loop on ChatProviderInterface / AiFacade.
 *
 * Runs on {@see AgentToolLoop} with the default limits; the wire side is
 * {@see DocumentToolLoopAdapter}. Tools never abort the loop. Intermediate
 * provider rounds are not streamed; callers get
 * {@see ChatToolLoopResult} plus optional per-step callbacks.
 */
final readonly class ChatToolLoop
{
    public function __construct(
        private AiFacade $aiFacade,
        private DocumentToolRegistry $registry,
        private DocumentToolsConfig $config,
        private ToolInvoker $invoker,
        private ?ToolRegistry $toolRegistry = null,
        private ?ToolsConfig $toolsConfig = null,
        private ?ProviderTranscriptCapture $transcripts = null,
        private ?UserRepository $users = null,
        private AgentToolLoop $loop = new AgentToolLoop(),
    ) {
    }

    /**
     * @param list<array<string, mixed>>                   $messages
     * @param array<string, mixed>                         $options
     * @param callable(DocumentToolResult, int): void|null $onStep
     */
    public function run(
        array $messages,
        DocumentSession $session,
        array $options,
        ?int $userId = null,
        ?callable $onStep = null,
    ): ChatToolLoopResult {
        $options['tools'] = $this->resolveTools($session->kind(), $userId);
        $options['tool_choice'] = $options['tool_choice'] ?? 'auto';
        $options['usage_turn_id'] = $options['usage_turn_id'] ?? ('doc-loop-'.bin2hex(random_bytes(8)));

        $adapter = new DocumentToolLoopAdapter(
            $this->aiFacade,
            $this->transcripts,
            $messages,
            $options,
            $userId,
            $this->config->maxOpsPerTurn(),
            fn (array $call): array => $this->callTool($call, $session, $userId),
            null !== $onStep ? $onStep(...) : null,
        );
        $this->loop->run(ToolLoopLimits::defaults($this->config->maxIterations()), $adapter);

        return $adapter->result($session);
    }

    /**
     * Runs one document tool call. A failure is the tool result, not a throw.
     *
     * @param array<string, mixed> $call
     *
     * @return array{0: DocumentToolResult, 1: string}
     */
    private function callTool(array $call, DocumentSession $session, ?int $userId): array
    {
        $name = (string) ($call['function']['name'] ?? $call['name'] ?? '');
        $rawArgs = $call['function']['arguments'] ?? $call['arguments'] ?? '{}';
        $args = is_array($rawArgs) ? $rawArgs : (json_decode((string) $rawArgs, true) ?: []);
        if (!is_array($args)) {
            $args = [];
        }
        $tool = $this->registry->get($name);
        if (null === $tool || !in_array($session->kind(), $tool->appliesTo(), true)) {
            if (null !== $this->toolRegistry && null !== $this->toolsConfig && null !== $userId && $this->toolsConfig->isRegistryEnabled($userId)) {
                throw new ToolNotRegisteredException($name);
            }
            $result = DocumentToolResult::error('Unknown tool '.$name, 'processing.documentStepUnknownTool', ['name' => $name]);
            $session->record($result);

            return [$result, ToolLimits::clamp($result->message)];
        }

        $actor = null !== $userId && null !== $this->users ? $this->users->find($userId) : null;
        $registered = null !== $this->toolRegistry && null !== $userId
            ? $this->toolRegistry->get($userId, $name, ['documentKind' => $session->kind()])
            : null;
        $outcome = $this->invoker->invoke(
            $registered ?? new ToolDescriptor($name, $name, '', [], SideEffect::Write, ToolSource::Document, $userId ?? 0, ToolDescriptor::POLICY_OWN_ARTEFACT),
            new ToolInvocation(
                $name,
                $args,
                new ToolCaller($userId, 'chat', document: $session),
                decision: $actor instanceof User ? new GateRequest($actor, PolicyContext::Interactive, 'chat:0') : null,
                user: $actor instanceof User ? $actor : null,
            ),
        );

        return [
            $outcome->document ?? DocumentToolResult::error($outcome->resultText, 'processing.documentStepFailed', ['name' => $name]),
            $outcome->resultText,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resolveTools(string $kind, ?int $userId): array
    {
        if (null === $this->toolRegistry || null === $this->toolsConfig || null === $userId || !$this->toolsConfig->isRegistryEnabled($userId)) {
            return $this->registry->declarationsFor($kind);
        }

        $tools = [];
        foreach ($this->toolRegistry->forUser($userId, ['documentKind' => $kind]) as $descriptor) {
            if ('document' !== $descriptor->source->value) {
                continue;
            }
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $descriptor->name,
                    'description' => $descriptor->description,
                    'parameters' => $descriptor->inputSchema,
                ],
            ];
        }

        return $tools;
    }
}
