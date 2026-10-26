<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution\Recovery;

use App\Entity\Message;
use App\Service\Exception\StreamCancelledException;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\Recovery\EmptyResultDetector;
use App\Service\Multitask\Execution\Recovery\QueryRelaxer;
use App\Service\Multitask\Execution\Recovery\SearchRecovery;
use App\Service\Multitask\Execution\TaskRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SearchRecoveryTest extends TestCase
{
    public function testTheFirstWiderQueryThatHitsStopsTheSearch(): void
    {
        $calls = [];
        $runner = $this->runner(function (TaskNode $node) use (&$calls): NodeResult {
            $calls[] = $node->inputs['arguments']['query'] ?? null;

            return 'Metadist' === ($node->inputs['arguments']['query'] ?? null)
                ? NodeResult::ok('{"results":[{"title":"Metadist"}]}')
                : NodeResult::ok('{"results":[]}');
        });
        $relaxer = $this->createMock(QueryRelaxer::class);
        $relaxer->method('suggest')->willReturn(['query' => 'Metadist']);
        $relaxer->expects(self::never())->method('broaden');
        $retried = [];

        $result = $this->recovery($relaxer)->recover(
            $runner,
            $this->mcpNode('Metadsit'),
            $this->context('Find Metadsit'),
            NodeResult::ok('{"results":[]}'),
            static function (string $query) use (&$retried): void {
                $retried[] = $query;
            },
        );

        self::assertSame(['Metadist'], $calls);
        self::assertSame(['Metadist'], $retried);
        self::assertSame('Metadist', $result->metadata['recovery']['matched_query'] ?? null);
        self::assertFalse($result->metadata['recovery']['empty'] ?? true);
        self::assertSame(0, $result->metadata['recovery']['attempts'][0]['hits'] ?? null);
        self::assertGreaterThan(0, $result->metadata['recovery']['attempts'][1]['hits'] ?? 0);
    }

    public function testTwoEmptyTriesThenStopAndATurnBudgetIsShared(): void
    {
        $calls = 0;
        $runner = $this->runner(function () use (&$calls): NodeResult {
            ++$calls;

            return NodeResult::ok('{"results":[]}');
        });
        $relaxer = $this->createMock(QueryRelaxer::class);
        $relaxer->method('suggest')->willReturn(['query' => 'Meta']);
        $relaxer->method('broaden')->willReturn(['query' => 'Dist']);

        $result = $this->recovery($relaxer)->recover(
            $runner,
            $this->mcpNode('Metadsit'),
            $this->context('Metadsit'),
            NodeResult::ok('{"results":[]}'),
        );

        self::assertSame(2, $calls);
        self::assertTrue($result->isSuccessful());
        self::assertTrue($result->metadata['recovery']['empty'] ?? false);
        self::assertSame(['Metadsit', 'Meta', 'Dist'], array_column($result->metadata['recovery']['attempts'], 'query'));

        $spent = $this->context('again');
        for ($i = 0; $i < NodeContext::MAX_RECOVERY_PER_TURN; ++$i) {
            $spent->spendRecovery();
        }
        $blocked = 0;
        $this->recovery($relaxer)->recover(
            $this->runner(function () use (&$blocked): NodeResult {
                ++$blocked;

                return NodeResult::ok('{"results":[]}');
            }),
            $this->mcpNode('Metadsit'),
            $spent,
            NodeResult::ok('{"results":[]}'),
        );
        self::assertSame(0, $blocked);
    }

    public function testADuplicateSuggestionIsNotSentAgain(): void
    {
        $calls = 0;
        $runner = $this->runner(function () use (&$calls): NodeResult {
            ++$calls;

            return NodeResult::ok('{"results":[]}');
        });
        $relaxer = $this->createMock(QueryRelaxer::class);
        $relaxer->method('suggest')->willReturn(['query' => 'Metadsit']);
        $relaxer->method('broaden')->willReturn(null);

        $result = $this->recovery($relaxer)->recover(
            $runner,
            $this->mcpNode('Metadsit'),
            $this->context('Metadsit'),
            NodeResult::ok('{"results":[]}'),
        );

        self::assertSame(0, $calls);
        self::assertSame(['Metadsit'], array_column($result->metadata['recovery']['attempts'], 'query'));
    }

    public function testWritesAreNeverRepeated(): void
    {
        $calls = 0;
        $runner = $this->runner(function () use (&$calls): NodeResult {
            ++$calls;

            return NodeResult::ok('{"results":[]}');
        });
        $node = new TaskNode('n1', Capability::McpAction, [], ['arguments' => ['query' => 'Metadsit']]);

        $result = $this->recovery($this->createMock(QueryRelaxer::class))->recover(
            $runner,
            $node,
            $this->context('create Metadsit'),
            NodeResult::ok('{"results":[]}'),
        );

        self::assertSame(0, $calls);
        self::assertArrayNotHasKey('recovery', $result->metadata);
    }

    public function testCancellationDuringARetryStopsTheTurn(): void
    {
        $runner = $this->runner(static function (): NodeResult {
            throw new StreamCancelledException('stopped');
        });
        $relaxer = $this->createMock(QueryRelaxer::class);
        $relaxer->method('suggest')->willReturn(['query' => 'Metadist']);

        $this->expectException(StreamCancelledException::class);
        $this->recovery($relaxer)->recover(
            $runner,
            $this->mcpNode('Metadsit'),
            $this->context('Metadsit'),
            NodeResult::ok('{"results":[]}'),
        );
    }

    public function testWebAndEmailRetriesReplaceTheQueryField(): void
    {
        $seen = [];
        $runner = $this->runner(function (TaskNode $node) use (&$seen): NodeResult {
            $seen[] = [
                'capability' => $node->capability->value,
                'input' => $node->inputs['query'] ?? null,
                'param' => $node->params['query'] ?? null,
            ];

            return NodeResult::ok('Found it', [], ['results_count' => 1]);
        });
        $relaxer = $this->createMock(QueryRelaxer::class);
        $relaxer->method('suggest')->willReturn(['query' => 'Metadist']);

        $this->recovery($relaxer)->recover(
            $runner,
            new TaskNode('n1', Capability::WebSearch),
            $this->context('Metadsit'),
            NodeResult::ok('No results.', [], ['search_results' => ['results' => []], 'query' => 'Metadsit']),
        );
        $this->recovery($relaxer)->recover(
            $runner,
            new TaskNode('n1', Capability::EmailSearch, [], [], ['query' => 'Metadsit']),
            $this->context('Metadsit'),
            NodeResult::ok('No emails matching "Metadsit" were found.', [], ['results_count' => 0, 'query' => 'Metadsit']),
        );

        self::assertSame('web_search', $seen[0]['capability']);
        self::assertSame('Metadist', $seen[0]['input']);
        self::assertSame('email_search', $seen[1]['capability']);
        self::assertSame('Metadist', $seen[1]['param']);
    }

    private function recovery(QueryRelaxer $relaxer): SearchRecovery
    {
        return new SearchRecovery(new EmptyResultDetector(), $relaxer, new NullLogger());
    }

    private function mcpNode(string $query): TaskNode
    {
        return new TaskNode('n1', Capability::McpFetch, [], [
            'arguments' => ['query' => $query],
        ], ['tool' => 'notion-search']);
    }

    private function context(string $text): NodeContext
    {
        $message = $this->createMock(Message::class);
        $message->method('getText')->willReturn($text);

        return new NodeContext($message, [], 7, ['language' => 'en']);
    }

    /**
     * @param callable(TaskNode, NodeContext): NodeResult $fn
     */
    private function runner(callable $fn): TaskRunner
    {
        return new class($fn) implements TaskRunner {
            /** @param callable(TaskNode, NodeContext): NodeResult $fn */
            public function __construct(private $fn)
            {
            }

            public function supportedCapabilities(): array
            {
                return [Capability::McpFetch, Capability::WebSearch, Capability::EmailSearch];
            }

            public function describe(): array
            {
                return [];
            }

            public function run(TaskNode $node, NodeContext $context): NodeResult
            {
                return ($this->fn)($node, $context);
            }
        };
    }
}
