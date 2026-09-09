<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SavedTask;

use App\Entity\Message;
use App\Repository\MessageRepository;
use App\Service\Multitask\TaskPlanExecutor;
use App\Service\SavedTask\Graph\SavedTaskGraphCapture;
use App\Service\SavedTask\Graph\SavedTaskGraphValidator;
use PHPUnit\Framework\TestCase;

final class SavedTaskGraphCaptureTest extends TestCase
{
    /** @var array<string, mixed> */
    private const DEFINITION = [
        'version' => 1,
        'language' => 'de',
        'reply_node' => 'n2',
        'tasks' => [
            ['id' => 'n1', 'capability' => 'url_fetch', 'depends_on' => [], 'inputs' => ['urls' => ['https://example.com/stock']], 'params' => []],
            ['id' => 'n2', 'capability' => 'chat', 'depends_on' => ['n1'], 'inputs' => ['text' => '$message.text $n1.text'], 'params' => ['topic_id' => 'general']],
            ['id' => 'n3', 'capability' => 'email_me', 'depends_on' => ['n2'], 'inputs' => ['text' => '$n2.text'], 'params' => []],
        ],
    ];

    public function testExecutedPlanBecomesAValidGraphWithInputsAndReply(): void
    {
        $capture = new SavedTaskGraphCapture($this->createStub(MessageRepository::class));

        $graph = $capture->fromDefinition(self::DEFINITION, 'manual');

        self::assertNotNull($graph);
        self::assertSame(1, $graph['version']);
        self::assertSame(['type' => 'manual'], $graph['trigger']);
        self::assertSame('n2', $graph['reply_node']);
        self::assertCount(3, $graph['nodes']);
        self::assertSame(['urls' => ['https://example.com/stock']], $graph['nodes'][0]['inputs']);
        self::assertSame(['topic_id' => 'general'], $graph['nodes'][1]['params']);
        self::assertSame(['n2'], $graph['nodes'][2]['depends_on']);
        // The captured graph must pass the same validator authored graphs do.
        self::assertSame([], (new SavedTaskGraphValidator())->validate($graph, 'manual', null));
    }

    public function testSingleStepPlanIsNotPinned(): void
    {
        $capture = new SavedTaskGraphCapture($this->createStub(MessageRepository::class));

        self::assertNull($capture->fromDefinition([
            'version' => 1,
            'reply_node' => 'n1',
            'tasks' => [['id' => 'n1', 'capability' => 'chat', 'depends_on' => [], 'inputs' => [], 'params' => []]],
        ], 'manual'));
    }

    public function testMalformedDefinitionIsRejected(): void
    {
        $capture = new SavedTaskGraphCapture($this->createStub(MessageRepository::class));

        self::assertNull($capture->fromDefinition(['tasks' => 'nope'], 'manual'));
        self::assertNull($capture->fromDefinition(['tasks' => [['capability' => 'chat']]], 'manual'));
    }

    public function testReadsTheDefinitionFromTheOwnersMessageOnly(): void
    {
        $message = new Message();
        $message->setUserId(9);
        (new \ReflectionProperty(Message::class, 'id'))->setValue($message, 4711);
        $message->setMeta(TaskPlanExecutor::PLAN_DEFINITION_META, (string) json_encode(self::DEFINITION));

        $messages = $this->createMock(MessageRepository::class);
        $messages->method('find')->with(4711)->willReturn($message);
        $capture = new SavedTaskGraphCapture($messages);

        $own = $capture->fromMessage(4711, 9, 'manual');
        self::assertNotNull($own);
        self::assertCount(3, $own['nodes']);

        // Another user's message id must never leak its plan into my task.
        self::assertNull($capture->fromMessage(4711, 10, 'manual'));
    }

    public function testMessageWithoutADagYieldsNoGraph(): void
    {
        $message = new Message();
        $message->setUserId(9);
        (new \ReflectionProperty(Message::class, 'id'))->setValue($message, 4712);

        $messages = $this->createMock(MessageRepository::class);
        $messages->method('find')->willReturn($message);

        self::assertNull((new SavedTaskGraphCapture($messages))->fromMessage(4712, 9, 'manual'));
    }
}
