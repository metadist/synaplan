<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\SavedTask;
use PHPUnit\Framework\TestCase;

final class SavedTaskTriggerTest extends TestCase
{
    public function testAcceptsEveryImplementedTriggerType(): void
    {
        $task = new SavedTask(1, 42, 'Meeting requests from mail');

        foreach (SavedTask::TRIGGER_TYPES as $type) {
            $task->setTrigger($type, null);
            self::assertSame($type, $task->getTriggerType());
        }
    }

    public function testAcceptsWebhookTriggerNowThatIngressExists(): void
    {
        $task = new SavedTask(1, 42, 'Meeting requests from mail');
        $task->setTrigger(SavedTask::TRIGGER_WEBHOOK, ['token' => 'abc']);

        self::assertSame(SavedTask::TRIGGER_WEBHOOK, $task->getTriggerType());
        self::assertContains(SavedTask::TRIGGER_WEBHOOK, SavedTask::TRIGGER_TYPES);
    }
}
