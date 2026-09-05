<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iam;

use App\Repository\ChatRepository;
use App\Repository\FileRepository;
use App\Service\Iam\Exception\UnknownResourceKindException;
use App\Service\Iam\Permission;
use App\Service\Iam\ResourceKind\ConversationKind;
use App\Service\Iam\ResourceKind\KnowledgeFolderKind;
use App\Service\Iam\ResourceKind\ResourceKindRegistry;
use PHPUnit\Framework\TestCase;

final class ResourceKindRegistryTest extends TestCase
{
    public function testKnowledgeFolderParsesOwnerPrefix(): void
    {
        $files = $this->createMock(FileRepository::class);
        $files->expects(self::once())
            ->method('existsForUserAndGroupKey')
            ->with(7, 'TASKPROMPT:sales')
            ->willReturn(true);

        $kind = new KnowledgeFolderKind($files);

        self::assertSame('knowledge_folder', $kind->key());
        self::assertSame(7, $kind->ownerId('7:TASKPROMPT:sales'));
        self::assertNull($kind->ownerId('not-an-id'));
        self::assertSame(
            [Permission::Read, Permission::Use, Permission::Edit, Permission::Manage],
            $kind->supportedPermissions(),
        );
    }

    public function testConversationSupportsReadAndUse(): void
    {
        $kind = new ConversationKind($this->createStub(ChatRepository::class));

        self::assertSame('conversation', $kind->key());
        self::assertSame([Permission::Read, Permission::Use], $kind->supportedPermissions());
    }

    public function testUnknownKindNamesTheKey(): void
    {
        $registry = new ResourceKindRegistry([]);

        $this->expectException(UnknownResourceKindException::class);
        $this->expectExceptionMessage('widget');
        $registry->get('widget');
    }
}
