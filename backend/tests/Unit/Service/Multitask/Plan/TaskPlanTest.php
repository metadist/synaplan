<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Plan;

use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\InvalidTaskPlanException;
use App\Service\Multitask\Plan\TaskPlan;
use PHPUnit\Framework\TestCase;

final class TaskPlanTest extends TestCase
{
    public function testSingleChatPlanIsDegenerate(): void
    {
        $plan = TaskPlan::singleChatPlan('de');

        self::assertTrue($plan->isSingleNode());
        self::assertSame('de', $plan->language);
        self::assertSame('n1', $plan->replyNode);
        self::assertSame(Capability::Chat, $plan->nodes[0]->capability);
    }

    public function testSingleChatPlanCarriesCustomTopic(): void
    {
        // Migration carrier: a custom user topic keeps its prompt id so the
        // PromptMeta.aiModel pin survives downstream.
        $plan = TaskPlan::singleChatPlan('en', topicId: 'legal-review', promptId: 'legal-review');

        self::assertSame('legal-review', $plan->nodes[0]->params['topic_id']);
        self::assertSame('legal-review', $plan->nodes[0]->params['prompt_id']);
    }

    public function testFromArrayBuildsNodes(): void
    {
        $plan = TaskPlan::fromArray([
            'version' => 1,
            'language' => 'en',
            'reply_node' => 'n2',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'extract_text'],
                ['id' => 'n2', 'capability' => 'summarize', 'depends_on' => ['n1'], 'params' => ['style' => 'short']],
            ],
        ]);

        self::assertCount(2, $plan->nodes);
        self::assertSame('n2', $plan->replyNode);
        self::assertSame(Capability::ExtractText, $plan->nodeById('n1')?->capability);
        self::assertSame('short', $plan->nodeById('n2')?->params['style']);
    }

    public function testFromArrayThrowsOnInvalid(): void
    {
        $this->expectException(InvalidTaskPlanException::class);

        TaskPlan::fromArray([
            'version' => 1,
            'reply_node' => 'n1',
            'tasks' => [['id' => 'n1', 'capability' => 'nope']],
        ]);
    }

    public function testTopologicalOrderRespectsDependencies(): void
    {
        $plan = TaskPlan::fromArray([
            'version' => 1,
            'language' => 'en',
            'reply_node' => 'n4',
            'tasks' => [
                ['id' => 'n4', 'capability' => 'compose_reply', 'depends_on' => ['n2', 'n3']],
                ['id' => 'n3', 'capability' => 'text2sound', 'depends_on' => ['n2']],
                ['id' => 'n2', 'capability' => 'summarize', 'depends_on' => ['n1']],
                ['id' => 'n1', 'capability' => 'extract_text'],
            ],
        ]);

        $order = array_map(static fn ($n) => $n->id, $plan->topologicalOrder());

        // Each node must appear after all of its dependencies.
        self::assertLessThan(array_search('n2', $order, true), array_search('n1', $order, true));
        self::assertLessThan(array_search('n3', $order, true), array_search('n2', $order, true));
        self::assertLessThan(array_search('n4', $order, true), array_search('n3', $order, true));
        self::assertSame('n4', end($order));
    }

    public function testRoundTripThroughArray(): void
    {
        $payload = [
            'version' => 1,
            'language' => 'en',
            'reply_node' => 'n1',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'chat', 'depends_on' => [], 'inputs' => [], 'params' => []],
            ],
        ];

        $plan = TaskPlan::fromArray($payload);

        self::assertSame($payload, $plan->toArray());
    }
}
