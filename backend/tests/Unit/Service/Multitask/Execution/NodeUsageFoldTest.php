<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution;

use App\Entity\Message;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\NodeUsageFold;
use App\Service\Multitask\Plan\TaskPlan;
use PHPUnit\Framework\TestCase;

final class NodeUsageFoldTest extends TestCase
{
    public function testMeteredNodesKeepTheirTokensForDisplayAndTheirCost(): void
    {
        $plan = TaskPlan::fromArray([
            'version' => 1, 'language' => 'en', 'reply_node' => 'n2',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'chat'],
                ['id' => 'n2', 'capability' => 'chat', 'depends_on' => ['n1']],
            ],
        ]);
        $context = new NodeContext(new Message(), [], 1, []);
        $context->setResult('n1', NodeResult::ok('a', [], [
            'facade_metered' => true,
            'facade_charged_cost' => '0.010000',
            'facade_price_known' => false,
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120],
        ]));
        $context->setResult('n2', NodeResult::ok('b', [], [
            'facade_metered' => true,
            'facade_charged_cost' => '0.020000',
            'usage' => ['prompt_tokens' => 40, 'completion_tokens' => 10, 'total_tokens' => 50],
        ]));

        $metadata = NodeUsageFold::into($plan, $context, []);

        $this->assertArrayNotHasKey('usage', $metadata);
        $this->assertTrue($metadata['facade_metered']);
        $this->assertSame('0.030000', $metadata['facade_charged_cost']);
        $this->assertFalse($metadata['facade_price_known']);
        $this->assertSame(170, $metadata['display_usage']['total_tokens']);
        $this->assertSame(140, $metadata['display_usage']['prompt_tokens']);
        $this->assertSame(30, $metadata['display_usage']['completion_tokens']);
    }

    public function testAnUnmeteredNodeStaysOnTheBilledUsage(): void
    {
        $plan = TaskPlan::singleChatPlan();
        $context = new NodeContext(new Message(), [], 1, []);
        $context->setResult('n1', NodeResult::ok('a', [], [
            'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 2, 'total_tokens' => 10],
        ]));

        $metadata = NodeUsageFold::into($plan, $context, []);

        $this->assertFalse($metadata['facade_metered'] ?? false);
        $this->assertSame(10, $metadata['usage']['total_tokens']);
        $this->assertSame(10, $metadata['display_usage']['total_tokens']);
    }

    public function testAMixedPlanBillsOnlyTheUnmeteredTokens(): void
    {
        $plan = TaskPlan::fromArray([
            'version' => 1, 'language' => 'en', 'reply_node' => 'n2',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'chat'],
                ['id' => 'n2', 'capability' => 'summarize', 'depends_on' => ['n1']],
            ],
        ]);
        $context = new NodeContext(new Message(), [], 1, []);
        $context->setResult('n1', NodeResult::ok('a', [], [
            'facade_metered' => true,
            'facade_charged_cost' => '0.004000',
            'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 10, 'total_tokens' => 40],
        ]));
        $context->setResult('n2', NodeResult::ok('b', [], [
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5, 'total_tokens' => 10],
        ]));

        $metadata = NodeUsageFold::into($plan, $context, []);

        $this->assertFalse($metadata['facade_metered'] ?? false);
        $this->assertSame(10, $metadata['usage']['total_tokens']);
        $this->assertSame(50, $metadata['display_usage']['total_tokens']);
    }
}
