<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SavedTask;

use App\Entity\SavedTask;
use PHPUnit\Framework\TestCase;

final class SavedTaskFailureAccountingTest extends TestCase
{
    public function testThirdFailureAutoPauses(): void
    {
        $task = new SavedTask(7, 3, 'Meeting requests');
        $task->recordFailure();
        $task->recordFailure();
        $this->assertTrue($task->isEnabled());
        $task->recordFailure();
        $this->assertFalse($task->isEnabled());
        $this->assertTrue($task->isAutoPaused());
        $this->assertSame(3, $task->getConsecutiveFailures());
    }

    public function testSuccessResetsCounter(): void
    {
        $task = new SavedTask(7, 3, 'Meeting requests');
        $task->recordFailure();
        $task->recordSuccess();
        $this->assertSame(0, $task->getConsecutiveFailures());
        $this->assertTrue($task->isEnabled());
    }

    public function testResumeClearsPause(): void
    {
        $task = new SavedTask(7, 3, 'Meeting requests');
        $task->recordFailure();
        $task->recordFailure();
        $task->recordFailure();
        $task->resume();
        $this->assertTrue($task->isEnabled());
        $this->assertSame(0, $task->getConsecutiveFailures());
        $this->assertFalse($task->isAutoPaused());
    }
}
