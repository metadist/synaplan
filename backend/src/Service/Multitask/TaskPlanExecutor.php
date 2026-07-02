<?php

declare(strict_types=1);

namespace App\Service\Multitask;

use App\Entity\Message;
use App\Service\Message\InferenceRouter;
use App\Service\ModelConfigService;
use App\Service\Multitask\Execution\DagExecutor;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskPlan;
use Psr\Log\LoggerInterface;

/**
 * Executes a task plan. Drop-in replacement for {@see InferenceRouter} at the
 * MessageProcessor call sites, gated by MULTITASK_ROUTING_ENABLED.
 *
 * Plan source + path selection:
 *   - AI-classified messages (classification source `ai_sorting`) AND non-image
 *     file attachments (`attachment_document_or_audio`) are sent to the
 *     {@see TaskPlanner}. Other deterministic/simple branches (fast-path chat,
 *     slash commands, widget, again) keep using the proven single-node path —
 *     no planner latency, no behaviour change. File attachments are planned so
 *     multi-intent messages ("summarize this DOCX, make an image and read it
 *     aloud") are no longer reduced to a lone file analysis (issue #1192); a
 *     single-intent attachment still degrades to the legacy single-node path.
 *     Widget conversations are excluded even when AI-classified ("standard
 *     sorting" widgets set `is_widget_mode`) — the embed client has no
 *     task-plan UI.
 *   - If the planner returns a SINGLE-node plan or falls back, we run the
 *     Sprint-2 degenerate path: delegate to InferenceRouter with the exact
 *     legacy classification (behaviour identical), and persist the plan.
 *   - If the planner returns a MULTI-node plan, run the sequential
 *     {@see DagExecutor}. On total failure (no node succeeded) we fall back to
 *     the legacy router so the user still gets an answer.
 *
 * Sprint 3b note: multi-node output currently surfaces its FIRST file via the
 * existing `metadata['file']` channel (text + one file — the canonical
 * doc→summary→mp3 case) AND the full list via `metadata['files']` for the
 * additive multi-file controller branch.
 */
final readonly class TaskPlanExecutor
{
    /**
     * Capabilities that have NO legacy InferenceRouter equivalent and therefore
     * must run through the DAG even as a lone single node (see
     * {@see shouldUseLegacyRouter()}).
     */
    private const DAG_ONLY_CAPABILITIES = [Capability::CalendarEvent, Capability::UrlFetch, Capability::McpFetch];

    /**
     * Single-shot media generators that the legacy router already delivers
     * silently as a one-node request (the `/pic`, `/vid`, `/tts`, officemaker
     * experience). When a plan is just one of these plus a pass-through
     * `compose_reply`, the `compose_reply` adds nothing — it is collapsed away
     * so the request no longer spins up the DAG / task-card UI (issue #1072).
     */
    private const COLLAPSIBLE_MEDIA_CAPABILITIES = [
        Capability::ImageGeneration,
        Capability::VideoGeneration,
        Capability::Text2Sound,
        Capability::DocumentGeneration,
    ];

    public function __construct(
        private InferenceRouter $router,
        private ClassificationPlanMapper $mapper,
        private TaskPlanStore $store,
        private TaskPlanner $planner,
        private DagExecutor $dagExecutor,
        private ModelConfigService $modelConfigService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Streaming entry point — mirrors {@see InferenceRouter::routeStream()}.
     *
     * @param array<int, Message>  $thread
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function executeStream(
        Message $message,
        array $thread,
        array $classification,
        callable $streamCallback,
        ?callable $progressCallback = null,
        array $options = [],
    ): array {
        $plan = $this->planForExecution($message, $thread, $classification, $options);

        if (null === $plan || $this->shouldUseLegacyRouter($plan->plan)) {
            return $this->runSingleNode(
                fn () => $this->router->routeStream($message, $thread, $this->effectiveClassification($classification), $streamCallback, $progressCallback, $options),
                $message,
                $classification,
            );
        }

        $assembled = $this->runDag($message, $thread, $classification, $options, $plan, $progressCallback);

        if ($assembled['all_failed']) {
            $this->logger->info('TaskPlanExecutor: DAG produced no successful node, falling back to legacy router', [
                'message_id' => $message->getId(),
            ]);

            // The legacy router is about to produce a clean answer, so retract the
            // failed task cards — otherwise the user sees a misleading
            // "step failed" box sitting above a correct reply.
            $this->discardPlan($progressCallback);

            return $this->runSingleNode(
                fn () => $this->router->routeStream($message, $thread, $this->effectiveClassification($classification), $streamCallback, $progressCallback, $options),
                $message,
                $classification,
            );
        }

        // Surface the assembled text by streaming it once (populates the OUT text)
        // and return the file(s) in the handler-result metadata shape.
        $streamCallback($assembled['content']);

        return $this->toHandlerResult($assembled);
    }

    /**
     * Non-streaming entry point — mirrors {@see InferenceRouter::route()}.
     *
     * @param array<int, Message>  $thread
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function execute(
        Message $message,
        array $thread,
        array $classification,
        ?callable $progressCallback = null,
        array $options = [],
    ): array {
        $plan = $this->planForExecution($message, $thread, $classification, $options);

        if (null === $plan || $this->shouldUseLegacyRouter($plan->plan)) {
            return $this->runSingleNode(
                fn () => $this->router->route($message, $thread, $this->effectiveClassification($classification), $progressCallback, $options),
                $message,
                $classification,
            );
        }

        $assembled = $this->runDag($message, $thread, $classification, $options, $plan, $progressCallback);

        if ($assembled['all_failed']) {
            $this->discardPlan($progressCallback);

            return $this->runSingleNode(
                fn () => $this->router->route($message, $thread, $this->effectiveClassification($classification), $progressCallback, $options),
                $message,
                $classification,
            );
        }

        return $this->toHandlerResult($assembled);
    }

    /**
     * Whether a planned single-node plan should delegate to the legacy
     * InferenceRouter (the behaviour-identical Sprint-2 degenerate path).
     *
     * Multi-node plans always run the DAG. A single-node plan also runs the DAG
     * when its capability has NO legacy router equivalent — otherwise the legacy
     * router, fed the original (calendar-unaware) classification, silently
     * degrades a lone `calendar_event` into a plain chat answer that merely
     * *describes* adding the event (e.g. emitting a literal "{{date:tomorrow}}")
     * instead of producing the .ics. Chat/media/file capabilities keep the
     * legacy path (the legacy classifier already handles them).
     */
    private function shouldUseLegacyRouter(TaskPlan $plan): bool
    {
        if (!$plan->isSingleNode()) {
            return false;
        }

        return !in_array($plan->nodes[0]->capability, self::DAG_ONLY_CAPABILITIES, true);
    }

    /**
     * Decide whether to run the planner and, if so, produce a plan. Returns null
     * to mean "use the single-node legacy path" (deterministic/simple branches).
     *
     * @param array<int, Message>  $thread
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $options
     */
    private function planForExecution(Message $message, array $thread, array $classification, array $options = []): ?TaskPlanResult
    {
        // Messages eligible for multi-task planning:
        //   - `ai_sorting`: genuinely AI-classified turns.
        //   - `attachment_document_or_audio`: non-image file attachments. These
        //     used to be force-routed to single-node file analysis, which
        //     silently dropped any *additional* intents in the same message
        //     ("summarize this DOCX, make an image and read it aloud" became just
        //     a summary). Sending them to the planner restores multi-intent
        //     support. This is safe because a single-intent attachment yields a
        //     single-node (or fallback) plan → shouldUseLegacyRouter() delegates
        //     to the legacy router with the original analyzefile classification,
        //     i.e. identical behaviour. Only genuinely multi-intent messages run
        //     the DAG (issue #1192).
        $source = $classification['source'] ?? null;
        if (!in_array($source, ['ai_sorting', 'attachment_document_or_audio'], true)) {
            return null;
        }

        // Widgets with "standard sorting" are AI-sorted too, but the embedded
        // widget client renders only plain `data` chunks — plan/task_* events
        // are silently dropped, so a DAG would stay mute until the final text
        // dump. Widget conversations always take the single-node path
        // (planning-doc §3.4 invariant).
        if (!empty($classification['is_widget_mode'])) {
            return null;
        }

        try {
            $userId = $this->modelConfigService->getEffectiveUserIdForMessage($message);

            // Forward the classification so dynamic skill blocks (mcp_fetch)
            // can resolve the matched topic's per-prompt gates at plan time.
            $result = $this->planner->plan($message, $thread, $userId, $options + ['classification' => $classification]);

            $collapsed = $this->collapseRedundantSingleMediaPlan($result->plan, $classification);
            if ($collapsed !== $result->plan) {
                $this->logger->info('TaskPlanExecutor: collapsed single-media plan to legacy path (issue #1072)', [
                    'message_id' => $message->getId(),
                    'capability' => $collapsed->nodes[0]->capability->value,
                ]);

                return new TaskPlanResult(
                    $collapsed,
                    $result->fallback,
                    $result->modelId,
                    $result->rawResponse,
                    $result->errors,
                );
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->warning('TaskPlanExecutor: planning failed, using single-node path', [
                'message_id' => $message->getId(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Collapse a redundant `[<single media generator>, compose_reply]` plan into
     * the lone generator node (issue #1072).
     *
     * The planner tends to wrap a plain single-media request ("make an image of
     * a sunset") in a two-node plan (`image_generation` + `compose_reply`) even
     * though the `compose_reply` only passes the generated file straight
     * through. That two-node shape flips `isSingleNode()` to false, spins up the
     * DAG and shows a "Taskplan 1/1" card for what should be a silent one-shot
     * generation — inconsistent with the identical `/pic` slash command.
     *
     * We rewrite it to a single-node plan so {@see shouldUseLegacyRouter()}
     * delegates to the proven legacy {@see InferenceRouter} path (no task card),
     * but ONLY when it is provably safe:
     *   - exactly two nodes, the reply node being a `compose_reply`;
     *   - the other is a root media generator (no upstream dependency — never an
     *     image-edit / animate chain, which genuinely needs the DAG);
     *   - the `compose_reply` depends solely on that media node (pure passthrough);
     *   - the ORIGINAL classification maps to the SAME media capability, so the
     *     legacy router (which runs on that classification, not the plan) still
     *     produces the intended media. If the planner disagreed with the sorter
     *     we keep the DAG so the media is not lost.
     *
     * @param array<string, mixed> $classification
     */
    private function collapseRedundantSingleMediaPlan(TaskPlan $plan, array $classification): TaskPlan
    {
        if (2 !== count($plan->nodes)) {
            return $plan;
        }

        $reply = $plan->nodeById($plan->replyNode);
        if (null === $reply || Capability::ComposeReply !== $reply->capability) {
            return $plan;
        }

        $media = null;
        foreach ($plan->nodes as $node) {
            if ($node->id !== $reply->id) {
                $media = $node;
            }
        }

        if (null === $media
            || [] !== $media->dependsOn
            || !in_array($media->capability, self::COLLAPSIBLE_MEDIA_CAPABILITIES, true)) {
            return $plan;
        }

        // compose_reply must be a pure pass-through of that single media node.
        if ([$media->id] !== $reply->dependsOn) {
            return $plan;
        }

        // Guard: the legacy router runs on the original classification, so only
        // collapse when that classification already targets this media.
        if ($this->mapper->capabilityForClassification($classification) !== $media->capability) {
            return $plan;
        }

        return TaskPlan::fromArray([
            'version' => 1,
            'language' => $plan->language,
            'reply_node' => $media->id,
            'tasks' => [[
                'id' => $media->id,
                'capability' => $media->capability->value,
                'depends_on' => [],
                'inputs' => $media->inputs,
                'params' => $media->params,
            ]],
        ]);
    }

    /**
     * Run the sequential DAG and persist per-node statuses.
     *
     * @param array<int, Message>  $thread
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $options
     *
     * @return array{content: string, files: list<array<string, mixed>>, metadata: array<string, mixed>, node_statuses: array<string, string>, node_job_keys: array<string, string>, partial_failure: bool, all_failed: bool}
     */
    private function runDag(
        Message $message,
        array $thread,
        array $classification,
        array $options,
        TaskPlanResult $plan,
        ?callable $progressCallback,
    ): array {
        $userId = $plan->modelId ? $this->modelConfigService->getEffectiveUserIdForMessage($message) : $message->getUserId();

        // Sibling awareness: give every runner the full set of capabilities in
        // this plan so a content node (chat/summarize) knows that media/file
        // delivery is handled by ANOTHER node and must not refuse or apologise
        // for it (e.g. "write a poem AND read it as MP3" — the chat node must
        // not say "I can't create audio").
        $planCapabilities = array_values(array_unique(array_map(
            static fn ($node) => $node->capability->value,
            $plan->plan->nodes,
        )));

        $context = new NodeContext($message, $thread, $userId, $classification, $options, $planCapabilities);

        // Announce the plan so the web client can render task cards up front. The
        // DagExecutor then emits task_update / task_chunk / task_file directly via
        // the same progress channel (all structured data rides in metadata, which
        // the SSE status callback forwards verbatim).
        if (null !== $progressCallback) {
            $progressCallback([
                'status' => 'plan',
                'message' => '',
                'metadata' => [
                    'plan' => $this->planForClient($plan->plan),
                    'reply_node' => $plan->plan->replyNode,
                ],
                'timestamp' => time(),
            ]);
        }

        $assembled = $this->dagExecutor->execute($plan->plan, $context, $progressCallback);

        $messageId = $message->getId();
        if (null !== $messageId) {
            try {
                $this->store->persistWithStatuses(
                    $messageId,
                    $plan->plan,
                    $plan->modelId,
                    $assembled['node_statuses'],
                    'pending',
                    $assembled['node_job_keys'],
                );
            } catch (\Throwable $e) {
                $this->logger->warning('TaskPlanExecutor: failed to persist DAG plan (ignored)', [
                    'message_id' => $messageId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $assembled;
    }

    /**
     * Tell the client to retract the task plan it rendered from the `plan` event.
     * Emitted right before the legacy fallback streams its answer so a fully
     * failed plan does not leave a misleading "step failed" card above the
     * correct reply. No-op off the streaming/progress channel.
     */
    private function discardPlan(?callable $progressCallback): void
    {
        if (null === $progressCallback) {
            return;
        }

        $progressCallback([
            'status' => 'plan_discarded',
            'message' => '',
            'metadata' => ['reason' => 'all_failed_fallback'],
            'timestamp' => time(),
        ]);
    }

    /**
     * Build the client-facing task list for the `plan` event. Hidden nodes
     * (compose_reply — the assembler) are excluded; they are not user cards.
     *
     * @return list<array{node_id: string, capability: string, kind: string}>
     */
    private function planForClient(TaskPlan $plan): array
    {
        $tasks = [];
        foreach ($plan->nodes as $node) {
            $kind = $node->capability->uiKind();
            if ('hidden' === $kind) {
                continue;
            }
            $tasks[] = [
                'node_id' => $node->id,
                'capability' => $node->capability->value,
                'kind' => $kind,
            ];
        }

        return $tasks;
    }

    /**
     * Convert the assembled DAG result into the handler-result shape that
     * StreamController/WebhookController persist. The primary file goes on the
     * legacy `metadata['file']` channel (unchanged single-file path); the full
     * list rides on `metadata['files']` for the additive multi-file branch.
     *
     * @param array{content: string, files: list<array<string, mixed>>, metadata: array<string, mixed>, node_statuses: array<string, string>, partial_failure: bool, all_failed: bool} $assembled
     *
     * @return array<string, mixed>
     */
    private function toHandlerResult(array $assembled): array
    {
        $metadata = $assembled['metadata'];
        $files = $assembled['files'];

        if ([] !== $files) {
            $metadata['file'] = $files[0];
            $metadata['files'] = $files;
        }

        return [
            'content' => $assembled['content'],
            'metadata' => $metadata,
        ];
    }

    /**
     * Run a single-node execution closure and persist the degenerate plan
     * (Sprint-2 behaviour: identical to the legacy router call).
     *
     * @param callable():array<string, mixed> $run
     * @param array<string, mixed>            $classification
     *
     * @return array<string, mixed>
     */
    private function runSingleNode(callable $run, Message $message, array $classification): array
    {
        $result = $run();
        $this->persistPlan($message, $classification, 'done');

        return $result;
    }

    /**
     * Round-trip the classification through a single-node plan so the executor
     * runs on the same array the router would have received. Any failure falls
     * back to the original classification — the answer must never break.
     *
     * @param array<string, mixed> $classification
     *
     * @return array<string, mixed>
     */
    private function effectiveClassification(array $classification): array
    {
        try {
            $plan = $this->mapper->toSingleNodePlan($classification);
            $node = $plan->nodes[0] ?? null;
            $recovered = $node ? $this->mapper->classificationFromNode($node) : null;

            return $recovered ?? $classification;
        } catch (\Throwable $e) {
            $this->logger->warning('TaskPlanExecutor: plan round-trip failed, using original classification', [
                'error' => $e->getMessage(),
            ]);

            return $classification;
        }
    }

    /**
     * @param array<string, mixed> $classification
     */
    private function persistPlan(Message $message, array $classification, string $status): void
    {
        try {
            $messageId = $message->getId();
            if (null === $messageId) {
                return;
            }
            $plan = $this->mapper->toSingleNodePlan($classification);
            $this->store->persist($messageId, $plan, null, $status);
        } catch (\Throwable $e) {
            $this->logger->warning('TaskPlanExecutor: failed to persist executed plan (ignored)', [
                'message_id' => $message->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
