<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Media;

use App\Entity\Message;
use App\Repository\MessageRepository;
use App\Service\Media\GeneratedFileRegistrar;
use App\Service\Media\MediaDependentPlanContinuation;
use App\Service\Media\MediaJob;
use App\Service\Media\MediaJobRebinder;
use App\Service\Media\MediaJobService;
use App\Service\Multitask\Execution\DagExecutor;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeContextRehydrator;
use App\Service\Multitask\Plan\TaskPlan;
use App\Service\Multitask\TaskPlanExecutor;
use App\Service\Multitask\TaskPlanStore;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class MediaDependentPlanContinuationTest extends TestCase
{
    public function testAProcessingStepThatReadsTheRenderNeedsContinuation(): void
    {
        $plan = TaskPlan::fromArray([
            'version' => 1,
            'language' => 'en',
            'reply_node' => 'n3',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'image_generation', 'inputs' => ['prompt' => 'a cat']],
                ['id' => 'n2', 'capability' => 'file_analysis', 'depends_on' => ['n1'], 'inputs' => ['file' => '$n1.file']],
                ['id' => 'n3', 'capability' => 'compose_reply', 'depends_on' => ['n1', 'n2']],
            ],
        ]);

        self::assertTrue(MediaDependentPlanContinuation::stepNeedsThisRender($plan, 'n1'));
    }

    public function testDeliveryAloneDoesNotContinueThePlan(): void
    {
        $plan = TaskPlan::fromArray([
            'version' => 1,
            'language' => 'en',
            'reply_node' => 'n2',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'image_generation', 'inputs' => ['prompt' => 'a cat']],
                ['id' => 'n2', 'capability' => 'compose_reply', 'depends_on' => ['n1']],
            ],
        ]);

        self::assertFalse(MediaDependentPlanContinuation::stepNeedsThisRender($plan, 'n1'));
    }

    public function testABusyPlanLockIsLeftForTheCallerToRetry(): void
    {
        $store = new InMemoryStore();
        $held = (new LockFactory($store))->createLock('media-plan:1001');
        self::assertTrue($held->acquire());
        $dag = $this->createMock(DagExecutor::class);
        $dag->expects(self::never())->method('execute');

        $continued = $this->continuation($store, $dag)->continue(1001, 'n1', 'job-1');

        self::assertFalse($continued);
    }

    public function testTheResumedRenderKeepsItsTrackAndRebindsTheNextJob(): void
    {
        $seen = null;
        $dag = $this->createMock(DagExecutor::class);
        $dag->method('execute')->willReturnCallback(function (TaskPlan $plan, NodeContext $context) use (&$seen): array {
            $seen = $context;

            return [
                'content' => '',
                'files' => [],
                'node_statuses' => ['n1' => 'done'],
                'node_job_keys' => ['n2' => 'job-next'],
                'partial_failure' => false,
                'all_failed' => false,
            ];
        });
        $rebinder = $this->createMock(MediaJobRebinder::class);
        $rebinder->expects(self::once())->method('rebind')->with('job-next', 1001);

        $continued = $this->continuation(new InMemoryStore(), $dag, $rebinder)->continue(1001, 'n1', 'job-1');

        self::assertTrue($continued);
        self::assertInstanceOf(NodeContext::class, $seen);
        self::assertSame(['track_id' => 'track-1'], $seen->options);
    }

    private function continuation(InMemoryStore $store, DagExecutor $dag, ?MediaJobRebinder $rebinder = null): MediaDependentPlanContinuation
    {
        $job = (new MediaJob('job-1'))
            ->setStatus(MediaJob::STATUS_COMPLETED)
            ->setTrackId('track-1')
            ->setOptions(['planMessageId' => 1001])
            ->setResult(['file' => ['url' => '/api/v1/files/uploads/a.png', 'type' => 'image']]);
        $message = $this->createStub(Message::class);
        $message->method('getId')->willReturn(1001);
        $message->method('getUserId')->willReturn(7);
        $message->method('getDirection')->willReturn('OUT');
        $message->method('getMeta')->willReturnCallback(
            static fn (string $key): ?string => TaskPlanExecutor::PLAN_DEFINITION_META === $key
                ? (string) json_encode([
                    'version' => 1,
                    'language' => 'en',
                    'reply_node' => 'n3',
                    'tasks' => [
                        ['id' => 'n1', 'capability' => 'image_generation', 'inputs' => ['prompt' => 'a cat']],
                        ['id' => 'n2', 'capability' => 'video_generation', 'depends_on' => ['n1'], 'inputs' => ['prompt' => 'animate it']],
                        ['id' => 'n3', 'capability' => 'compose_reply', 'depends_on' => ['n2']],
                    ],
                ])
                : null,
        );
        $messages = $this->createStub(MessageRepository::class);
        $messages->method('find')->willReturn($message);
        $jobs = $this->createStub(MediaJobService::class);
        $jobs->method('findByKey')->willReturn($job);
        $rehydrator = $this->createStub(NodeContextRehydrator::class);
        $rehydrator->method('fromMessage')->willReturnCallback(
            static fn (Message $source, array $options): NodeContext => new NodeContext($source, [], 7, [], $options),
        );

        return new MediaDependentPlanContinuation(
            $messages,
            $jobs,
            $rehydrator,
            $dag,
            $this->createStub(TaskPlanStore::class),
            $this->createStub(GeneratedFileRegistrar::class),
            $this->createStub(EntityManagerInterface::class),
            new LockFactory($store),
            $rebinder ?? $this->createStub(MediaJobRebinder::class),
            new NullLogger(),
        );
    }
}
