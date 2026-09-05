<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask;

use App\Entity\Message;
use App\Repository\ConfigRepository;
use App\Repository\PromptRepository;
use App\Repository\SavedTaskRepository;
use App\Service\Message\InferenceRouter;
use App\Service\ModelConfigService;
use App\Service\Multitask\ClassificationPlanMapper;
use App\Service\Multitask\Execution\DagExecutor;
use App\Service\Multitask\MultitaskRoutingConfig;
use App\Service\Multitask\Plan\TaskPlan;
use App\Service\Multitask\TaskPlanExecutor;
use App\Service\Multitask\TaskPlanner;
use App\Service\Multitask\TaskPlanResult;
use App\Service\Multitask\TaskPlanStore;
use App\Service\PerfTimer;
use App\Service\SavedTask\Graph\SavedTaskGraphValidator;
use App\Service\SavedTask\Graph\SavedTaskPlanFactory;
use App\Service\SavedTask\SavedTaskConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class TaskPlanExecutorTest extends TestCase
{
    private InferenceRouter&MockObject $router;
    private TaskPlanStore&MockObject $store;
    private TaskPlanner&MockObject $planner;
    private DagExecutor&MockObject $dagExecutor;
    private ModelConfigService&MockObject $modelConfigService;
    private MultitaskRoutingConfig&MockObject $multitaskConfig;
    private TaskPlanExecutor $executor;

    protected function setUp(): void
    {
        $this->router = $this->createMock(InferenceRouter::class);
        $this->store = $this->createMock(TaskPlanStore::class);
        $this->planner = $this->createMock(TaskPlanner::class);
        $this->dagExecutor = $this->createMock(DagExecutor::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->multitaskConfig = $this->createMock(MultitaskRoutingConfig::class);
        $this->multitaskConfig->method('planOnlyMultiStep')->willReturn(true);
        // Real mapper so the round-trip is genuinely exercised.
        $this->executor = new TaskPlanExecutor(
            $this->router,
            new ClassificationPlanMapper(),
            $this->store,
            $this->planner,
            $this->dagExecutor,
            $this->modelConfigService,
            $this->multitaskConfig,
            $this->createMock(LoggerInterface::class),
        );
    }

    /**
     * @param array{content?: string, files?: list<array<string,mixed>>, metadata?: array<string,mixed>, node_statuses?: array<string,string>, partial_failure?: bool, all_failed?: bool} $overrides
     *
     * @return array<string, mixed>
     */
    private function assembled(array $overrides = []): array
    {
        return array_merge([
            'content' => 'assembled answer',
            'files' => [],
            'metadata' => [],
            'node_statuses' => ['n1' => 'done'],
            'partial_failure' => false,
            'all_failed' => false,
        ], $overrides);
    }

    private function message(): Message&MockObject
    {
        $m = $this->createMock(Message::class);
        $m->method('getId')->willReturn(123);

        return $m;
    }

    public function testExecuteStreamDelegatesWithIdenticalClassification(): void
    {
        $classification = [
            'topic' => 'mediamaker', 'intent' => 'image_generation', 'language' => 'en',
            'media_type' => 'video', 'duration' => 8, 'resolution' => '720p',
            'override_model_id' => 7,
        ];
        $thread = [];
        $options = ['reasoning' => false];
        $streamCb = static function (): void {};
        $statusCb = static function (): void {};
        $expected = ['content' => 'streamed', 'metadata' => ['provider' => 'test']];

        $received = null;
        $this->router->expects(self::once())
            ->method('routeStream')
            ->willReturnCallback(function ($msg, $thr, $cls, $sc, $pc, $opt) use (&$received, $expected) {
                $received = $cls;

                return $expected;
            });

        $result = $this->executor->executeStream($this->message(), $thread, $classification, $streamCb, $statusCb, $options);

        // Behaviour identical: router gets the EXACT same classification.
        self::assertSame($classification, $received);
        self::assertSame($expected, $result);
    }

    public function testExecuteDelegatesWithIdenticalClassification(): void
    {
        $classification = ['topic' => 'general', 'intent' => 'chat', 'language' => 'de'];
        $expected = ['content' => 'hello', 'metadata' => []];

        $received = null;
        $this->router->expects(self::once())
            ->method('route')
            ->willReturnCallback(function ($msg, $thr, $cls) use (&$received, $expected) {
                $received = $cls;

                return $expected;
            });

        $result = $this->executor->execute($this->message(), [], $classification, null);

        self::assertSame($classification, $received);
        self::assertSame($expected, $result);
    }

    public function testPersistsExecutedPlan(): void
    {
        $this->router->method('routeStream')->willReturn(['content' => 'x']);

        $this->store->expects(self::once())
            ->method('persist')
            ->with(123, self::anything(), null, 'done');

        $this->executor->executeStream($this->message(), [], ['intent' => 'chat', 'language' => 'en'], static function (): void {});
    }

    public function testPersistFailureDoesNotBreakTurn(): void
    {
        $this->router->method('routeStream')->willReturn(['content' => 'answer']);
        $this->store->method('persist')->willThrowException(new \RuntimeException('db down'));

        $result = $this->executor->executeStream($this->message(), [], ['intent' => 'chat', 'language' => 'en'], static function (): void {});

        self::assertSame(['content' => 'answer'], $result);
    }

    public function testNonAiSortedMessageNeverRunsPlanner(): void
    {
        // Deterministic branches (fast-path/tool/widget/again) must not invoke the planner.
        $this->planner->expects(self::never())->method('plan');
        $this->router->method('routeStream')->willReturn(['content' => 'x']);

        $this->executor->executeStream(
            $this->message(),
            [],
            ['intent' => 'chat', 'language' => 'en', 'source' => 'fast_path_heuristic'],
            static function (): void {},
        );
    }

    public function testWidgetModeNeverRunsPlannerEvenWhenAiSorted(): void
    {
        // "Standard sorting" widgets run the real classifier (source=ai_sorting),
        // but the embedded widget client cannot render plan/task_* events —
        // widget conversations must stay on the single-node path (§3.4 invariant).
        $this->planner->expects(self::never())->method('plan');
        $this->router->expects(self::once())->method('routeStream')->willReturn(['content' => 'x']);

        $this->executor->executeStream(
            $this->message(),
            [],
            ['intent' => 'chat', 'language' => 'en', 'source' => 'ai_sorting', 'is_widget_mode' => true],
            static function (): void {},
        );
    }

    public function testMultiNodePlanRunsDagAndStreamsAssembledText(): void
    {
        $multiNode = TaskPlan::fromArray([
            'version' => 1, 'language' => 'en', 'reply_node' => 'n4',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'extract_text'],
                ['id' => 'n2', 'capability' => 'summarize', 'depends_on' => ['n1']],
                ['id' => 'n3', 'capability' => 'text2sound', 'depends_on' => ['n2']],
                ['id' => 'n4', 'capability' => 'compose_reply', 'depends_on' => ['n2', 'n3']],
            ],
        ]);
        $this->planner->method('plan')->willReturn(new TaskPlanResult($multiNode, fallback: false, modelId: 76));
        $this->dagExecutor->method('execute')->willReturn($this->assembled([
            'content' => 'SUMMARY',
            'files' => [['path' => '/api/v1/files/uploads/x.mp3', 'type' => 'audio']],
            'node_statuses' => ['n1' => 'done', 'n2' => 'done', 'n3' => 'done', 'n4' => 'done'],
        ]));

        // Router must NOT be used when the DAG succeeds.
        $this->router->expects(self::never())->method('routeStream');

        $streamed = '';
        $result = $this->executor->executeStream(
            $this->message(),
            [],
            ['intent' => 'chat', 'language' => 'en', 'source' => 'ai_sorting'],
            function (string $chunk) use (&$streamed): void { $streamed .= $chunk; },
        );

        self::assertSame('SUMMARY', $streamed);
        self::assertSame('SUMMARY', $result['content']);
        self::assertSame('audio', $result['metadata']['file']['type']);
        self::assertCount(1, $result['metadata']['files']);
    }

    public function testMultiNodeTotalFailureFallsBackToLegacyRouter(): void
    {
        $multiNode = TaskPlan::fromArray([
            'version' => 1, 'language' => 'en', 'reply_node' => 'n2',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'extract_text'],
                ['id' => 'n2', 'capability' => 'summarize', 'depends_on' => ['n1']],
            ],
        ]);
        $this->planner->method('plan')->willReturn(new TaskPlanResult($multiNode, fallback: false, modelId: 76));
        $this->dagExecutor->method('execute')->willReturn($this->assembled([
            'all_failed' => true,
            'node_statuses' => ['n1' => 'failed', 'n2' => 'skipped'],
        ]));

        // Whole-plan failure → legacy router answers.
        $this->router->expects(self::once())->method('routeStream')->willReturn(['content' => 'legacy answer']);

        $statuses = [];
        $result = $this->executor->executeStream(
            $this->message(),
            [],
            ['intent' => 'chat', 'language' => 'en', 'source' => 'ai_sorting'],
            static function (): void {},
            function (array $event) use (&$statuses): void { $statuses[] = $event['status'] ?? null; },
        );

        self::assertSame(['content' => 'legacy answer'], $result);
        // The UI must be told to retract the failed task cards before the clean
        // fallback answer streams.
        self::assertContains('plan_discarded', $statuses);
    }

    public function testSingleNodePlanFromPlannerUsesLegacyPath(): void
    {
        // Planner says single-node → trust the proven router path, no DAG.
        $this->planner->method('plan')->willReturn(new TaskPlanResult(TaskPlan::singleChatPlan('en'), fallback: false, modelId: 76));
        $this->dagExecutor->expects(self::never())->method('execute');
        $this->router->expects(self::once())->method('routeStream')->willReturn(['content' => 'router answer']);

        $result = $this->executor->executeStream(
            $this->message(),
            [],
            ['intent' => 'chat', 'language' => 'en', 'source' => 'ai_sorting'],
            static function (): void {},
        );

        self::assertSame(['content' => 'router answer'], $result);
    }

    public function testPlanningUsageSurvivesDelegationToLegacyRouter(): void
    {
        $planningUsage = [
            'promptTokens' => 40,
            'completionTokens' => 10,
            'totalTokens' => 50,
            'cost' => '0.001500',
            'modelKey' => 'openai:planner',
            'kind' => 'PLANNING',
        ];
        $this->planner->method('plan')->willReturn(new TaskPlanResult(
            TaskPlan::singleChatPlan('en'),
            fallback: false,
            modelId: 76,
            planningUsage: $planningUsage,
        ));
        $this->router->method('routeStream')->willReturn([
            'metadata' => [
                'provider' => 'openai',
                'model' => 'gpt-4o',
                'usage' => ['total_tokens' => 25],
            ],
        ]);

        $result = $this->executor->executeStream(
            $this->message(),
            [],
            ['intent' => 'chat', 'language' => 'en', 'source' => 'ai_sorting'],
            static function (): void {},
        );

        self::assertSame($planningUsage, $result['metadata']['planning_usage']);
        self::assertSame('openai', $result['metadata']['provider']);
        self::assertSame(['total_tokens' => 25], $result['metadata']['usage']);
    }

    public function testFileAttachmentMultiIntentRunsDag(): void
    {
        // Issue #1192: a non-image attachment carrying multiple intents
        // (summarize + image + TTS) must be planned, not reduced to a lone
        // file analysis. A multi-node plan runs the DAG.
        $multiNode = TaskPlan::fromArray([
            'version' => 1, 'language' => 'en', 'reply_node' => 'n4',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'file_analysis'],
                ['id' => 'n2', 'capability' => 'summarize', 'depends_on' => ['n1']],
                ['id' => 'n3', 'capability' => 'image_generation', 'depends_on' => ['n2']],
                ['id' => 'n4', 'capability' => 'compose_reply', 'depends_on' => ['n2', 'n3']],
            ],
        ]);
        $this->planner->expects(self::once())->method('plan')->willReturn(new TaskPlanResult($multiNode, fallback: false, modelId: 76));
        $this->dagExecutor->method('execute')->willReturn($this->assembled([
            'content' => 'Here is your summary and image',
            'files' => [['path' => '/api/v1/files/uploads/img.png', 'type' => 'image']],
            'node_statuses' => ['n1' => 'done', 'n2' => 'done', 'n3' => 'done', 'n4' => 'done'],
        ]));

        // The legacy single-node router must NOT be used for a multi-node plan.
        $this->router->expects(self::never())->method('routeStream');

        $result = $this->executor->executeStream(
            $this->message(),
            [],
            ['intent' => 'file_analysis', 'topic' => 'analyzefile', 'language' => 'en', 'source' => 'attachment_document_or_audio'],
            static function (): void {},
        );

        self::assertSame('Here is your summary and image', $result['content']);
        self::assertSame('image', $result['metadata']['file']['type']);
    }

    public function testSingleIntentFileAttachmentDelegatesToLegacyRouter(): void
    {
        // Issue #1192: a single-intent attachment still degrades to the proven
        // single-node path — the planner returns a single-node plan and the
        // legacy router answers (no DAG, behaviour preserved).
        $this->planner->expects(self::once())->method('plan')
            ->willReturn(new TaskPlanResult(TaskPlan::singleChatPlan('en'), fallback: true, modelId: 76));
        $this->dagExecutor->expects(self::never())->method('execute');
        $this->router->expects(self::once())->method('routeStream')->willReturn(['content' => 'file summary']);

        $result = $this->executor->executeStream(
            $this->message(),
            [],
            ['intent' => 'file_analysis', 'topic' => 'analyzefile', 'language' => 'en', 'source' => 'attachment_document_or_audio'],
            static function (): void {},
        );

        self::assertSame(['content' => 'file summary'], $result);
    }

    public function testSingleMediaPlusComposeReplyCollapsesToLegacyRouter(): void
    {
        // Issue #1072: the planner wraps a plain single-media request
        // ("make an image of a sunset") in [image_generation, compose_reply].
        // That redundant compose_reply must be collapsed so the request runs
        // the silent legacy path (no DAG, no task card) — like /pic.
        $wrapped = TaskPlan::fromArray([
            'version' => 1, 'language' => 'en', 'reply_node' => 'n2',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'image_generation', 'inputs' => ['prompt' => 'a sunset']],
                ['id' => 'n2', 'capability' => 'compose_reply', 'depends_on' => ['n1'], 'inputs' => ['text' => 'Here is your image.', 'attachments' => ['$n1.file']]],
            ],
        ]);
        $this->planner->method('plan')->willReturn(new TaskPlanResult($wrapped, fallback: false, modelId: 76));

        // Collapsed to single-node → DAG must NOT run; legacy router answers.
        $this->dagExecutor->expects(self::never())->method('execute');
        $this->router->expects(self::once())->method('routeStream')->willReturn(['content' => 'IMAGE']);

        $result = $this->executor->executeStream(
            $this->message(),
            [],
            ['intent' => 'image_generation', 'media_type' => 'image', 'topic' => 'mediamaker', 'language' => 'en', 'source' => 'ai_sorting'],
            static function (): void {},
        );

        self::assertSame(['content' => 'IMAGE'], $result);
    }

    public function testSingleMediaPlanNotCollapsedWhenClassificationDisagrees(): void
    {
        // Guard: the legacy router runs on the ORIGINAL classification. If the
        // sorter said "chat" but the planner produced an image, collapsing to
        // the legacy path would lose the image — so the DAG must still run.
        $wrapped = TaskPlan::fromArray([
            'version' => 1, 'language' => 'en', 'reply_node' => 'n2',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'image_generation', 'inputs' => ['prompt' => 'a sunset']],
                ['id' => 'n2', 'capability' => 'compose_reply', 'depends_on' => ['n1'], 'inputs' => ['text' => 'Here is your image.', 'attachments' => ['$n1.file']]],
            ],
        ]);
        $this->planner->method('plan')->willReturn(new TaskPlanResult($wrapped, fallback: false, modelId: 76));
        $this->dagExecutor->expects(self::once())->method('execute')->willReturn($this->assembled([
            'content' => 'Here is your image',
            'files' => [['path' => '/api/v1/files/uploads/img.png', 'type' => 'image']],
            'node_statuses' => ['n1' => 'done', 'n2' => 'done'],
        ]));

        // classification intent = chat → does NOT map to image_generation.
        $this->router->expects(self::never())->method('routeStream');

        $result = $this->executor->executeStream(
            $this->message(),
            [],
            ['intent' => 'chat', 'language' => 'en', 'source' => 'ai_sorting'],
            static function (): void {},
        );

        self::assertSame('image', $result['metadata']['file']['type']);
    }

    public function testMediaEditChainIsNotCollapsed(): void
    {
        // A dependent media node ("make a logo, then make it blue") is a genuine
        // two-step chain — it must keep running the DAG, not be collapsed.
        $chain = TaskPlan::fromArray([
            'version' => 1, 'language' => 'en', 'reply_node' => 'n3',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'image_generation', 'inputs' => ['prompt' => 'a logo']],
                ['id' => 'n2', 'capability' => 'image_generation', 'depends_on' => ['n1'], 'inputs' => ['prompt' => 'make it blue', 'image' => '$n1.file']],
                ['id' => 'n3', 'capability' => 'compose_reply', 'depends_on' => ['n2'], 'inputs' => ['attachments' => ['$n2.file']]],
            ],
        ]);
        $this->planner->method('plan')->willReturn(new TaskPlanResult($chain, fallback: false, modelId: 76));
        $this->dagExecutor->expects(self::once())->method('execute')->willReturn($this->assembled([
            'content' => 'Here is the blue logo',
            'files' => [['path' => '/api/v1/files/uploads/logo.png', 'type' => 'image']],
            'node_statuses' => ['n1' => 'done', 'n2' => 'done', 'n3' => 'done'],
        ]));
        $this->router->expects(self::never())->method('routeStream');

        $result = $this->executor->executeStream(
            $this->message(),
            [],
            ['intent' => 'image_generation', 'media_type' => 'image', 'language' => 'en', 'source' => 'ai_sorting'],
            static function (): void {},
        );

        self::assertSame('image', $result['metadata']['file']['type']);
    }

    public function testSingleCalendarEventNodeRunsDagNotLegacyRouter(): void
    {
        // A lone calendar_event has NO legacy router equivalent — running it
        // through the legacy router (with the calendar-unaware classification)
        // degrades it into a plain chat answer. It MUST run the DAG instead.
        $singleCalendar = TaskPlan::fromArray([
            'version' => 1, 'language' => 'de', 'reply_node' => 'n1',
            'tasks' => [[
                'id' => 'n1',
                'capability' => 'calendar_event',
                'params' => ['title' => 'Meeting mit Oliver', 'start' => '2026-06-10T13:30:00', 'timezone' => 'UTC'],
            ]],
        ]);
        $this->planner->method('plan')->willReturn(new TaskPlanResult($singleCalendar, fallback: false, modelId: 76));
        $this->dagExecutor->expects(self::once())->method('execute')->willReturn($this->assembled([
            'content' => 'Calendar invite "Meeting mit Oliver"',
            'files' => [['path' => '/api/v1/files/uploads/meeting.ics', 'type' => 'document']],
        ]));

        // The legacy router must NOT be touched.
        $this->router->expects(self::never())->method('routeStream');

        $streamed = '';
        $result = $this->executor->executeStream(
            $this->message(),
            [],
            ['intent' => 'chat', 'language' => 'de', 'source' => 'ai_sorting'],
            function (string $chunk) use (&$streamed): void { $streamed .= $chunk; },
        );

        self::assertSame('Calendar invite "Meeting mit Oliver"', $result['content']);
        self::assertSame('document', $result['metadata']['file']['type']);
    }

    public function testAttachmentDocumentCombineRunsDagWithoutPlannerOrLegacyRouter(): void
    {
        $this->planner->expects(self::never())->method('plan');
        $this->dagExecutor->expects(self::once())->method('execute')->willReturn($this->assembled([
            'content' => 'Combined PDF created: Finanzmodell_combined.pdf',
            'files' => [['path' => '/api/v1/files/uploads/combined.pdf', 'type' => 'document']],
        ]));
        $this->router->expects(self::never())->method('routeStream');

        $result = $this->executor->executeStream(
            $this->message(),
            [],
            [
                'topic' => 'officemaker',
                'intent' => 'document_combine',
                'language' => 'de',
                'source' => 'attachment_document_combine',
                'skip_sorting' => true,
            ],
            static function (): void {},
        );

        self::assertSame('Combined PDF created: Finanzmodell_combined.pdf', $result['content']);
        self::assertSame('document', $result['metadata']['file']['type']);
    }

    /**
     * A failed merge (no office engine, missing pdfunite, file gone) must not
     * reach the legacy router as `document_combine`: there is no handler for
     * that intent, so ChatHandler would answer without a matching prompt and
     * claim a PDF that does not exist (#1694). The fallback is the attachment
     * analysis these turns had before the merge route existed.
     */
    public function testFailedDocumentCombineFallsBackToFileAnalysisNotAPromptlessChat(): void
    {
        $this->dagExecutor->method('execute')->willReturn($this->assembled([
            'all_failed' => true,
            'node_statuses' => ['n1' => 'failed'],
        ]));

        $delegated = null;
        $this->router->expects(self::once())
            ->method('routeStream')
            ->willReturnCallback(function (...$args) use (&$delegated): array {
                $delegated = $args[2];

                return ['content' => 'file analysis answer'];
            });

        $result = $this->executor->executeStream(
            $this->message(),
            [],
            [
                'topic' => 'officemaker',
                'intent' => 'document_combine',
                'language' => 'de',
                'source' => 'attachment_document_combine',
                'skip_sorting' => true,
            ],
            static function (): void {},
        );

        self::assertSame(['content' => 'file analysis answer'], $result);
        self::assertSame('analyzefile', $delegated['topic'] ?? null);
        self::assertSame('file_analysis', $delegated['intent'] ?? null);
        self::assertSame('attachment_document_or_audio', $delegated['source'] ?? null);
        self::assertSame('de', $delegated['language'] ?? null, 'The rest of the classification survives');
    }

    public function testSavedTaskRunPlansAndRunsTheDag(): void
    {
        // Regression: Saved Task runs (source=saved_task) pin their prompt and
        // skip the sorter, but the stored instruction is a full user turn.
        // Before the fix the source check rejected them, so "make an image of a
        // cat and save it to Nextcloud" reran as a plain chat answer — nothing
        // was generated, nothing was saved.
        $multiNode = TaskPlan::fromArray([
            'version' => 1, 'language' => 'de', 'reply_node' => 'n3',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'image_generation', 'inputs' => ['prompt' => 'a cat']],
                ['id' => 'n2', 'capability' => 'save_to_folder', 'depends_on' => ['n1'], 'inputs' => ['attachments' => ['$n1.file']], 'params' => ['channel' => 'nextcloud']],
                ['id' => 'n3', 'capability' => 'compose_reply', 'depends_on' => ['n1'], 'inputs' => ['attachments' => ['$n1.file']]],
            ],
        ]);
        $this->planner->expects(self::once())->method('plan')
            ->willReturn(new TaskPlanResult($multiNode, fallback: false, modelId: 76));
        $this->dagExecutor->expects(self::once())->method('execute')->willReturn($this->assembled([
            'content' => 'Bild gespeichert.',
            'files' => [['path' => '/api/v1/files/uploads/cat.png', 'type' => 'image']],
            'node_statuses' => ['n1' => 'done', 'n2' => 'done', 'n3' => 'done'],
        ]));
        $this->router->expects(self::never())->method('route');

        $result = $this->executor->execute(
            $this->message(),
            [],
            ['intent' => 'chat', 'topic' => 'saved-123', 'language' => 'en', 'source' => 'saved_task'],
        );

        self::assertSame('Bild gespeichert.', $result['content']);
        self::assertSame('image', $result['metadata']['file']['type']);
    }

    public function testSavedTaskSingleStepInstructionDelegatesToLegacyRouter(): void
    {
        // A simple stored instruction ("summarize the news") yields a
        // single-node plan → the proven legacy router answers, no DAG.
        $this->planner->expects(self::once())->method('plan')
            ->willReturn(new TaskPlanResult(TaskPlan::singleChatPlan('en'), fallback: false, modelId: 76));
        $this->dagExecutor->expects(self::never())->method('execute');
        $this->router->expects(self::once())->method('route')->willReturn(['content' => 'router answer']);

        $result = $this->executor->execute(
            $this->message(),
            [],
            ['intent' => 'chat', 'topic' => 'saved-123', 'language' => 'en', 'source' => 'saved_task'],
        );

        self::assertSame('router answer', $result['content']);
    }

    public function testSavedTaskRunExecutesItsAuthoredStepsRegardlessOfTriggerType(): void
    {
        // A task with authored "Advanced steps" must run EXACTLY those steps on
        // every run — manual, schedule, or inbound email. The chat-trigger-only
        // lookup used for chat turns would miss it and hand the instruction to
        // the free-form planner, which may plan different steps.
        $prompt = $this->createMock(\App\Entity\Prompt::class);
        $prompt->method('getId')->willReturn(4);

        $task = new \App\Entity\SavedTask(9, 4, 'Wochenreport');
        $task->setTrigger(\App\Entity\SavedTask::TRIGGER_SCHEDULE, ['kind' => 'interval', 'every_minutes' => 60]);
        $task->setGraph([
            'version' => 1,
            'trigger' => ['type' => 'schedule'],
            'nodes' => [
                ['id' => 'n1', 'capability' => 'image_generation', 'params' => ['prompt' => 'a cat']],
                ['id' => 'n2', 'capability' => 'save_to_folder', 'depends_on' => ['n1'], 'params' => ['channel' => 'nextcloud']],
                ['id' => 'n3', 'capability' => 'compose_reply', 'depends_on' => ['n1'], 'params' => []],
            ],
        ]);

        $configRepo = $this->createMock(ConfigRepository::class);
        $configRepo->method('getValue')->willReturn('true');

        $prompts = $this->createMock(PromptRepository::class);
        $prompts->expects(self::once())->method('findByTopicAndUser')->with('saved-4', 9)->willReturn($prompt);

        $savedTasks = $this->createMock(SavedTaskRepository::class);
        $savedTasks->expects(self::once())->method('findEnabledGraphTaskForPrompt')->with(4, 9)->willReturn($task);
        $savedTasks->expects(self::never())->method('findEnabledChatTaskForPrompt');

        $this->modelConfigService->method('getEffectiveUserIdForMessage')->willReturn(9);

        $executor = new TaskPlanExecutor(
            $this->router,
            new ClassificationPlanMapper(),
            $this->store,
            $this->planner,
            $this->dagExecutor,
            $this->modelConfigService,
            $this->multitaskConfig,
            $this->createMock(LoggerInterface::class),
            new SavedTaskConfig($configRepo),
            $savedTasks,
            $prompts,
            new SavedTaskPlanFactory(new SavedTaskGraphValidator()),
        );

        // The pinned graph is used verbatim: no planner round-trip, DAG runs.
        $this->planner->expects(self::never())->method('plan');
        $this->dagExecutor->expects(self::once())->method('execute')->willReturn($this->assembled([
            'content' => 'Report gespeichert.',
            'files' => [['path' => '/api/v1/files/uploads/cat.png', 'type' => 'image']],
            'node_statuses' => ['n1' => 'done', 'n2' => 'done', 'n3' => 'done'],
        ]));
        $this->router->expects(self::never())->method('route');

        $result = $executor->execute(
            $this->message(),
            [],
            ['intent' => 'chat', 'topic' => 'saved-4', 'language' => 'en', 'source' => 'saved_task'],
        );

        self::assertSame('Report gespeichert.', $result['content']);
        self::assertSame('image', $result['metadata']['file']['type']);
    }

    public function testSorterVoteOfASingleStepSkipsThePlanner(): void
    {
        // The whole point of the vote: on a one-step turn the planner
        // would only produce a single-node plan that we hand straight back to
        // the legacy router, so the round-trip is pure latency.
        $this->planner->expects(self::never())->method('plan');
        $this->router->expects(self::once())->method('routeStream')->willReturn(['content' => 'router answer']);

        $result = $this->executor->executeStream(
            $this->message(),
            [],
            ['intent' => 'chat', 'language' => 'en', 'source' => 'ai_sorting', 'multi_step' => false],
            static function (): void {},
        );

        self::assertSame(['content' => 'router answer'], $result);
    }

    public function testSorterVoteOfMultipleStepsStillPlans(): void
    {
        $this->planner->expects(self::once())
            ->method('plan')
            ->willReturn(new TaskPlanResult(TaskPlan::singleChatPlan('en'), fallback: false, modelId: 76));
        $this->router->method('routeStream')->willReturn(['content' => 'router answer']);

        $this->executor->executeStream(
            $this->message(),
            [],
            ['intent' => 'chat', 'language' => 'en', 'source' => 'ai_sorting', 'multi_step' => true],
            static function (): void {},
        );
    }

    public function testMissingSorterVoteStillPlans(): void
    {
        // No vote (older seeded prompt, a SORT model that dropped the field, or
        // a branch that never ran the sorter) must keep the pre-vote behaviour.
        $this->planner->expects(self::once())
            ->method('plan')
            ->willReturn(new TaskPlanResult(TaskPlan::singleChatPlan('en'), fallback: false, modelId: 76));
        $this->router->method('routeStream')->willReturn(['content' => 'router answer']);

        $this->executor->executeStream(
            $this->message(),
            [],
            ['intent' => 'chat', 'language' => 'en', 'source' => 'ai_sorting'],
            static function (): void {},
        );
    }

    public function testDisabledFlagPlansEvenOnASingleStepVote(): void
    {
        // PLAN_ONLY_MULTI_STEP is the kill switch: turning it off must put
        // every AI-sorted turn back through the planner.
        $config = $this->createMock(MultitaskRoutingConfig::class);
        $config->method('planOnlyMultiStep')->willReturn(false);
        $executor = new TaskPlanExecutor(
            $this->router,
            new ClassificationPlanMapper(),
            $this->store,
            $this->planner,
            $this->dagExecutor,
            $this->modelConfigService,
            $config,
            $this->createMock(LoggerInterface::class),
        );

        $this->planner->expects(self::once())
            ->method('plan')
            ->willReturn(new TaskPlanResult(TaskPlan::singleChatPlan('en'), fallback: false, modelId: 76));
        $this->router->method('routeStream')->willReturn(['content' => 'router answer']);

        $executor->executeStream(
            $this->message(),
            [],
            ['intent' => 'chat', 'language' => 'en', 'source' => 'ai_sorting', 'multi_step' => false],
            static function (): void {},
        );
    }

    public function testPlanningEmitsAProgressStatusBeforeTheBlockingCall(): void
    {
        // Without this the UI shows "Generating response…" for the whole
        // planner round-trip and looks stuck.
        $this->planner->method('plan')->willReturn(new TaskPlanResult(TaskPlan::singleChatPlan('en'), fallback: false, modelId: 76));
        $this->router->method('routeStream')->willReturn(['content' => 'router answer']);

        $statuses = [];
        $this->executor->executeStream(
            $this->message(),
            [],
            ['intent' => 'chat', 'language' => 'en', 'source' => 'ai_sorting', 'multi_step' => true],
            static function (): void {},
            function (array $event) use (&$statuses): void { $statuses[] = $event['status'] ?? null; },
        );

        self::assertContains('planning', $statuses);
    }

    public function testSkippedPlanningEmitsNoPlanningStatus(): void
    {
        $this->router->method('routeStream')->willReturn(['content' => 'router answer']);

        $statuses = [];
        $this->executor->executeStream(
            $this->message(),
            [],
            ['intent' => 'chat', 'language' => 'en', 'source' => 'ai_sorting', 'multi_step' => false],
            static function (): void {},
            function (array $event) use (&$statuses): void { $statuses[] = $event['status'] ?? null; },
        );

        self::assertNotContains('planning', $statuses);
    }

    public function testAnonymousMessageSkipsSavedTaskLookupInsteadOfFatalling(): void
    {
        // Regression: WhatsApp senders without an account have no effective
        // user id. The Saved-Task short-circuit must bail out instead of
        // passing null into PromptRepository::findByTopicAndUser(int) — that
        // TypeError 500'd every inbound WhatsApp webhook.
        $prompts = $this->createMock(PromptRepository::class);
        $prompts->expects(self::never())->method('findByTopicAndUser');

        $this->modelConfigService->method('getEffectiveUserIdForMessage')->willReturn(null);

        $executor = new TaskPlanExecutor(
            $this->router,
            new ClassificationPlanMapper(),
            $this->store,
            $this->planner,
            $this->dagExecutor,
            $this->modelConfigService,
            $this->multitaskConfig,
            $this->createMock(LoggerInterface::class),
            new SavedTaskConfig($this->createMock(ConfigRepository::class)),
            $this->createMock(SavedTaskRepository::class),
            $prompts,
            new SavedTaskPlanFactory(new SavedTaskGraphValidator()),
        );

        $this->planner->method('plan')
            ->willReturn(new TaskPlanResult(TaskPlan::singleChatPlan('en'), fallback: false, modelId: 76));
        $this->router->expects(self::once())->method('routeStream')->willReturn(['content' => 'router answer']);

        $result = $executor->executeStream(
            $this->message(),
            [],
            ['intent' => 'chat', 'topic' => 'general', 'language' => 'en', 'source' => 'ai_sorting', 'multi_step' => true],
            static function (): void {},
        );

        self::assertSame(['content' => 'router answer'], $result);
    }

    public function testPlannerCallIsRecordedAsItsOwnPerfPhase(): void
    {
        // The planner is a blocking LLM round-trip in front of the answer
        // model, so it has to show up separately in the `perf` SSE event
        // instead of hiding inside `handler_total`.
        $this->planner->method('plan')->willReturnCallback(function (): TaskPlanResult {
            usleep(20_000);

            return new TaskPlanResult(TaskPlan::singleChatPlan('en'), fallback: false, modelId: 76);
        });
        $this->router->method('routeStream')->willReturn(['content' => 'router answer']);

        $perfTimer = new PerfTimer();
        $this->executor->executeStream(
            $this->message(),
            [],
            ['intent' => 'chat', 'language' => 'en', 'source' => 'ai_sorting'],
            static function (): void {},
            null,
            ['perf_timer' => $perfTimer],
        );

        self::assertArrayHasKey('plan', $perfTimer->totals());
        self::assertGreaterThan(10.0, $perfTimer->totals()['plan']);
    }

    public function testPlanPhaseIsClosedWhenThePlannerThrows(): void
    {
        // A planner failure degrades to the legacy router; the phase must not
        // stay open, otherwise it silently vanishes from the perf payload.
        $this->planner->method('plan')->willThrowException(new \RuntimeException('planner down'));
        $this->router->expects(self::once())->method('routeStream')->willReturn(['content' => 'legacy answer']);

        $perfTimer = new PerfTimer();
        $this->executor->executeStream(
            $this->message(),
            [],
            ['intent' => 'chat', 'language' => 'en', 'source' => 'ai_sorting'],
            static function (): void {},
            null,
            ['perf_timer' => $perfTimer],
        );

        self::assertArrayHasKey('plan', $perfTimer->totals());
    }

    public function testSkippedPlanningRecordsNoPlanPhase(): void
    {
        // Deterministic branches never reach the planner, so they must not
        // report a (zero) planning cost either.
        $this->planner->expects(self::never())->method('plan');
        $this->router->method('routeStream')->willReturn(['content' => 'x']);

        $perfTimer = new PerfTimer();
        $this->executor->executeStream(
            $this->message(),
            [],
            ['intent' => 'chat', 'language' => 'en', 'source' => 'tool_command'],
            static function (): void {},
            null,
            ['perf_timer' => $perfTimer],
        );

        self::assertArrayNotHasKey('plan', $perfTimer->totals());
    }
}
