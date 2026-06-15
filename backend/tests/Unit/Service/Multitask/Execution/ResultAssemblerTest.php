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

        // n1 succeeds, n2 is skipped (not executed), n3 uses best-effort.
        $ctx->setResult('n1', NodeResult::ok('Summary text'));
        $ctx->setResult('n3', NodeResult::ok('Summary text'));

        $result = $this->assembler->assemble($plan, $ctx);
        $cards = $result['metadata']['task_plan_render']['cards'];

        $n2 = array_values(array_filter($cards, fn ($c) => 'n2' === $c['nodeId']))[0];
        $this->assertSame('skipped', $n2['state']);
    }
}
