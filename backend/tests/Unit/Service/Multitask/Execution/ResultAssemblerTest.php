<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution;

use App\Entity\Message;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\ResultAssembler;
use App\Service\Multitask\Plan\TaskPlan;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

/**
 * ResultAssembler builds the task_plan_render metadata key so the frontend
 * can reconstruct task cards after a page reload (issue #1070 DAG divergence).
 */
final class ResultAssemblerTest extends TestCase
{
    private ResultAssembler $assembler;

    protected function setUp(): void
    {
        $this->assembler = new ResultAssembler();
    }

    private function context(string $messageText = 'hello'): NodeContext
    {
        $m = $this->createMock(Message::class);
        $m->method('getText')->willReturn($messageText);
        $m->method('getFileText')->willReturn('');
        $m->method('getFile')->willReturn(0);
        $m->method('getFilePath')->willReturn('');
        $m->method('getFiles')->willReturn(new ArrayCollection());
        $m->method('getLanguage')->willReturn('en');

        return new NodeContext($m, [], 1, ['language' => 'en']);
    }

    private function plan(): TaskPlan
    {
        return TaskPlan::fromArray([
            'version' => 1, 'language' => 'en', 'reply_node' => 'n3',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'summarize', 'inputs' => ['text' => '$message.text']],
                ['id' => 'n2', 'capability' => 'text2sound', 'depends_on' => ['n1'], 'inputs' => ['text' => '$n1.text']],
                ['id' => 'n3', 'capability' => 'compose_reply', 'depends_on' => ['n1', 'n2'], 'inputs' => ['text' => '$n1.text', 'attachments' => ['$n2.file']]],
            ],
        ]);
    }

    public function testTaskPlanRenderContainsNonHiddenNodesOnly(): void
    {
        $plan = $this->plan();
        $ctx = $this->context();

        $ctx->setResult('n1', NodeResult::ok('Summary text'));
        $ctx->setResult('n2', NodeResult::ok(null, [['path' => 'uploads/tts.mp3', 'type' => 'audio']]));
        $ctx->setResult('n3', NodeResult::ok('Summary text', [['path' => 'uploads/tts.mp3', 'type' => 'audio']]));

        $result = $this->assembler->assemble($plan, $ctx);

        $this->assertArrayHasKey('task_plan_render', $result['metadata']);
        $render = $result['metadata']['task_plan_render'];

        // n3 is compose_reply → hidden; only n1 and n2 produce visible cards.
        $this->assertCount(2, $render['cards']);
        $this->assertSame('n3', $render['reply_node']);

        $cardIds = array_column($render['cards'], 'nodeId');
        $this->assertContains('n1', $cardIds);
        $this->assertContains('n2', $cardIds);
        $this->assertNotContains('n3', $cardIds);
    }

    public function testTaskPlanRenderCardShapeIsCorrect(): void
    {
        $plan = $this->plan();
        $ctx = $this->context();

        $ctx->setResult('n1', NodeResult::ok('Summary text'));
        $ctx->setResult('n2', NodeResult::ok(null, [['path' => 'uploads/tts.mp3', 'type' => 'audio']]));
        $ctx->setResult('n3', NodeResult::ok('Summary text', [['path' => 'uploads/tts.mp3', 'type' => 'audio']]));

        $result = $this->assembler->assemble($plan, $ctx);
        $cards = $result['metadata']['task_plan_render']['cards'];

        $n1 = array_values(array_filter($cards, fn ($c) => 'n1' === $c['nodeId']))[0];
        $this->assertSame('summarize', $n1['capability']);
        $this->assertSame('text', $n1['kind']);
        $this->assertSame('done', $n1['state']);
        $this->assertSame('Summary text', $n1['text']);
        $this->assertArrayNotHasKey('url', $n1); // no files on n1

        $n2 = array_values(array_filter($cards, fn ($c) => 'n2' === $c['nodeId']))[0];
        $this->assertSame('text2sound', $n2['capability']);
        $this->assertSame('audio', $n2['kind']);
        $this->assertSame('done', $n2['state']);
        $this->assertSame('uploads/tts.mp3', $n2['url']);
        $this->assertSame('audio', $n2['type']);
    }

    public function testPartialFailureCardStateIsPreserved(): void
    {
        $plan = $this->plan();
        $ctx = $this->context();

        $ctx->setResult('n1', NodeResult::ok('Summary text'));
        $ctx->setResult('n2', NodeResult::failed('TTS provider unavailable'));
        $ctx->setResult('n3', NodeResult::ok('Summary text'));

        $result = $this->assembler->assemble($plan, $ctx);
        $cards = $result['metadata']['task_plan_render']['cards'];

        $n2 = array_values(array_filter($cards, fn ($c) => 'n2' === $c['nodeId']))[0];
        $this->assertSame('failed', $n2['state']);
        $this->assertSame('TTS provider unavailable', $n2['error']);
    }

    public function testSkippedNodeCardStateIsPreserved(): void
    {
        $plan = $this->plan();
        $ctx = $this->context();

        // n1 succeeds, n2 is skipped (dependency failed), n3 uses best-effort.
        $ctx->setResult('n1', NodeResult::ok('Summary text'));
        $ctx->setResult('n2', NodeResult::skipped('dependency failed'));
        $ctx->setResult('n3', NodeResult::ok('Summary text'));

        $result = $this->assembler->assemble($plan, $ctx);
        $cards = $result['metadata']['task_plan_render']['cards'];

        $n2 = array_values(array_filter($cards, fn ($c) => 'n2' === $c['nodeId']))[0];
        $this->assertSame('skipped', $n2['state']);
    }

    /**
     * Issue #1197: when the reply node is `compose_reply` (no provider meta of
     * its own), the assembler must backfill provider/model/model_id from the
     * upstream LLM node so StreamController persists the real inference
     * provider (e.g. groq) and the chat bubble shows the correct avatar.
     */
    public function testProviderMetadataPropagatedFromUpstreamNodeToReply(): void
    {
        $plan = $this->plan();
        $ctx = $this->context();

        $ctx->setResult('n1', NodeResult::ok('Summary text', [], [
            'provider' => 'groq',
            'model' => 'gpt-oss-120b',
            'model_id' => 76,
        ]));
        $ctx->setResult('n2', NodeResult::ok(null, [['path' => 'uploads/tts.mp3', 'type' => 'audio']]));
        // compose_reply reply node carries no provider metadata.
        $ctx->setResult('n3', NodeResult::ok('Summary text', [['path' => 'uploads/tts.mp3', 'type' => 'audio']]));

        $result = $this->assembler->assemble($plan, $ctx);

        $this->assertSame('groq', $result['metadata']['provider']);
        $this->assertSame('gpt-oss-120b', $result['metadata']['model']);
        $this->assertSame(76, $result['metadata']['model_id']);
    }

    /**
     * Issue #1197: an explicit provider on the reply node must NOT be
     * overwritten by the upstream backfill.
     */
    public function testReplyNodeProviderNotOverriddenByBackfill(): void
    {
        $plan = $this->plan();
        $ctx = $this->context();

        $ctx->setResult('n1', NodeResult::ok('Summary text', [], ['provider' => 'groq', 'model' => 'gpt-oss-120b']));
        $ctx->setResult('n2', NodeResult::ok(null, [['path' => 'uploads/tts.mp3', 'type' => 'audio']]));
        $ctx->setResult('n3', NodeResult::ok('Summary text', [['path' => 'uploads/tts.mp3', 'type' => 'audio']], [
            'provider' => 'anthropic',
            'model' => 'claude-opus',
        ]));

        $result = $this->assembler->assemble($plan, $ctx);

        $this->assertSame('anthropic', $result['metadata']['provider']);
        $this->assertSame('claude-opus', $result['metadata']['model']);
    }

    private function searchPlan(): TaskPlan
    {
        return TaskPlan::fromArray([
            'version' => 1, 'language' => 'en', 'reply_node' => 'n2',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'web_search', 'inputs' => ['query' => '$message.text']],
                ['id' => 'n2', 'capability' => 'chat', 'depends_on' => ['n1'], 'inputs' => ['text' => '$n1.text']],
            ],
        ]);
    }

    public function testWebSearchResultsPropagatedToMetadata(): void
    {
        $plan = $this->searchPlan();
        $ctx = $this->context('latest AI news');

        $rawResults = [
            'query' => 'latest AI news',
            'results' => [
                ['title' => 'Article 1', 'url' => 'https://example.com/1', 'description' => 'desc 1'],
                ['title' => 'Article 2', 'url' => 'https://example.com/2', 'description' => 'desc 2'],
            ],
        ];
        $ctx->setResult('n1', NodeResult::ok('formatted search text', [], [
            'web_search' => true,
            'query' => 'latest AI news',
            'search_results' => $rawResults,
        ]));
        $ctx->setResult('n2', NodeResult::ok('Here is the AI summary.'));

        $result = $this->assembler->assemble($plan, $ctx);

        // search_results must be lifted to top-level metadata so StreamController
        // can set the web_search_query/count metas and populate the Sources dropdown.
        $this->assertArrayHasKey('search_results', $result['metadata']);
        $this->assertSame('latest AI news', $result['metadata']['search_results']['query']);
        $this->assertCount(2, $result['metadata']['search_results']['results']);
    }

    public function testWebSearchCardContainsCompactSummaryNotDump(): void
    {
        $plan = $this->searchPlan();
        $ctx = $this->context('latest AI news');

        $rawResults = [
            'query' => 'latest AI news',
            'results' => [
                ['title' => 'A1', 'url' => 'https://example.com/1', 'description' => 'd1'],
                ['title' => 'A2', 'url' => 'https://example.com/2', 'description' => 'd2'],
                ['title' => 'A3', 'url' => 'https://example.com/3', 'description' => 'd3'],
            ],
        ];
        $ctx->setResult('n1', NodeResult::ok('Web Search Results for: "latest AI news"\n\n1. A1...', [], [
            'web_search' => true,
            'query' => 'latest AI news',
            'search_results' => $rawResults,
        ]));
        $ctx->setResult('n2', NodeResult::ok('Summary.'));

        $result = $this->assembler->assemble($plan, $ctx);
        $cards = $result['metadata']['task_plan_render']['cards'];

        $n1Card = array_values(array_filter($cards, fn ($c) => 'n1' === $c['nodeId']))[0];
        $this->assertSame('search', $n1Card['kind']);
        $this->assertSame('latest AI news', $n1Card['query']);
        $this->assertSame(3, $n1Card['resultsCount']);
        // The raw formatted dump must NOT appear in the card text.
        $this->assertArrayNotHasKey('text', $n1Card);
    }

    public function testWebSearchResultsNotOverriddenWhenAlreadySet(): void
    {
        $plan = $this->searchPlan();
        $ctx = $this->context();

        // Pre-populate metadata via replyNode (unlikely but defensive).
        $existingResults = ['query' => 'pre-set', 'results' => [['url' => 'https://a.com']]];
        $ctx->setResult('n2', NodeResult::ok('Answer', [], ['search_results' => $existingResults]));
        $ctx->setResult('n1', NodeResult::ok('search text', [], [
            'web_search' => true,
            'query' => 'different query',
            'search_results' => ['query' => 'different query', 'results' => [['url' => 'https://b.com']]],
        ]));

        $result = $this->assembler->assemble($plan, $ctx);

        // replyNode metadata wins (it was already set from n2).
        $this->assertSame('pre-set', $result['metadata']['search_results']['query']);
    }
}
