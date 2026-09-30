<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\Entity\User;
use App\Service\Digest\MessageReferenceResolver;
use App\Service\Message\ExternalReplyReferences;
use App\Service\SelfAware\Docs\PlatformDocReferenceResolver;
use App\Service\UserMemoryService;
use PHPUnit\Framework\TestCase;

final class ExternalReplyReferencesTest extends TestCase
{
    public function testResolveRunsMemoryThenMessageThenDocTags(): void
    {
        $user = $this->createStub(User::class);
        $memories = $this->createMock(UserMemoryService::class);
        $messages = $this->createMock(MessageReferenceResolver::class);
        $docs = $this->createMock(PlatformDocReferenceResolver::class);

        $memories->expects($this->once())
            ->method('resolveMemoryTags')
            ->with('raw [Memory:1]', $user)
            ->willReturn('named [Message:2]');
        $messages->expects($this->once())
            ->method('resolveMessageTags')
            ->with('named [Message:2]', $user)
            ->willReturn('named ("Note") [Doc:using-synaplan]');
        $docs->expects($this->once())
            ->method('resolveDocTags')
            ->with('named ("Note") [Doc:using-synaplan]')
            ->willReturn('named ("Note") [Using Synaplan](https://docs.example/using-synaplan)');

        $resolver = new ExternalReplyReferences($memories, $messages, $docs);

        $this->assertSame(
            'named ("Note") [Using Synaplan](https://docs.example/using-synaplan)',
            $resolver->resolve('raw [Memory:1]', $user),
        );
    }

    public function testStoredResolutionKeepsDocTags(): void
    {
        $user = $this->createStub(User::class);
        $memories = $this->createMock(UserMemoryService::class);
        $messages = $this->createMock(MessageReferenceResolver::class);
        $docs = $this->createMock(PlatformDocReferenceResolver::class);

        $memories->method('resolveMemoryTags')->willReturnArgument(0);
        $messages->method('resolveMessageTags')->willReturnArgument(0);
        $docs->expects($this->never())->method('resolveDocTags');

        $resolver = new ExternalReplyReferences($memories, $messages, $docs);

        $this->assertSame(
            'See [Doc:using-synaplan].',
            $resolver->resolveStored('See [Doc:using-synaplan].', $user),
        );
    }
}
