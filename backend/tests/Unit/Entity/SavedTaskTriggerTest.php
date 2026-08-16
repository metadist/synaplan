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

    /**
     * The webhook ingress endpoint does not exist yet (Sprint 4, E22+). A task
     * stored with this trigger could never fire, so the entity must reject it
     * until the route ships.
     */
    public function testRejectsWebhookTriggerUntilIngressExists(): void
    {
        $task = new SavedTask(1, 42, 'Meeting requests from mail');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported trigger type "webhook"');
        $task->setTrigger(SavedTask::TRIGGER_WEBHOOK, null);
    }
}
