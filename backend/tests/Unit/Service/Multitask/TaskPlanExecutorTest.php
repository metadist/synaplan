<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask;

use App\Entity\Message;
use App\Service\Message\InferenceRouter;
use App\Service\ModelConfigService;
use App\Service\Multitask\ClassificationPlanMapper;
use App\Service\Multitask\Execution\DagExecutor;
use App\Service\Multitask\Plan\TaskPlan;
use App\Service\Multitask\TaskPlanExecutor;
use App\Service\Multitask\TaskPlanner;
use App\Service\Multitask\TaskPlanResult;
use App\Service\Multitask\TaskPlanStore;
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
    private TaskPlanExecutor $executor;

    protected function setUp(): void
    {
        $this->router = $this->createMock(InferenceRouter::class);
        $this->store = $this->createMock(TaskPlanStore::class);
        $this->planner = $this->createMock(TaskPlanner::class);
        $this->dagExecutor = $this->createMock(DagExecutor::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        // Real mapper so the round-trip is genuinely exercised.
        $this->executor = new TaskPlanExecutor(
            $this->router,
            new ClassificationPlanMapper(),
            $this->store,
            $this->planner,
            $this->dagExecutor,
            $this->modelConfigService,
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
}
