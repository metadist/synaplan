<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SavedTask;

use App\Entity\SavedTask;
use App\Service\Multitask\Plan\Capability;
use App\Service\SavedTask\Graph\SavedTaskGraphValidator;
use App\Service\SavedTask\Graph\SavedTaskPlanFactory;
use PHPUnit\Framework\TestCase;

final class SavedTaskPlanFactoryTest extends TestCase
{
    public function testAuthoredUrlFetchKeepsInputs(): void
    {
        $task = new SavedTask(1, 12, 'Read the page');
        $task->setGraph([
            'version' => 1,
            'trigger' => ['type' => 'manual'],
            'nodes' => [
                [
                    'id' => 'n1',
                    'capability' => 'url_fetch',
                    'depends_on' => [],
                    'inputs' => ['urls' => 'https://example.com/news'],
                ],
                [
                    'id' => 'n2',
                    'capability' => 'chat',
                    'depends_on' => ['n1'],
                    'inputs' => ['text' => '$n1.text'],
                ],
            ],
        ]);

        $plan = (new SavedTaskPlanFactory(new SavedTaskGraphValidator()))->fromTask($task);

        $fetch = $plan->nodeById('n1');
        self::assertNotNull($fetch);
        self::assertSame(Capability::UrlFetch, $fetch->capability);
        self::assertSame(['urls' => 'https://example.com/news'], $fetch->inputs);

        $chat = $plan->nodeById('n2');
        self::assertNotNull($chat);
        self::assertSame(['text' => '$n1.text'], $chat->inputs);
        self::assertSame('12', $chat->params['prompt_id'] ?? null);
    }

    public function testDeclaredReplyNodeWinsOverTheLastStep(): void
    {
        // A captured chat plan answers with the chat step; the trailing
        // email_me confirmation must not become the reply just because it is
        // last in the list.
        $task = new SavedTask(1, 12, 'Stock mail');
        $task->setGraph([
            'version' => 1,
            'trigger' => ['type' => 'manual'],
            'reply_node' => 'n2',
            'nodes' => [
                ['id' => 'n1', 'capability' => 'url_fetch', 'depends_on' => [], 'inputs' => ['urls' => 'https://example.com/stock']],
                ['id' => 'n2', 'capability' => 'chat', 'depends_on' => ['n1'], 'inputs' => ['text' => '$n1.text']],
                ['id' => 'n3', 'capability' => 'email_me', 'depends_on' => ['n2'], 'inputs' => ['text' => '$n2.text']],
            ],
        ]);

        $plan = (new SavedTaskPlanFactory(new SavedTaskGraphValidator()))->fromTask($task);

        self::assertSame('n2', $plan->replyNode);
        self::assertCount(3, $plan->nodes);
    }

    public function testUnknownDeclaredReplyNodeFallsBackToTheLastStep(): void
    {
        $task = new SavedTask(1, 12, 'Stock mail');
        $task->setGraph([
            'version' => 1,
            'trigger' => ['type' => 'manual'],
            'reply_node' => 'missing',
            'nodes' => [
                ['id' => 'n1', 'capability' => 'url_fetch', 'depends_on' => [], 'inputs' => ['urls' => 'https://example.com/stock']],
                ['id' => 'n2', 'capability' => 'chat', 'depends_on' => ['n1'], 'inputs' => ['text' => '$n1.text']],
            ],
        ]);

        $plan = (new SavedTaskPlanFactory(new SavedTaskGraphValidator()))->fromTask($task);

        self::assertSame('n2', $plan->replyNode);
    }
}
