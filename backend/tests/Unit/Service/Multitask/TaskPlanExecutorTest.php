<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask;

use App\Entity\Message;
use App\Service\Message\InferenceRouter;
use App\Service\Multitask\ClassificationPlanMapper;
use App\Service\Multitask\TaskPlanExecutor;
use App\Service\Multitask\TaskPlanStore;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class TaskPlanExecutorTest extends TestCase
{
    private InferenceRouter&MockObject $router;
    private TaskPlanStore&MockObject $store;
    private TaskPlanExecutor $executor;

    protected function setUp(): void
    {
        $this->router = $this->createMock(InferenceRouter::class);
        $this->store = $this->createMock(TaskPlanStore::class);
        // Real mapper so the round-trip is genuinely exercised.
        $this->executor = new TaskPlanExecutor(
            $this->router,
            new ClassificationPlanMapper(),
            $this->store,
            $this->createMock(LoggerInterface::class),
        );
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
}
