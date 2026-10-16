<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask;

use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeStatus;
use App\Service\Multitask\Execution\Runner\AskUserRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use PHPUnit\Framework\TestCase;

final class AskUserRunnerTest extends TestCase
{
    public function testAQuestionPausesWithAtMostFiveOptions(): void
    {
        $result = (new AskUserRunner())->run($this->node([
            'question' => 'How often?',
            'options' => ['daily', 'weekly', 'monthly', 'yearly', 'never', 'extra'],
            'recommended' => 'daily',
        ]), $this->createStub(NodeContext::class));

        self::assertSame(NodeStatus::WaitingApproval, $result->status);
        $ask = $result->metadata['ask_user'];
        self::assertSame('How often?', $ask['question']);
        self::assertCount(5, $ask['options']);
        self::assertNotContains('extra', $ask['options']);
        self::assertSame('daily', $ask['recommended']);
        self::assertArrayHasKey('expiresAt', $ask);
    }

    public function testAnAnswerContinuesAsTheStepText(): void
    {
        $result = (new AskUserRunner())->run($this->node([
            'question' => 'How often?',
            'answer' => 'every 6 hours',
        ]), $this->createStub(NodeContext::class));

        self::assertTrue($result->isSuccessful());
        self::assertSame('every 6 hours', $result->text);
    }

    public function testAnEmptyQuestionFailsWithoutAsking(): void
    {
        $result = (new AskUserRunner())->run($this->node(['question' => '  ']), $this->createStub(NodeContext::class));

        self::assertSame(NodeStatus::Failed, $result->status);
    }

    private function node(array $params): TaskNode
    {
        return new TaskNode('n1', Capability::AskUser, [], [], $params);
    }
}
