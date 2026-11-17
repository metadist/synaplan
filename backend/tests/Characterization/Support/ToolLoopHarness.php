<?php

declare(strict_types=1);

namespace App\Tests\Characterization\Support;

use App\AI\Messages\Mcp\McpToolCatalogAdapter;
use App\AI\Messages\Tools\AnalyzeImageTool;
use App\AI\Messages\Tools\CodeExecutionTool;
use App\AI\Messages\Tools\GatewayToolCatalog;
use App\AI\Messages\Tools\GatewayToolLoop;
use App\AI\Messages\Tools\NativeToolRegistry;
use App\AI\Messages\Tools\WebFetchTool;
use App\AI\Messages\Tools\WebSearchTool;
use App\AI\OpenAI\OpenAiGatewayToolLoop;
use App\AI\Service\AiFacade;
use App\DTO\UserMemoryDTO;
use App\Entity\Approval;
use App\Entity\AuditLogEntry;
use App\Entity\CustomTool;
use App\Entity\File;
use App\Entity\McpServerConfig;
use App\Entity\Message;
use App\Entity\User;
use App\Mcp\McpServerFactory;
use App\Message\ResumeApprovalCommand;
use App\MessageHandler\ResumeApprovalCommandHandler;
use App\Observability\EventRingStore;
use App\Repository\ApprovalRepository;
use App\Repository\AuditLogEntryRepository;
use App\Repository\ChatRepository;
use App\Repository\ComputeRunRepository;
use App\Repository\CustomToolRepository;
use App\Repository\DesktopDeviceRepository;
use App\Repository\FileRepository;
use App\Repository\McpServerConfigRepository;
use App\Repository\MessageRepository;
use App\Repository\PromptRepository;
use App\Repository\UserRepository;
use App\Service\Chat\ConversationSummaryRefreshDispatcher;
use App\Service\Compute\ComputeArtefactStore;
use App\Service\Compute\ComputeClient;
use App\Service\Compute\ComputeConfig;
use App\Service\Compute\ComputeEgressResolver;
use App\Service\Compute\Contract\ComputeRunStatus;
use App\Service\Desktop\DesktopAgentConfig;
use App\Service\Desktop\DesktopJobResultNotifier;
use App\Service\Desktop\DesktopJobStore;
use App\Service\Document\ChatToolLoop;
use App\Service\Document\DocumentToolsConfig;
use App\Service\Document\Tool\DocumentSession;
use App\Service\Document\Tool\DocumentToolRegistry;
use App\Service\Document\Tool\SetCellsTool;
use App\Service\File\FileStorageService;
use App\Service\File\VectorizationService;
use App\Service\Iam\AuditLogWriter;
use App\Service\Mcp\AtlassianToolArguments;
use App\Service\Mcp\McpClient;
use App\Service\Mcp\McpClientConfig;
use App\Service\Mcp\McpToolRegistry;
use App\Service\Mcp\Notion\NotionFetchArguments;
use App\Service\Mcp\Notion\NotionImageAttach;
use App\Service\Memory\UserMemoryService;
use App\Service\Message\MessageProcessor;
use App\Service\MessagesGateway\MessagesGatewayConfig;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\NodeStatus;
use App\Service\Multitask\Execution\Runner\CodeRunRunner;
use App\Service\Multitask\Execution\Runner\McpActionRunner;
use App\Service\Multitask\Execution\Runner\McpFetchRunner;
use App\Service\Multitask\Execution\Runner\ToolCallRunner;
use App\Service\Multitask\MultitaskRoutingConfig;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Prompt\PromptService;
use App\Service\RAG\VectorSearchService;
use App\Service\RateLimitService;
use App\Service\SavedTask\Graph\StepInputResolver;
use App\Service\Security\SsrfGuard;
use App\Service\Tool\ApprovalArgsRedactor;
use App\Service\Tool\ApprovalRealtimeNotifier;
use App\Service\Tool\ApprovalService;
use App\Service\Tool\ChatApprovalContinuationService;
use App\Service\Tool\Custom\HttpToolExecutor;
use App\Service\Tool\Exception\ToolNotRegisteredException;
use App\Service\Tool\Policy\ApprovalPolicy;
use App\Service\Tool\Policy\PolicyContext;
use App\Service\Tool\Policy\PolicyOutcome;
use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolExecutionGate;
use App\Service\Tool\ToolRegistry;
use App\Service\Tool\ToolsConfig;
use App\Service\Tool\ToolSource;
use App\Service\Usage\RecordedUsage;
use App\Service\Usage\StorageQuotaService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Drives every tool loop and runner on the untouched code and records the
 * gate, the approval rows, the audit rows and the usage rows.
 *
 * The doubles stand in for the model, the MCP server and the compute sidecar.
 * The gate, the approval policy and the loops are the real classes.
 */
final class ToolLoopHarness
{
    private const USER_ID = 7;
    private const RUN_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    private ToolLoopKnobs $knobs;
    private ToolExecutionGate $gate;

    /** @var list<array{tool: string, outcome: string, approval: int|null}> */
    private array $gateDecisions = [];
    private ScriptedMessagesTranslator $translator;
    private ScriptedChatModel $documentModel;
    private ScriptedChatModel $openaiModel;
    private User $user;
    private Message $message;
    private McpServerConfig $server;
    private string $facadeMode = 'openai';

    /** @var list<string> */
    private array $executed = [];

    /** @var list<array<string, mixed>> */
    private array $usage = [];

    /** @var array<int, array<string, mixed>> */
    private array $approvals = [];

    /** @var list<array<string, mixed>> */
    private array $audit = [];

    private int $nextApprovalId = 0;

    /** @var array<string, mixed> */
    private array $catalog = ['tools' => [], 'dispatch' => []];

    private GatewayToolLoop $gateway;
    private OpenAiGatewayToolLoop $openai;
    private ChatToolLoop $chat;
    private ToolCallRunner $toolCall;
    private McpFetchRunner $mcpFetch;
    private McpActionRunner $mcpAction;
    private CodeRunRunner $codeRun;
    private ResumeApprovalCommandHandler $resume;
    private McpServerFactory $mcpServer;

    /** @var array<string, array<string, mixed>> */
    private array $cases = [];

    /** @var callable(class-string): object */
    private $mock;

    private ?Approval $pendingResume = null;

    /** @param callable(class-string): object $mock */
    public function __construct(callable $mock)
    {
        $this->mock = $mock;
        $this->knobs = new ToolLoopKnobs();
        $this->translator = new ScriptedMessagesTranslator();
        $this->documentModel = new ScriptedChatModel();
        $this->openaiModel = new ScriptedChatModel();
        $this->user = $this->makeUser();
        $this->message = $this->makeMessage();
        $this->server = $this->makeServer();
        $this->boot();
    }

    /**
     * @return array{bounds: array<string, mixed>, cases: array<string, array<string, mixed>>}
     */
    public function runAll(): array
    {
        $this->chatCases();
        $this->modelLoopCases();
        $this->runnerCases();
        $this->resumeCases();
        $this->mcpServerCases();

        return ['bounds' => $this->bounds(), 'cases' => $this->cases];
    }

    private function chatCases(): void
    {
        $edit = [[
            'name' => 'set_cells',
            'arguments' => ['sheet' => 'Sheet1', 'cells' => [['address' => 'A1', 'value' => 'Hi']]],
        ]];
        $this->runChat('chat.document_edit.approvals_off', false, $edit);
        $this->runChat('chat.document_edit.approvals_on', true, $edit);
        $this->runChat('chat.document_edit.clamp', false, [[
            'name' => LongEchoDocumentTool::NAME,
            'arguments' => [],
        ]]);
    }

    /**
     * @param list<array{name: string, arguments: array<string, mixed>}> $tools
     */
    private function runChat(string $id, bool $approvals, array $tools): void
    {
        $this->reset($approvals);
        $this->facadeMode = 'document';
        $this->documentModel->toolCalls = $tools;
        $this->documentModel->finalText = 'Updated the sheet.';
        $session = DocumentSession::empty('xlsx');
        $result = $this->chat->run(
            [['role' => 'user', 'content' => 'Put Hi in A1']],
            $session,
            ['provider' => 'test', 'model' => 'test'],
            self::USER_ID,
        );
        $cell = $session->spreadsheet()?->sheet('Sheet1')?->getCell('A1');
        $this->put($id, [
            'stop' => 'done',
            'result' => [
                'assistant' => $result->content,
                'tool_results' => $this->documentModel->toolResults,
                'mutated' => $session->hasMutations(),
                'a1' => $cell?->value,
            ],
        ], array_map(static fn (array $tool): string => $tool['name'], $tools));
    }

    private function modelLoopCases(): void
    {
        $read = $this->mcpRead();
        $write = $this->mcpWrite();
        $search = $this->native('web_search', ['query' => 'euro']);
        $image = $this->native('analyze_image', ['file_id' => 4]);
        $code = $this->native('code_execution', ['language' => 'python', 'code' => 'print(1)']);
        $custom = $this->customCall();
        $client = $this->clientCall();
        $final = 'Done. https://example.test/rates';

        foreach ([
            ['gateway_complete.mcp_read.approvals_off', 'gateway', 'complete', false, [$read], 8, false],
            ['gateway_complete.mcp_read.approvals_on', 'gateway', 'complete', true, [$read], 8, false],
            ['gateway_complete.mcp_read.clamp', 'gateway', 'complete', false, [$this->mcpRead('clamp')], 8, false],
            ['gateway_complete.mcp_write.approvals_off', 'gateway', 'complete', false, [$write], 8, false],
            ['gateway_complete.mcp_write.approvals_on', 'gateway', 'complete', true, [$write], 8, false],
            ['gateway_complete.web_search.approvals_off', 'gateway', 'complete', false, [$search], 8, false],
            ['gateway_complete.web_search.approvals_on', 'gateway', 'complete', true, [$search], 8, false],
            ['gateway_complete.image.approvals_off', 'gateway', 'complete', false, [$image], 8, false],
            ['gateway_complete.image.approvals_on', 'gateway', 'complete', true, [$image], 8, false],
            ['gateway_complete.code.approvals_off', 'gateway', 'complete', false, [$code], 8, false],
            ['gateway_complete.code.approvals_on', 'gateway', 'complete', true, [$code], 8, false],
            ['gateway_complete.custom_http.approvals_off', 'gateway', 'complete', false, [$custom], 8, false],
            ['gateway_complete.custom_http.approvals_on', 'gateway', 'complete', true, [$custom], 8, false],
            ['gateway_complete.client_tool', 'gateway', 'complete', false, [$client], 8, false],
            ['gateway_complete.mix', 'gateway', 'complete', false, [$read, $client], 8, false],
            ['gateway_complete.iterations', 'gateway', 'complete', false, [$read], 1, true],
            ['gateway_stream.mcp_read.approvals_off', 'gateway', 'stream', false, [$read], 8, false],
            ['gateway_stream.mcp_read.approvals_on', 'gateway', 'stream', true, [$read], 8, false],
            ['gateway_stream.mcp_write.approvals_off', 'gateway', 'stream', false, [$write], 8, false],
            ['gateway_stream.mcp_write.approvals_on', 'gateway', 'stream', true, [$write], 8, false],
            ['gateway_stream.web_search.approvals_off', 'gateway', 'stream', false, [$search], 8, false],
            ['gateway_stream.image.approvals_off', 'gateway', 'stream', false, [$image], 8, false],
            ['gateway_stream.code.approvals_off', 'gateway', 'stream', false, [$code], 8, false],
            ['gateway_stream.custom_http.approvals_off', 'gateway', 'stream', false, [$custom], 8, false],
            ['gateway_stream.client_tool', 'gateway', 'stream', false, [$client], 8, false],
            ['gateway_stream.mix', 'gateway', 'stream', false, [$read, $client], 8, false],
            ['openai_complete.mcp_read.approvals_off', 'openai', 'complete', false, [$read], 8, false],
            ['openai_complete.mcp_read.approvals_on', 'openai', 'complete', true, [$read], 8, false],
            ['openai_complete.mcp_write.approvals_off', 'openai', 'complete', false, [$write], 8, false],
            ['openai_complete.mcp_write.approvals_on', 'openai', 'complete', true, [$write], 8, false],
            ['openai_complete.web_search.approvals_off', 'openai', 'complete', false, [$search], 8, false],
            ['openai_complete.web_search.approvals_on', 'openai', 'complete', true, [$search], 8, false],
            ['openai_complete.image.approvals_off', 'openai', 'complete', false, [$image], 8, false],
            ['openai_complete.image.approvals_on', 'openai', 'complete', true, [$image], 8, false],
            ['openai_complete.code.approvals_off', 'openai', 'complete', false, [$code], 8, false],
            ['openai_complete.code.approvals_on', 'openai', 'complete', true, [$code], 8, false],
            ['openai_complete.custom_http', 'openai', 'complete', false, [$custom], 8, false],
            ['openai_complete.client_tool', 'openai', 'complete', false, [$client], 8, false],
            ['openai_complete.mix', 'openai', 'complete', false, [$read, $client], 8, false],
            ['openai_complete.iterations', 'openai', 'complete', false, [$read], 1, true],
            ['openai_stream.mcp_read.approvals_off', 'openai', 'stream', false, [$read], 8, false],
            ['openai_stream.mcp_read.approvals_on', 'openai', 'stream', true, [$read], 8, false],
            ['openai_stream.mcp_write.approvals_on', 'openai', 'stream', true, [$write], 8, false],
            ['openai_stream.web_search.approvals_off', 'openai', 'stream', false, [$search], 8, false],
            ['openai_stream.client_tool', 'openai', 'stream', false, [$client], 8, false],
            ['openai_stream.mix', 'openai', 'stream', false, [$read, $client], 8, false],
        ] as [$id, $channel, $mode, $approvals, $calls, $max, $repeat]) {
            $this->runModelLoop($id, $channel, $mode, $approvals, $calls, $max, $repeat, $final);
        }
    }

    /**
     * @param list<array<string, mixed>> $calls
     */
    private function runModelLoop(
        string $id,
        string $channel,
        string $mode,
        bool $approvals,
        array $calls,
        int $max,
        bool $repeat,
        string $final,
    ): void {
        $this->reset($approvals, $max);
        $this->facadeMode = 'openai';
        $owned = [];
        $modelCalls = [];
        foreach ($calls as $call) {
            $modelCalls[] = [
                'name' => (string) $call['name'],
                'arguments' => is_array($call['input'] ?? null) ? $call['input'] : [],
            ];
            if (true !== ($call['client'] ?? false)) {
                $owned[] = $call;
            }
        }
        $snapshot = $this->snapshot($owned);
        $this->catalog = $snapshot;
        if ('gateway' === $channel) {
            $this->translator->toolUses = $modelCalls;
            $this->translator->finalText = $final;
            $this->translator->repeat = $repeat;
            $record = 'stream' === $mode
                ? $this->runGatewayStream($snapshot)
                : $this->runGatewayComplete($snapshot);
        } else {
            $this->openaiModel->toolCalls = $modelCalls;
            $this->openaiModel->finalText = $final;
            $this->openaiModel->repeat = $repeat;
            $record = 'stream' === $mode
                ? $this->runOpenAiStream($this->clientDeclarations($calls))
                : $this->runOpenAiComplete($this->clientDeclarations($calls));
        }
        $this->put($id, $record);
    }

    /**
     * @param array<string, mixed> $snapshot
     *
     * @return array<string, mixed>
     */
    private function runGatewayComplete(array $snapshot): array
    {
        $result = $this->gateway->runComplete(
            $this->requestBody(),
            $this->translatorContext(),
            $this->translator,
            $this->user,
            $snapshot,
        );
        $body = is_array($result['body'] ?? null) ? $result['body'] : [];
        $reason = $result['usage']->stopReason;
        $recorded = [
            'assistant' => $this->assistantText($body),
            'tool_results' => $this->translator->toolResults,
            'status' => $result['status'],
        ];
        $clientTools = $this->gatewayClientTools($body);
        if ([] !== $clientTools) {
            $recorded['client_tools'] = $clientTools;
        }

        return [
            'stop' => $this->stop($reason, $this->translator->calls, $this->knobs->maxIterations, $this->translator->toolResults),
            'iterations' => $result['iterations'],
            'result' => $recorded,
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     *
     * @return array<string, mixed>
     */
    private function runGatewayStream(array $snapshot): array
    {
        $events = [];
        $usage = $this->gateway->runStream(
            $this->requestBody(),
            $this->translatorContext(),
            $this->translator,
            $this->user,
            $snapshot,
            function (string|array $chunk) use (&$events): void {
                $events[] = $this->gatewayEvent($chunk);
            },
        );

        return [
            'stop' => $this->stop($usage->stopReason, $this->translator->calls, $this->knobs->maxIterations, $this->translator->toolResults),
            'result' => [
                'tool_results' => $this->translator->toolResults,
                'events' => $events,
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $clientTools
     *
     * @return array<string, mixed>
     */
    private function runOpenAiComplete(array $clientTools): array
    {
        $result = $this->openai->complete($this->user, [['role' => 'user', 'content' => 'Edit the report']], [
            'model' => 'test',
            'tools' => $clientTools,
        ]);
        $finish = is_string($result['finish_reason'] ?? null) ? $result['finish_reason'] : '';
        $recorded = [
            'assistant' => is_string($result['content'] ?? null) ? $result['content'] : '',
            'tool_results' => $this->openaiModel->toolResults,
            'finish_reason' => $finish,
        ];
        $clientTools = $this->openAiClientTools($result);
        if ([] !== $clientTools) {
            $recorded['client_tools'] = $clientTools;
        }

        return [
            'stop' => $this->openAiStop($finish),
            'result' => $recorded,
        ];
    }

    /**
     * @param list<array<string, mixed>> $clientTools
     *
     * @return array<string, mixed>
     */
    private function runOpenAiStream(array $clientTools): array
    {
        $finish = 'tool_calls';
        $events = [];
        $this->openai->stream(
            $this->user,
            [['role' => 'user', 'content' => 'Edit the report']],
            function (mixed $chunk) use (&$finish, &$events): void {
                if (is_string($chunk)) {
                    $events[] = ['type' => 'text', 'text' => $chunk];

                    return;
                }
                if (!is_array($chunk)) {
                    return;
                }
                if (is_string($chunk['finish_reason'] ?? null)) {
                    $finish = $chunk['finish_reason'];
                }
                $events[] = $this->openAiEvent($chunk);
            },
            ['model' => 'test', 'tools' => $clientTools],
        );

        return [
            'stop' => $this->openAiStop($finish),
            'result' => [
                'tool_results' => $this->openaiModel->toolResults,
                'events' => $events,
            ],
        ];
    }

    private function runnerCases(): void
    {
        $this->runToolCall('runner_tool_call.custom.approvals_off', false, false, false, 'create_ticket');
        $this->runToolCall('runner_tool_call.custom.approvals_on', true, false, false, 'create_ticket');
        $this->runToolCall('runner_tool_call.custom.approvals_on_unattended', true, true, false, 'create_ticket');
        $this->runToolCall('runner_tool_call.custom.already_approved', true, false, true, 'create_ticket');
        $this->runToolCall('runner_tool_call.mcp_write.approvals_on', true, false, false, 'mcp__1__create_issue');

        $this->runFetch('runner_mcp_fetch.read.approvals_off', false);
        $this->runFetch('runner_mcp_fetch.read.approvals_on', true);

        $this->runAction('runner_mcp_action.write.approvals_off', false, false, false);
        $this->runAction('runner_mcp_action.write.approvals_on_interactive', true, false, false);
        $this->runAction('runner_mcp_action.write.approvals_on_unattended', true, true, false);
        $this->runAction('runner_mcp_action.write.already_approved', true, false, true);

        $this->runCode('runner_code.approvals_off', false, false);
        $this->runCode('runner_code.approvals_on_interactive', true, false);
        $this->runCode('runner_code.approvals_on_unattended', true, true);
    }

    private function runToolCall(string $id, bool $approvals, bool $unattended, bool $already, string $tool): void
    {
        $this->reset($approvals);
        $context = $this->context(['allow_unattended' => $unattended]);
        if ($already) {
            $context->markApproved('n1');
        }
        $result = $this->toolCall->run(new TaskNode('n1', Capability::ToolCall, params: [
            'tool' => $tool,
            'inputs' => ['summary' => 'Q3'],
        ]), $context);
        $this->put($id, $this->nodeRecord($result));
    }

    private function runFetch(string $id, bool $approvals): void
    {
        $this->reset($approvals);
        $result = $this->mcpFetch->run(new TaskNode('n1', Capability::McpFetch, inputs: [
            'arguments' => ['query' => 'acme'],
        ], params: [
            'server_id' => 1,
            'tool' => 'search_customers',
        ]), $this->context());
        $this->put($id, $this->nodeRecord($result));
    }

    private function runAction(string $id, bool $approvals, bool $unattended, bool $already): void
    {
        $this->reset($approvals);
        $context = $this->context([
            'saved_task' => $unattended,
            'allow_unattended' => $unattended,
            'saved_task_run_id' => $unattended ? 4 : null,
        ]);
        if ($already) {
            $context->markApproved('n1');
        }
        $result = $this->mcpAction->run(new TaskNode('n1', Capability::McpAction, inputs: [
            'arguments' => ['summary' => 'Q3'],
        ], params: [
            'server_id' => 1,
            'tool' => 'create_issue',
        ]), $context);
        $this->put($id, $this->nodeRecord($result));
    }

    private function runCode(string $id, bool $approvals, bool $unattended): void
    {
        $this->reset($approvals);
        $options = $unattended
            ? ['saved_task_run_id' => 4, 'allow_unattended' => false]
            : [];
        $result = $this->codeRun->run(
            new TaskNode('n1', Capability::CodeRun, params: ['script' => "print('printed-ok')"]),
            $this->context($options),
        );
        $this->put($id, $this->nodeRecord($result));
    }

    private function resumeCases(): void
    {
        $this->runResume('resume_approval.mcp_write', 'mcp__1__create_issue');
        $this->runResume('resume_approval.custom_http', 'create_ticket');
    }

    private function runResume(string $id, string $tool): void
    {
        $this->reset(true);
        $approval = new Approval(self::USER_ID, 'chat:11', $tool, SideEffect::Write->value, time() + 3600);
        $approval->setArgs(['summary' => 'Q3']);
        $approval->markApproved(self::USER_ID);
        (new \ReflectionProperty(Approval::class, 'id'))->setValue($approval, 3);
        $this->pendingResume = $approval;
        ($this->resume)(new ResumeApprovalCommand(3));
        $this->put($id, [
            'stop' => 'done',
            'result' => ['status' => $approval->getStatus(), 'ref' => $this->stableRef($approval->getResultRef())],
        ]);
    }

    private function mcpServerCases(): void
    {
        $this->reset(false);
        $add = $this->mcpClosure('memoryAddHandler');
        $memory = $add('work', 'quarter', 'Q3 is closed');
        $this->executed[] = 'memory_add';
        $this->put('mcp_server.memory_add', [
            'stop' => 'done',
            'result' => is_array($memory) ? ToolLoopText::stripRandom($memory) : $memory,
        ]);

        $this->reset(false);
        $ingest = $this->mcpClosure('fileIngestHandler');
        $file = $ingest('notes', 'Q3 numbers', 'reports');
        $this->executed[] = 'file_ingest';
        $this->put('mcp_server.file_ingest', [
            'stop' => 'done',
            'result' => is_array($file) ? $file : ['value' => $file],
        ]);
    }

    /**
     * @return \Closure(mixed...): mixed
     */
    private function mcpClosure(string $method): \Closure
    {
        $handler = (new \ReflectionMethod(McpServerFactory::class, $method))->invoke($this->mcpServer, $this->user);
        if (!$handler instanceof \Closure) {
            throw new \RuntimeException($method.' did not return a closure.');
        }

        return $handler;
    }

    /**
     * @param array<string, mixed> $extra
     * @param list<string>         $executed
     */
    private function put(string $id, array $extra, array $executed = []): void
    {
        if ([] !== $executed) {
            $this->executed = $executed;
        }
        $gate = $this->gateDecisions;
        $approvals = array_values($this->approvals);
        usort($approvals, static fn (array $a, array $b): int => ((int) $a['id']) <=> ((int) $b['id']));
        $this->cases[$id] = [
            'approvals_enabled' => $this->knobs->approvals,
            'executed' => $this->executed,
            'gate' => [] === $gate ? 'no gate' : $gate,
            'approvals' => $approvals,
            'audit' => $this->audit,
            'usage' => $this->usage,
        ] + $extra;
    }

    /**
     * @return array<string, mixed>
     */
    private function nodeRecord(NodeResult $result): array
    {
        $meta = $result->metadata;
        unset($meta['expires_at']);

        return [
            'stop' => match ($result->status) {
                NodeStatus::WaitingApproval => 'needs_approval',
                NodeStatus::Failed => 'refused',
                NodeStatus::Done => 'done',
                default => $result->status->value,
            },
            'result' => ToolLoopText::stripRandom([
                'status' => $result->status->value,
                'text' => null === $result->text ? null : ToolLoopText::pack($result->text),
                'error' => $result->error,
                'metadata' => $meta,
            ]),
        ];
    }

    private function stop(?string $reason, int $calls, int $max, array $toolResults): string
    {
        if ($calls >= $max && 'tool_use' === $reason && [] === $toolResults && [] !== $this->executed) {
            return 'iterations';
        }
        if ('tool_use' === $reason && [] === $this->executed) {
            return 'client_tool';
        }
        if ('end_turn' === $reason || 'stop' === $reason) {
            return 'done';
        }
        if ('tool_use' === $reason && $calls >= $max) {
            return 'iterations';
        }

        return $reason ?? 'unknown';
    }

    private function openAiStop(string $finish): string
    {
        if ('stop' === $finish) {
            return 'done';
        }
        if ('tool_calls' === $finish
            && [] !== $this->executed
            && $this->openaiModel->calls >= $this->knobs->maxIterations
            && [] === $this->openaiModel->toolResults
        ) {
            return 'iterations';
        }
        if ('tool_calls' === $finish) {
            return 'client_tool';
        }

        return '' === $finish ? 'unknown' : $finish;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function assistantText(array $body): string
    {
        $parts = [];
        $content = $body['content'] ?? [];
        if (!is_array($content)) {
            return '';
        }
        foreach ($content as $block) {
            if (is_array($block) && 'text' === ($block['type'] ?? null) && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            }
        }

        return implode('', $parts);
    }

    /**
     * The tool_use blocks handed back to the client. Owned tools are absent
     * here because the loop already ran them.
     *
     * @param array<string, mixed> $body
     *
     * @return list<array{id: mixed, name: mixed, input: array<string, mixed>}>
     */
    private function gatewayClientTools(array $body): array
    {
        $content = $body['content'] ?? [];
        if (!is_array($content)) {
            return [];
        }
        $tools = [];
        foreach ($content as $block) {
            if (!is_array($block) || 'tool_use' !== ($block['type'] ?? null)) {
                continue;
            }
            $input = $block['input'] ?? [];
            $tools[] = [
                'id' => $block['id'] ?? null,
                'name' => $block['name'] ?? null,
                'input' => is_array($input) ? $input : [],
            ];
        }

        return $tools;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return list<array{id: mixed, name: mixed, arguments: mixed}>
     */
    private function openAiClientTools(array $result): array
    {
        $calls = $result['tool_calls'] ?? [];
        if (!is_array($calls)) {
            return [];
        }
        $tools = [];
        foreach ($calls as $call) {
            if (!is_array($call)) {
                continue;
            }
            $function = is_array($call['function'] ?? null) ? $call['function'] : [];
            $arguments = $function['arguments'] ?? [];
            if (is_string($arguments)) {
                $decoded = json_decode($arguments, true);
                $arguments = is_array($decoded) ? $decoded : $arguments;
            }
            $tools[] = [
                'id' => $call['id'] ?? null,
                'name' => $function['name'] ?? null,
                'arguments' => $arguments,
            ];
        }

        return $tools;
    }

    /**
     * @return array<string, mixed>
     */
    private function gatewayEvent(string|array $chunk): array
    {
        if (is_string($chunk)) {
            return ['event' => 'text', 'text' => $chunk];
        }
        $data = is_array($chunk['data'] ?? null) ? $chunk['data'] : [];
        $event = ['event' => is_string($chunk['event'] ?? null) ? $chunk['event'] : 'unknown'];
        $block = is_array($data['content_block'] ?? null) ? $data['content_block'] : null;
        if (null !== $block && 'tool_use' === ($block['type'] ?? null)) {
            $event['id'] = $block['id'] ?? null;
            $event['name'] = $block['name'] ?? null;
        }
        $delta = is_array($data['delta'] ?? null) ? $data['delta'] : null;
        if (null !== $delta && is_string($delta['text'] ?? null)) {
            $event['text'] = $delta['text'];
        }
        if (null !== $delta && is_string($delta['partial_json'] ?? null)) {
            $event['partial_json'] = $delta['partial_json'];
        }
        if (null !== $delta && is_string($delta['stop_reason'] ?? null)) {
            $event['stop_reason'] = $delta['stop_reason'];
        }

        return $event;
    }

    /**
     * @param array<string, mixed> $chunk
     *
     * @return array<string, mixed>
     */
    private function openAiEvent(array $chunk): array
    {
        $event = ['type' => is_string($chunk['type'] ?? null) ? $chunk['type'] : 'chunk'];
        foreach (['index', 'id', 'name', 'arguments', 'finish_reason'] as $key) {
            if (array_key_exists($key, $chunk)) {
                $event[$key] = $chunk[$key];
            }
        }

        return $event;
    }

    private function reset(bool $approvals, int $max = 8): void
    {
        $this->knobs->approvals = $approvals;
        $this->knobs->maxIterations = $max;
        $this->gateDecisions = [];
        $this->translator->reset();
        $this->documentModel->reset();
        $this->openaiModel->reset();
        $this->executed = [];
        $this->usage = [];
        $this->approvals = [];
        $this->audit = [];
        $this->nextApprovalId = 0;
        $this->pendingResume = null;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function context(array $options = []): NodeContext
    {
        return new NodeContext($this->message, [], self::USER_ID, ['topic' => 'tools'], $options);
    }

    /**
     * @return array<string, mixed>
     */
    private function requestBody(): array
    {
        return [
            'model' => 'claude-test',
            'max_tokens' => 64,
            'messages' => [['role' => 'user', 'content' => 'Edit the report']],
        ];
    }

    /**
     * @return array{api_key: string, upstream_url: string}
     */
    private function translatorContext(): array
    {
        return ['api_key' => 'test-key', 'upstream_url' => 'http://example.test'];
    }

    /**
     * @param list<array<string, mixed>> $owned
     *
     * @return array<string, mixed>
     */
    private function snapshot(array $owned): array
    {
        $tools = [];
        $dispatch = [];
        foreach ($owned as $call) {
            $name = (string) $call['name'];
            $tools[] = [
                'name' => $name,
                'description' => (string) ($call['tool'] ?? $name),
                'input_schema' => ['type' => 'object'],
            ];
            $dispatch[$name] = [
                'kind' => (string) $call['kind'],
                'serverId' => GatewayToolCatalog::KIND_MCP === ($call['kind'] ?? null) ? 1 : 0,
                'tool' => (string) ($call['tool'] ?? $name),
                'annotations' => is_array($call['annotations'] ?? null) ? $call['annotations'] : [],
            ];
        }

        return [
            'tools' => $tools,
            'dispatch' => $dispatch,
            'web_search' => GatewayToolCatalog::WEB_SEARCH_NONE,
        ];
    }

    /**
     * @param list<array<string, mixed>> $calls
     *
     * @return list<array<string, mixed>>
     */
    private function clientDeclarations(array $calls): array
    {
        $out = [];
        foreach ($calls as $call) {
            if (true !== ($call['client'] ?? false)) {
                continue;
            }
            $out[] = [
                'type' => 'function',
                'function' => [
                    'name' => (string) $call['name'],
                    'description' => 'Client tool',
                    'parameters' => ['type' => 'object'],
                ],
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function mcpRead(string $query = 'q3'): array
    {
        return [
            'name' => 'mcp__1__rag_search',
            'input' => ['query' => $query],
            'kind' => GatewayToolCatalog::KIND_MCP,
            'tool' => 'rag_search',
            'annotations' => ['readOnlyHint' => true],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mcpWrite(): array
    {
        return [
            'name' => 'mcp__1__create_issue',
            'input' => ['summary' => 'Q3'],
            'kind' => GatewayToolCatalog::KIND_MCP,
            'tool' => 'create_issue',
            'annotations' => ['readOnlyHint' => false],
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function native(string $name, array $input): array
    {
        return [
            'name' => $name,
            'input' => $input,
            'kind' => GatewayToolCatalog::KIND_NATIVE,
            'tool' => $name,
            'annotations' => ['readOnlyHint' => true],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function customCall(): array
    {
        return [
            'name' => 'create_ticket',
            'input' => ['summary' => 'Q3'],
            'kind' => GatewayToolCatalog::KIND_CUSTOM,
            'tool' => 'create_ticket',
            'annotations' => ['toolId' => 4],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function clientCall(): array
    {
        return [
            'name' => 'get_weather',
            'input' => ['city' => 'Bern'],
            'client' => true,
        ];
    }

    private function stableRef(?string $ref): ?string
    {
        if (null === $ref) {
            return null;
        }

        return 1 === preg_match('/^q[0-9a-f]{24}$/', $ref) ? 'q…' : $ref;
    }

    /**
     * @return array<string, mixed>
     */
    private function bounds(): array
    {
        $read = static function (string $class, string $name): int {
            $constant = new \ReflectionClassConstant($class, $name);

            return (int) $constant->getValue();
        };

        return [
            'chat' => [
                'wall_clock_seconds' => $read(ChatToolLoop::class, 'WALL_CLOCK_SECONDS'),
                'max_tools_per_turn' => $read(ChatToolLoop::class, 'MAX_TOOLS_PER_TURN'),
                'max_result_chars' => $read(ChatToolLoop::class, 'MAX_RESULT_CHARS'),
            ],
            'gateway' => [
                'wall_clock_seconds' => $read(GatewayToolLoop::class, 'WALL_CLOCK_SECONDS'),
                'max_tools_per_turn' => $read(GatewayToolLoop::class, 'MAX_TOOLS_PER_TURN'),
                'max_result_chars' => $read(GatewayToolLoop::class, 'MAX_TOOL_RESULT_CHARS'),
            ],
            'openai' => [
                'wall_clock_seconds' => $read(OpenAiGatewayToolLoop::class, 'WALL_CLOCK_SECONDS'),
                'max_tools_per_turn' => $read(OpenAiGatewayToolLoop::class, 'MAX_TOOLS_PER_TURN'),
                'max_result_chars' => $read(OpenAiGatewayToolLoop::class, 'MAX_TOOL_RESULT_CHARS'),
            ],
            'note' => 'The 240s deadline is read from the wall clock, so this suite does not wait it out. The iterations case is the live cap.',
        ];
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T&MockObject
     */
    private function double(string $class): MockObject
    {
        $double = ($this->mock)($class);
        if (!$double instanceof MockObject || !$double instanceof $class) {
            throw new \RuntimeException('Expected a mock of '.$class);
        }

        return $double;
    }

    private function recordingGate(
        ToolsConfig $toolsConfig,
        ToolRegistry $registry,
        ApprovalPolicy $policy,
        ApprovalService $approvalService,
    ): ToolExecutionGate {
        $real = new ToolExecutionGate($toolsConfig, $registry, $policy, $approvalService);
        $spy = $this->double(ToolExecutionGate::class);
        $spy->method('inspect')->willReturnCallback(
            function (
                int $userId,
                string $toolName,
                array $args,
                User $actor,
                PolicyContext $context,
                string $requestedBy,
                ?array $assistantTools = null,
                bool $allowUnattended = false,
                ?string $assistantKey = null,
                ?string $nodeApproval = null,
                bool $requireApproval = false,
                ?array $resolvedRequest = null,
                bool $alreadyApproved = false,
                ?string $forcedRefusal = null,
            ) use ($real): array {
                try {
                    $decision = $real->inspect(
                        $userId,
                        $toolName,
                        $args,
                        $actor,
                        $context,
                        $requestedBy,
                        $assistantTools,
                        $allowUnattended,
                        $assistantKey,
                        $nodeApproval,
                        $requireApproval,
                        $resolvedRequest,
                        $alreadyApproved,
                        $forcedRefusal,
                    );
                } catch (ToolNotRegisteredException $e) {
                    $this->gateDecisions[] = ['tool' => $toolName, 'outcome' => 'not_registered', 'approval' => null];
                    throw $e;
                }
                $approval = $decision['approval'];
                $this->gateDecisions[] = [
                    'tool' => $toolName,
                    'outcome' => $decision['outcome']->value,
                    'approval' => null !== $approval ? (int) $approval->getId() : null,
                ];

                return $decision;
            },
        );
        $spy->method('approvalsEnabled')->willReturnCallback(
            static fn (int $userId): bool => $real->approvalsEnabled($userId),
        );

        return $spy;
    }

    private function boot(): void
    {
        $knobs = $this->knobs;

        $toolsConfig = $this->double(ToolsConfig::class);
        $toolsConfig->method('isApprovalsEnabled')->willReturnCallback(static fn (?int $userId): bool => $knobs->approvals);
        $toolsConfig->method('isRegistryEnabled')->willReturn(true);
        $toolsConfig->method('isCustomHttpEnabled')->willReturn(true);
        $toolsConfig->method('approvalExpiryHours')->willReturn(72);
        $toolsConfig->method('alwaysAllowTools')->willReturn([]);
        $toolsConfig->method('defaultOutcome')->willReturnCallback(static fn (SideEffect $side): PolicyOutcome => match ($side) {
            SideEffect::Read => PolicyOutcome::Auto,
            SideEffect::Write => PolicyOutcome::Approve,
            SideEffect::Destructive => PolicyOutcome::Block,
        });
        $toolsConfig->method('notifyMode')->willReturn(ToolsConfig::NOTIFY_INSTANT);

        $policy = new ApprovalPolicy($toolsConfig, new NeutralGroupPolicy(), new NeutralAssistantPolicy(), $this->computeConfig());
        $approvals = $this->double(ApprovalRepository::class);
        $approvals->method('save')->willReturnCallback(function (Approval $approval): void {
            if (null === $approval->getId()) {
                ++$this->nextApprovalId;
                (new \ReflectionProperty(Approval::class, 'id'))->setValue($approval, $this->nextApprovalId);
            }
            $id = (int) $approval->getId();
            $this->approvals[$id] = [
                'id' => $id,
                'tool' => $approval->getTool(),
                'sideEffect' => $approval->getSideEffect(),
                'status' => $approval->getStatus(),
                'requestedBy' => $approval->getRequestedBy(),
            ];
        });
        $approvals->method('find')->willReturnCallback(fn (int $id): ?Approval => $this->pendingResume);

        $auditRepo = $this->double(AuditLogEntryRepository::class);
        $auditRepo->method('save')->willReturnCallback(function (AuditLogEntry $entry): void {
            $subject = $entry->getSubject();
            if (is_array($subject) && array_key_exists('durationMs', $subject)) {
                $subject['durationMs'] = 0;
            }
            $this->audit[] = [
                'actor' => $entry->getActorId(),
                'action' => $entry->getAction(),
                'kind' => $entry->getResourceKind(),
                'id' => $entry->getResourceId(),
                'subject' => $subject,
            ];
        });
        $auditWriter = new AuditLogWriter($auditRepo);
        $approvalService = new ApprovalService(
            $approvals,
            new ApprovalArgsRedactor(),
            $toolsConfig,
            $this->double(ApprovalRealtimeNotifier::class),
            $auditWriter,
        );
        $this->gate = $this->recordingGate($toolsConfig, new ToolRegistry([new FixedToolSource($this->descriptors())]), $policy, $approvalService);

        $rates = $this->double(RateLimitService::class);
        $rates->method('checkLimit')->willReturn(['allowed' => true, 'remaining' => 10, 'limit' => 100]);
        $rates->method('computeIntSetting')->willReturnCallback(static fn (User $user, string $setting, int $fallback): int => $fallback);
        $rates->method('recordUsage')->willReturnCallback(function (User $user, string $action, array $metadata = []): RecordedUsage {
            $this->usage[] = $this->usageRow($action, $metadata);

            return new RecordedUsage('0.000000', '0.000000', 0, 0, 0);
        });
        $rates->method('recordFileAnalysisOnce')->willReturnCallback(function (User $user, int $fileId, array $metadata = []): bool {
            $this->usage[] = [
                'action' => 'FILE_ANALYSIS',
                'source' => $metadata['source'] ?? null,
                'filename' => $metadata['filename'] ?? null,
                'file_id' => $fileId,
            ];

            return true;
        });

        $mcp = $this->double(McpClient::class);
        $mcp->method('callTool')->willReturnCallback(function (McpServerConfig $server, string $tool, array $arguments): array {
            $this->executed[] = $tool;
            $query = $arguments['query'] ?? '';
            $text = 'clamp' === $query ? str_repeat('A', 13000) : 'mcp:'.$tool;

            return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => false];
        });

        $servers = $this->double(McpServerConfigRepository::class);
        $servers->method('findByIdAndUser')->willReturnCallback(
            fn (int $id, int $userId): ?McpServerConfig => 1 === $id && self::USER_ID === $userId ? $this->server : null,
        );

        $custom = $this->customTool();
        $customTools = $this->double(CustomToolRepository::class);
        $customTools->method('find')->willReturnCallback(static fn (int $id): ?CustomTool => 4 === $id ? $custom : null);

        $http = $this->double(HttpToolExecutor::class);
        $http->method('execute')->willReturnCallback(function (): array {
            $this->executed[] = 'create_ticket';

            return ['summary' => 'Ticket SYN-1 created', 'status' => 201, 'fields' => []];
        });
        $http->method('resolve')->willReturn([
            'method' => 'POST',
            'url' => 'https://example.test/issues',
            'headers' => [],
            'body' => null,
        ]);

        $webSearch = $this->double(WebSearchTool::class);
        $webSearch->method('execute')->willReturnCallback(function (array $arguments): array {
            $this->executed[] = 'web_search';

            return [
                'text' => 'Rates at https://example.test/rates',
                'isError' => false,
                'query' => is_string($arguments['query'] ?? null) ? $arguments['query'] : '',
                'resultCount' => 1,
            ];
        });
        $image = $this->double(AnalyzeImageTool::class);
        $image->method('execute')->willReturnCallback(function (): array {
            $this->executed[] = 'analyze_image';

            return ['text' => 'A chart.', 'isError' => false, 'summary' => 'chart'];
        });
        $codeTool = $this->double(CodeExecutionTool::class);
        $codeTool->method('execute')->willReturnCallback(function (): array {
            $this->executed[] = 'code_execution';

            return ['text' => 'printed-ok', 'isError' => false];
        });

        $messages = $this->double(MessagesGatewayConfig::class);
        $messages->method('mcpMaxIterations')->willReturnCallback(static fn (?int $userId): int => $knobs->maxIterations);
        $messages->method('webSearchMaxRounds')->willReturn(2);
        $messages->method('webFetchMaxPerTurn')->willReturn(4);

        $catalogAdapter = new McpToolCatalogAdapter($this->double(McpToolRegistry::class));
        $logger = new NullLogger();
        $registry = new ToolRegistry([new FixedToolSource($this->descriptors())]);

        $facade = $this->double(AiFacade::class);
        $facade->method('chat')->willReturnCallback(function (array|string $messages, ?int $userId = null, array $options = []): array {
            $list = is_array($messages) ? $messages : [];

            return 'document' === $this->facadeMode ? $this->documentModel->reply($list) : $this->openaiModel->reply($list);
        });
        $facade->method('chatStream')->willReturnCallback(function (array|string $messages, callable $callback, ?int $userId = null, array $options = []): array {
            $reply = $this->openaiModel->reply(is_array($messages) ? $messages : []);
            $text = is_string($reply['content'] ?? null) ? $reply['content'] : '';
            if ('' !== $text) {
                $mid = (int) max(1, (int) ceil(strlen($text) / 2));
                $callback(substr($text, 0, $mid));
                $rest = substr($text, $mid);
                if ('' !== $rest) {
                    $callback($rest);
                }
            }

            return $reply;
        });

        $openaiCatalog = $this->double(\App\AI\OpenAI\OpenAiGatewayToolCatalog::class);
        $openaiCatalog->method('build')->willReturnCallback(fn (): array => $this->catalog);

        $users = $this->double(UserRepository::class);
        $users->method('find')->willReturn($this->user);

        $this->gateway = new GatewayToolLoop(
            $catalogAdapter,
            new NativeToolRegistry($webSearch, $this->double(WebFetchTool::class)),
            $image,
            $mcp,
            $servers,
            $messages,
            $rates,
            $logger,
            $this->gate,
            $registry,
            $toolsConfig,
            $codeTool,
            $http,
            $customTools,
        );
        $this->openai = new OpenAiGatewayToolLoop(
            $openaiCatalog,
            $facade,
            $webSearch,
            $mcp,
            $catalogAdapter,
            $servers,
            $messages,
            $rates,
            $logger,
            $this->gate,
            $registry,
            $toolsConfig,
            $codeTool,
        );

        $docConfig = $this->double(DocumentToolsConfig::class);
        $docConfig->method('maxIterations')->willReturn(4);
        $docConfig->method('maxOpsPerTurn')->willReturn(8);
        $documentTools = new DocumentToolRegistry([new SetCellsTool(), new LongEchoDocumentTool()]);

        $this->toolCall = new ToolCallRunner(
            $registry,
            new StepInputResolver(),
            $http,
            $customTools,
            $mcp,
            $servers,
            $users,
            $logger,
            $this->gate,
            $toolsConfig,
        );

        $discovered = [[
            'name' => 'search_customers',
            'description' => 'Find customers',
            'inputSchema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]],
            'annotations' => ['readOnlyHint' => true],
        ], [
            'name' => 'create_issue',
            'description' => 'Create an issue',
            'inputSchema' => ['type' => 'object', 'properties' => ['summary' => ['type' => 'string']]],
            'annotations' => ['readOnlyHint' => false],
        ]];
        $discovery = $this->double(McpToolRegistry::class);
        $discovery->method('toolsFor')->willReturn($discovered);
        $clientConfig = $this->double(McpClientConfig::class);
        $clientConfig->method('isClientEnabled')->willReturn(true);
        $routing = $this->double(MultitaskRoutingConfig::class);
        $routing->method('isFeatureEnabled')->willReturn(true);
        $prompts = $this->double(PromptService::class);
        $prompts->method('getPromptWithMetadata')->willReturn(['metadata' => ['tool_mcp' => true]]);
        $images = $this->double(NotionImageAttach::class);
        $images->method('applies')->willReturn(false);
        $atlassian = new AtlassianToolArguments($mcp, $logger);
        $notion = new NotionFetchArguments($logger);
        $this->mcpFetch = new McpFetchRunner($mcp, $discovery, $servers, $clientConfig, $routing, $prompts, $logger, $atlassian, $notion, $images);
        $this->mcpAction = new McpActionRunner($mcp, $discovery, $servers, $clientConfig, $routing, $prompts, $logger, $atlassian, $this->gate, $users);

        $compute = $this->double(ComputeClient::class);
        $compute->method('submitRun')->willReturnCallback(function (): string {
            $this->executed[] = 'code_run';

            return self::RUN_ID;
        });
        $compute->method('status')->willReturn(new ComputeRunStatus(
            runId: self::RUN_ID,
            status: 'succeeded',
            usage: ['wallMs' => 10, 'cpuSec' => 0.1, 'maxMemoryMb' => 32, 'bytesIn' => 1, 'bytesOut' => 1],
            truncated: ['stdout' => false, 'stderr' => false],
            exitCode: 0,
            durationMs: 10,
        ));
        $compute->method('collectLogs')->willReturn(['stdout' => 'printed-ok', 'stderr' => '']);
        $runs = $this->double(ComputeRunRepository::class);
        $runs->method('countActiveForUser')->willReturn(0);
        $runs->method('sumDurationMsSince')->willReturn(0);
        $config = $this->computeConfig();
        $this->codeRun = new CodeRunRunner(
            $config,
            $compute,
            $this->double(ComputeArtefactStore::class),
            $runs,
            $this->double(FileRepository::class),
            $users,
            $rates,
            $logger,
            '/tmp',
            $this->gate,
            null,
            new ComputeEgressResolver($config, new SsrfGuard()),
        );

        $continuation = $this->double(ChatApprovalContinuationService::class);
        $continuation->method('continueChat')->willReturn(null);
        $this->resume = new ResumeApprovalCommandHandler(
            $approvals,
            $registry,
            $users,
            $customTools,
            $http,
            $servers,
            $mcp,
            $this->double(ApprovalRealtimeNotifier::class),
            $continuation,
            $this->double(MessageBusInterface::class),
            $logger,
        );

        $memory = $this->double(UserMemoryService::class);
        $memory->method('isAvailable')->willReturn(true);
        $memory->method('createMemory')->willReturn(new UserMemoryDTO(
            id: 5,
            userId: self::USER_ID,
            category: 'work',
            key: 'quarter',
            value: 'Q3 is closed',
            source: 'user_created',
            created: 1,
            updated: 1,
        ));
        $storage = $this->double(FileStorageService::class);
        $storage->method('storeRawContent')->willReturn([
            'success' => true,
            'path' => 'uploads/7/notes.txt',
            'size' => 11,
        ]);
        $vectors = $this->double(VectorizationService::class);
        $vectors->method('vectorizeAndStore')->willReturn([
            'success' => true,
            'chunks_created' => 1,
            'provider' => 'test',
        ]);
        $em = $this->double(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity): void {
            if ($entity instanceof File) {
                (new \ReflectionProperty(File::class, 'id'))->setValue($entity, 42);
            }
        });
        $this->mcpServer = new McpServerFactory(
            $this->double(VectorSearchService::class),
            $memory,
            $this->double(MessageProcessor::class),
            $vectors,
            $storage,
            $this->double(StorageQuotaService::class),
            $this->double(PromptRepository::class),
            $this->double(ChatRepository::class),
            $this->double(MessageRepository::class),
            $rates,
            $em,
            $this->double(CacheItemPoolInterface::class),
            $this->double(ConversationSummaryRefreshDispatcher::class),
            $this->double(DesktopAgentConfig::class),
            $this->double(DesktopJobStore::class),
            $this->double(DesktopDeviceRepository::class),
            $this->double(DesktopJobResultNotifier::class),
            $logger,
            $this->double(EventRingStore::class),
        );

        $invoker = new \App\Service\Tool\Execution\ToolInvoker(
            [
                new \App\Service\Tool\Execution\DocumentToolExecutor($documentTools, $logger),
                new \App\Service\Tool\Execution\McpToolExecutor($mcp, $servers, $discovery),
                new \App\Service\Tool\Execution\CustomHttpToolExecutor($logger, $customTools, $http, $toolsConfig),
                new \App\Service\Tool\Execution\BuiltinToolExecutor(
                    new NativeToolRegistry($webSearch, $this->double(WebFetchTool::class)),
                    $image,
                    $webSearch,
                ),
                new \App\Service\Tool\Execution\MemoryAddExecutor($memory, $rates),
                new \App\Service\Tool\Execution\FileIngestExecutor(
                    $storage,
                    $this->double(StorageQuotaService::class),
                    $vectors,
                    $em,
                    $rates,
                ),
            ],
            gate: $this->gate,
            audit: $auditWriter,
            rates: $rates,
            users: $users,
        );
        $this->gateway->setToolInvoker($invoker);
        $this->openai->setToolInvoker($invoker);
        $this->toolCall->setToolInvoker($invoker);
        $this->mcpFetch->setToolInvoker($invoker);
        $this->mcpAction->setToolInvoker($invoker);
        $this->codeRun->setToolInvoker($invoker);
        $this->resume->setToolInvoker($invoker);
        $this->mcpServer->setToolInvoker($invoker);
        $this->chat = new ChatToolLoop(
            $facade,
            $documentTools,
            $docConfig,
            $invoker,
            $registry,
            $toolsConfig,
            users: $users,
        );
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    private function usageRow(string $action, array $metadata): array
    {
        $row = ['action' => $action];
        $map = [
            'source' => 'source',
            'provider' => 'provider',
            'model' => 'model',
            'input_text' => 'input',
            'response_text' => 'response',
            'cpuSec' => 'cpuSec',
            'filename' => 'filename',
        ];
        foreach ($map as $from => $to) {
            if (!array_key_exists($from, $metadata)) {
                continue;
            }
            $value = $metadata[$from];
            $row[$to] = is_string($value) ? ToolLoopText::pack($value) : $value;
        }

        return $row;
    }

    /**
     * @return list<ToolDescriptor>
     */
    private function descriptors(): array
    {
        $schema = ['type' => 'object'];

        return [
            new ToolDescriptor('set_cells', 'Set cells', 'Set cells', $schema, SideEffect::Write, ToolSource::Document, self::USER_ID, ToolDescriptor::POLICY_OWN_ARTEFACT),
            new ToolDescriptor(LongEchoDocumentTool::NAME, 'Echo', 'Echo', $schema, SideEffect::Write, ToolSource::Document, self::USER_ID, ToolDescriptor::POLICY_OWN_ARTEFACT),
            new ToolDescriptor('mcp__1__rag_search', 'Search', 'Search', $schema, SideEffect::Read, ToolSource::Mcp, self::USER_ID, null, ['serverId' => 1, 'tool' => 'rag_search', 'allowWrite' => false]),
            new ToolDescriptor('mcp__1__create_issue', 'Create issue', 'Create issue', $schema, SideEffect::Write, ToolSource::Mcp, self::USER_ID, null, ['serverId' => 1, 'tool' => 'create_issue', 'allowWrite' => true]),
            new ToolDescriptor('mcp:1:create_issue', 'Create issue', 'Create issue', $schema, SideEffect::Write, ToolSource::Mcp, self::USER_ID, null, ['serverId' => 1, 'tool' => 'create_issue', 'allowWrite' => true]),
            new ToolDescriptor('web_search', 'Web search', 'Search the web', $schema, SideEffect::Read, ToolSource::Builtin, self::USER_ID),
            new ToolDescriptor('analyze_image', 'Analyze image', 'Look at an image', $schema, SideEffect::Read, ToolSource::Builtin, self::USER_ID),
            new ToolDescriptor('code_execution', 'File work', 'Run a script', $schema, SideEffect::Write, ToolSource::Compute, self::USER_ID),
            new ToolDescriptor('create_ticket', 'Create ticket', 'Create a ticket', $schema, SideEffect::Write, ToolSource::Custom, self::USER_ID, null, ['toolId' => 4]),
            new ToolDescriptor('code_run', 'File work', 'Run a script', $schema, SideEffect::Write, ToolSource::Compute, self::USER_ID),
            new ToolDescriptor('mcp:1:search_customers', 'Search customers', 'Find customers', $schema, SideEffect::Read, ToolSource::Mcp, self::USER_ID, null, ['serverId' => 1, 'tool' => 'search_customers', 'allowWrite' => false]),
            new ToolDescriptor('memory_add', 'Save a memory', 'Store one memory', $schema, SideEffect::Write, ToolSource::Plugin, self::USER_ID, ToolDescriptor::POLICY_OWN_ARTEFACT),
            new ToolDescriptor('file_ingest', 'Add a file', 'Add a document', $schema, SideEffect::Write, ToolSource::Plugin, self::USER_ID, ToolDescriptor::POLICY_OWN_ARTEFACT),
        ];
    }

    private function computeConfig(): ComputeConfig
    {
        $repo = $this->double(\App\Repository\ConfigRepository::class);
        $repo->method('getValue')->willReturnCallback(static function (int $owner, string $group, string $setting): ?string {
            return 'ENABLED' === $setting ? '1' : null;
        });

        return new ComputeConfig($repo, 'http://compute:8080', 'token-token-token-token-token-32b');
    }

    private function makeUser(): User
    {
        $user = new User();
        $user->setMail('ada@example.test');
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, self::USER_ID);

        return $user;
    }

    private function makeMessage(): Message
    {
        $message = new Message();
        $message->setUserId(self::USER_ID);
        $message->setText('Please file the Q3 ticket.');
        (new \ReflectionProperty(Message::class, 'id'))->setValue($message, 11);

        return $message;
    }

    private function makeServer(): McpServerConfig
    {
        $server = new McpServerConfig();
        $server->setUserId(self::USER_ID)->setName('Jira')->setUrl('https://example.test/mcp')->setEnabled(true)->setAllowWrite(true);
        (new \ReflectionProperty(McpServerConfig::class, 'id'))->setValue($server, 1);

        return $server;
    }

    private function customTool(): CustomTool
    {
        $tool = new CustomTool(self::USER_ID, 'create_ticket', 'Create ticket');
        $tool->setEnabled(true);
        (new \ReflectionProperty(CustomTool::class, 'id'))->setValue($tool, 4);

        return $tool;
    }
}
