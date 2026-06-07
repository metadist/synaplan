<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Plan;

use App\Service\Multitask\Plan\TaskPlanValidator;
use PHPUnit\Framework\TestCase;

final class TaskPlanValidatorTest extends TestCase
{
    private TaskPlanValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new TaskPlanValidator();
    }

    public function testValidSingleNodePlan(): void
    {
        $plan = [
            'version' => 1,
            'language' => 'en',
            'reply_node' => 'n1',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'chat'],
            ],
        ];

        self::assertSame([], $this->validator->validate($plan));
    }

    public function testValidCanonicalFourNodeChain(): void
    {
        $plan = [
            'version' => 1,
            'language' => 'en',
            'reply_node' => 'n4',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'extract_text', 'inputs' => ['files' => '$message.files']],
                ['id' => 'n2', 'capability' => 'summarize', 'depends_on' => ['n1'], 'inputs' => ['text' => '$n1.text'], 'params' => ['style' => 'short']],
                ['id' => 'n3', 'capability' => 'text2sound', 'depends_on' => ['n2'], 'inputs' => ['text' => '$n2.text'], 'params' => ['format' => 'mp3']],
                ['id' => 'n4', 'capability' => 'compose_reply', 'depends_on' => ['n2', 'n3'], 'inputs' => ['text' => '$n2.text', 'attachments' => ['$n3.file']]],
            ],
        ];

        self::assertSame([], $this->validator->validate($plan));
    }

    public function testRejectsNonObject(): void
    {
        self::assertNotEmpty($this->validator->validate('not a plan'));
        self::assertNotEmpty($this->validator->validate(null));
    }

    public function testRejectsWrongVersion(): void
    {
        $errors = $this->validator->validate([
            'version' => 2,
            'reply_node' => 'n1',
            'tasks' => [['id' => 'n1', 'capability' => 'chat']],
        ]);

        self::assertContains('version must be 1', $errors);
    }

    public function testRejectsEmptyTasks(): void
    {
        $errors = $this->validator->validate([
            'version' => 1,
            'reply_node' => 'n1',
            'tasks' => [],
        ]);

        self::assertContains('tasks must be a non-empty array', $errors);
    }

    public function testRejectsUnknownCapability(): void
    {
        $errors = $this->validator->validate([
            'version' => 1,
            'reply_node' => 'n1',
            'tasks' => [['id' => 'n1', 'capability' => 'launch_missiles']],
        ]);

        self::assertNotEmpty(array_filter($errors, static fn (string $e): bool => str_contains($e, 'launch_missiles')));
    }

    public function testRejectsDuplicateIds(): void
    {
        $errors = $this->validator->validate([
            'version' => 1,
            'reply_node' => 'n1',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'chat'],
                ['id' => 'n1', 'capability' => 'summarize'],
            ],
        ]);

        self::assertContains("duplicate node id 'n1'", $errors);
    }

    public function testRejectsUnknownDependency(): void
    {
        $errors = $this->validator->validate([
            'version' => 1,
            'reply_node' => 'n1',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'summarize', 'depends_on' => ['ghost']],
            ],
        ]);

        self::assertContains("node 'n1' depends on unknown node 'ghost'", $errors);
    }

    public function testRejectsSelfDependency(): void
    {
        $errors = $this->validator->validate([
            'version' => 1,
            'reply_node' => 'n1',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'summarize', 'depends_on' => ['n1']],
            ],
        ]);

        self::assertContains("node 'n1' depends on itself", $errors);
    }

    public function testRejectsCycle(): void
    {
        $errors = $this->validator->validate([
            'version' => 1,
            'reply_node' => 'n1',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'summarize', 'depends_on' => ['n2']],
                ['id' => 'n2', 'capability' => 'summarize', 'depends_on' => ['n1']],
            ],
        ]);

        self::assertContains('plan contains a dependency cycle', $errors);
    }

    public function testRejectsMissingReplyNode(): void
    {
        $errors = $this->validator->validate([
            'version' => 1,
            'reply_node' => 'n9',
            'tasks' => [['id' => 'n1', 'capability' => 'chat']],
        ]);

        self::assertContains('reply_node must reference an existing node id', $errors);
    }

    public function testRejectsTooManyNodes(): void
    {
        $tasks = [];
        for ($i = 1; $i <= 30; ++$i) {
            $tasks[] = ['id' => "n{$i}", 'capability' => 'chat'];
        }

        $errors = $this->validator->validate([
            'version' => 1,
            'reply_node' => 'n1',
            'tasks' => $tasks,
        ]);

        self::assertNotEmpty(array_filter($errors, static fn (string $e): bool => str_contains($e, 'too many nodes')));
    }
}
