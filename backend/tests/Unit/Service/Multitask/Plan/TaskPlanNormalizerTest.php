<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Plan;

use App\Service\Multitask\Plan\TaskPlan;
use App\Service\Multitask\Plan\TaskPlanNormalizer;
use PHPUnit\Framework\TestCase;

final class TaskPlanNormalizerTest extends TestCase
{
    /**
     * The customer-reported shape: the answering node reads `$n1.text` and
     * `$n2.text` but lists no dependency, so nothing stops it from running
     * before the data nodes.
     */
    public function testAddsEdgesForReferencedNodes(): void
    {
        $payload = [
            'version' => 1,
            'language' => 'en',
            'reply_node' => 'n3',
            'tasks' => [
                ['id' => 'n3', 'capability' => 'chat', 'inputs' => ['text' => "Report:\nHead: \$n1.text\nRegion: \$n2.text"]],
                ['id' => 'n1', 'capability' => 'mcp_fetch', 'inputs' => ['arguments' => ['bucket' => 'demo']], 'params' => ['server_id' => 1, 'tool' => 's3_head_bucket']],
                ['id' => 'n2', 'capability' => 'mcp_fetch', 'inputs' => ['arguments' => ['bucket' => 'demo']], 'params' => ['server_id' => 1, 'tool' => 's3_get_bucket_location']],
            ],
        ];

        self::assertSame(['n3' => ['n1', 'n2']], TaskPlanNormalizer::missingDependencies($payload));

        $repaired = TaskPlanNormalizer::inferDependencies($payload);
        self::assertSame(['n1', 'n2'], $repaired['tasks'][0]['depends_on']);

        // And the executor now orders the data nodes first.
        $order = array_map(static fn ($n) => $n->id, TaskPlan::fromArray($repaired)->topologicalOrder());
        self::assertSame(['n1', 'n2', 'n3'], $order);
    }

    public function testKeepsExistingEdgesAndOrder(): void
    {
        $payload = [
            'version' => 1,
            'reply_node' => 'n3',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'chat', 'inputs' => ['text' => 'write']],
                ['id' => 'n2', 'capability' => 'text2sound', 'depends_on' => ['n1'], 'inputs' => ['text' => '$n1.text']],
                ['id' => 'n3', 'capability' => 'compose_reply', 'depends_on' => ['n2', 'n1'], 'inputs' => ['text' => '$n1.text', 'attachments' => ['$n2.file']]],
            ],
        ];

        self::assertSame([], TaskPlanNormalizer::missingDependencies($payload));
        self::assertSame($payload, TaskPlanNormalizer::inferDependencies($payload));
    }

    public function testFindsReferencesNestedInArgumentsAndLists(): void
    {
        $payload = [
            'version' => 1,
            'reply_node' => 'n3',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'chat', 'inputs' => ['text' => 'summary']],
                ['id' => 'n2', 'capability' => 'mcp_action', 'inputs' => ['arguments' => ['title' => 'x', 'content' => '${n1.text}']], 'params' => ['server_id' => 5, 'tool' => 'create_page']],
                ['id' => 'n3', 'capability' => 'compose_reply', 'inputs' => ['text' => ['$n2.output', '$n1.text']]],
            ],
        ];

        self::assertSame(['n2' => ['n1'], 'n3' => ['n2', 'n1']], TaskPlanNormalizer::missingDependencies($payload));
    }

    public function testIgnoresUnknownIdsMessageRefsAndSelfReference(): void
    {
        $payload = [
            'version' => 1,
            'reply_node' => 'n1',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'chat', 'inputs' => ['text' => '$message.text and $n1.text and $n9.text']],
            ],
        ];

        self::assertSame([], TaskPlanNormalizer::missingDependencies($payload));
        self::assertSame($payload, TaskPlanNormalizer::inferDependencies($payload));
    }

    public function testLeavesMalformedPayloadsToTheValidator(): void
    {
        self::assertSame(['tasks' => 'nope'], TaskPlanNormalizer::inferDependencies(['tasks' => 'nope']));

        $malformedDeps = ['tasks' => [['id' => 'n1', 'depends_on' => 'n2', 'inputs' => ['text' => '$n2.text']], ['id' => 'n2']]];
        self::assertSame($malformedDeps, TaskPlanNormalizer::inferDependencies($malformedDeps));
    }
}
