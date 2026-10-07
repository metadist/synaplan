<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution\Runner;

use App\Entity\Message;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\Runner\ComposeReplyRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

final class ComposeReplyRunnerTest extends TestCase
{
    private function context(): NodeContext
    {
        $message = $this->createMock(Message::class);
        $message->method('getText')->willReturn('check both buckets');
        $message->method('getFileText')->willReturn('');
        $message->method('getFile')->willReturn(0);
        $message->method('getFilePath')->willReturn('');
        $message->method('getFiles')->willReturn(new ArrayCollection());

        $ctx = new NodeContext($message, [], 1, ['language' => 'en']);
        $ctx->setResult('n1', NodeResult::ok('real-bucket exists', [], ['query' => 'Backblaze · s3_head_bucket']));
        $ctx->setResult('n2', NodeResult::reportableFailure(
            'Backblaze reported an error: NotFound',
            ['query' => 'Backblaze · s3_head_bucket'],
        ));

        return $ctx;
    }

    public function testFailedStepErrorIsAppendedWhenTheReplyOnlyCopiesTheSuccess(): void
    {
        $node = new TaskNode('n3', Capability::ComposeReply, ['n1', 'n2'], ['text' => '$n1.text']);

        $result = (new ComposeReplyRunner())->run($node, $this->context());

        self::assertTrue($result->isSuccessful());
        self::assertStringContainsString('real-bucket exists', (string) $result->text);
        self::assertStringContainsString('[n2 · Backblaze · s3_head_bucket · FAILED]', (string) $result->text);
        self::assertStringContainsString('Backblaze reported an error: NotFound', (string) $result->text);
    }

    public function testErrorAlreadyQuotedIsNotAppendedTwice(): void
    {
        $node = new TaskNode('n3', Capability::ComposeReply, ['n1', 'n2'], [
            'text' => "real-bucket exists\n\nBackblaze reported an error: NotFound",
        ]);

        $result = (new ComposeReplyRunner())->run($node, $this->context());

        self::assertSame(1, substr_count((string) $result->text, 'Backblaze reported an error: NotFound'));
        self::assertStringNotContainsString('FAILED', (string) $result->text);
    }
}
