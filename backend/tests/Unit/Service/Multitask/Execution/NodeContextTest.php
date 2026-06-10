<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution;

use App\Entity\File;
use App\Entity\Message;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

final class NodeContextTest extends TestCase
{
    private function context(Message $message): NodeContext
    {
        return new NodeContext($message, [], 1, ['language' => 'en']);
    }

    private function message(string $text = 'hello', string $fileText = ''): Message
    {
        $m = $this->createMock(Message::class);
        $m->method('getText')->willReturn($text);
        $m->method('getFileText')->willReturn($fileText);
        $m->method('getFile')->willReturn(0);
        $m->method('getFilePath')->willReturn('');
        $m->method('getFiles')->willReturn(new ArrayCollection());

        return $m;
    }

    public function testResolvesMessageText(): void
    {
        $ctx = $this->context($this->message('what is in this?'));

        self::assertSame('what is in this?', $ctx->resolve('$message.text'));
    }

    public function testResolvesMessageFileText(): void
    {
        $ctx = $this->context($this->message(fileText: 'extracted body'));

        self::assertSame('extracted body', $ctx->resolve('$message.fileText'));
    }

    public function testResolvesMessageFiles(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getFilePath')->willReturn('1/000/report.pdf');
        $file->method('getFileType')->willReturn('pdf');
        $file->method('getFileText')->willReturn('doc text');

        $m = $this->createMock(Message::class);
        $m->method('getText')->willReturn('summarize');
        $m->method('getFileText')->willReturn('');
        $m->method('getFile')->willReturn(1);
        $m->method('getFilePath')->willReturn('');
        $m->method('getFiles')->willReturn(new ArrayCollection([$file]));

        $files = $this->context($m)->resolve('$message.files');

        self::assertIsArray($files);
        self::assertCount(1, $files);
        self::assertSame('1/000/report.pdf', $files[0]['path']);
        self::assertSame('pdf', $files[0]['type']);
    }

    public function testResolvesUpstreamNodeText(): void
    {
        $ctx = $this->context($this->message());
        $ctx->setResult('n1', NodeResult::ok('summary text'));

        self::assertSame('summary text', $ctx->resolve('$n1.text'));
    }

    public function testResolvesUpstreamNodeFileAndFiles(): void
    {
        $ctx = $this->context($this->message());
        $file = ['path' => '/api/v1/files/uploads/x.mp3', 'type' => 'audio'];
        $ctx->setResult('n3', NodeResult::ok(null, [$file]));

        self::assertSame($file, $ctx->resolve('$n3.file'));
        self::assertSame([$file], $ctx->resolve('$n3.files'));
    }

    public function testResolvesListOfRefs(): void
    {
        $ctx = $this->context($this->message());
        $ctx->setResult('n3', NodeResult::ok(null, [['path' => 'a.mp3', 'type' => 'audio']]));

        $resolved = $ctx->resolve(['$n3.file']);

        self::assertSame([['path' => 'a.mp3', 'type' => 'audio']], $resolved);
    }

    public function testLiteralsPassThrough(): void
    {
        $ctx = $this->context($this->message());

        self::assertSame('short', $ctx->resolve('short'));
        self::assertSame(8, $ctx->resolve(8));
        self::assertSame(['style' => 'short'], $ctx->resolve(['style' => 'short']));
    }

    public function testUnknownRefResolvesToNull(): void
    {
        $ctx = $this->context($this->message());

        self::assertNull($ctx->resolve('$nope.text'));
        self::assertNull($ctx->resolve('$n1.text')); // no result set
    }

    public function testResolveInputsMapsAllNodeInputs(): void
    {
        $ctx = $this->context($this->message());
        $ctx->setResult('n2', NodeResult::ok('the summary'));
        $ctx->setResult('n3', NodeResult::ok(null, [['path' => 'a.mp3', 'type' => 'audio']]));

        $node = new TaskNode('n4', Capability::ComposeReply, ['n2', 'n3'], [
            'text' => '$n2.text',
            'attachments' => ['$n3.file'],
        ]);

        $resolved = $ctx->resolveInputs($node);

        self::assertSame('the summary', $resolved['text']);
        self::assertSame([['path' => 'a.mp3', 'type' => 'audio']], $resolved['attachments']);
    }
}
