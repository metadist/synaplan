<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution\Runner;

use App\Entity\Message;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\Runner\ConditionRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\SavedTask\Graph\StepInputResolver;
use PHPUnit\Framework\TestCase;

final class ConditionRunnerTest extends TestCase
{
    public function testFalseConditionStopsWithoutFailing(): void
    {
        $runner = new ConditionRunner(new StepInputResolver());
        $node = new TaskNode('c1', Capability::Condition, [], [], [
            'operator' => 'equals',
            'value' => 'yes',
            'inputs' => ['input' => ['literal' => 'no']],
        ]);
        $result = $runner->run($node, $this->context());

        self::assertTrue($result->isStopped());
        self::assertFalse($result->isSuccessful());
    }

    public function testTrueConditionContinues(): void
    {
        $runner = new ConditionRunner(new StepInputResolver());
        $node = new TaskNode('c1', Capability::Condition, [], [], [
            'operator' => 'contains',
            'value' => 'mail',
            'inputs' => ['input' => ['literal' => 'inbox mail']],
        ]);
        $result = $runner->run($node, $this->context());

        self::assertTrue($result->isSuccessful());
    }

    private function context(): NodeContext
    {
        $message = new Message();
        $message->setUserId(1);

        return new NodeContext($message, [], 1, ['language' => 'en']);
    }
}
