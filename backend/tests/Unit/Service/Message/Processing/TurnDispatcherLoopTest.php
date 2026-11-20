<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Processing;

use App\Entity\Message;
use App\Service\Chat\Loop\ChatLoopGate;
use App\Service\Message\InferenceRouter;
use App\Service\Message\Processing\TurnDispatcher;
use App\Service\ModelConfigService;
use App\Service\Multitask\MultitaskRoutingConfig;
use App\Service\Multitask\TaskPlanExecutor;
use App\Service\Multitask\TaskPlanner;
use App\Service\Multitask\TaskPlanStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TurnDispatcherLoopTest extends TestCase
{
    public function testATurnOnTheLoopGoesToTheChatEvenWithMultiTaskRoutingOn(): void
    {
        $router = $this->createMock(InferenceRouter::class);
        $router->expects(self::once())->method('routeStream')->willReturn(['ok' => true]);
        $executor = $this->createMock(TaskPlanExecutor::class);
        $executor->expects(self::never())->method('executeStream');

        self::assertSame(['ok' => true], $this->dispatcher($router, $executor, true)->dispatchStreamed(new Message(), [], [], static function (): void {}, null, []));
    }

    public function testATurnOffTheLoopKeepsTheTaskPlanWhenMultiTaskRoutingIsOn(): void
    {
        $router = $this->createMock(InferenceRouter::class);
        $router->expects(self::never())->method('routeStream');
        $executor = $this->createMock(TaskPlanExecutor::class);
        $executor->expects(self::once())->method('executeStream')->willReturn(['plan' => true]);

        self::assertSame(['plan' => true], $this->dispatcher($router, $executor, false)->dispatchStreamed(new Message(), [], [], static function (): void {}, null, []));
    }

    private function dispatcher(InferenceRouter $router, TaskPlanExecutor $executor, bool $onLoop): TurnDispatcher
    {
        $multitask = $this->createStub(MultitaskRoutingConfig::class);
        $multitask->method('isRoutingEnabled')->willReturn(true);
        $loop = $this->createStub(ChatLoopGate::class);
        $loop->method('forTurn')->willReturn($onLoop);

        return new TurnDispatcher(
            $router,
            $executor,
            $this->createStub(TaskPlanner::class),
            $this->createStub(TaskPlanStore::class),
            $multitask,
            $this->createStub(ModelConfigService::class),
            new NullLogger(),
            $loop,
        );
    }
}
