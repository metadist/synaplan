<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iam;

use App\Entity\File;
use App\Entity\User;
use App\Repository\FileRepository;
use App\Repository\MessageMetaRepository;
use App\Repository\MessageRepository;
use App\Service\Iam\AccessGate;
use App\Service\Iam\IamConfig;
use App\Service\Iam\Permission;
use App\Service\Iam\ResourceKind\ConversationKind;
use App\Service\Iam\SharedFileAccess;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * File reads follow the live share: a copy's shared_file_ref alone opens nothing.
 */
final class SharedFileAccessTest extends TestCase
{
    private AccessGate&MockObject $gate;
    private IamConfig&MockObject $iamConfig;
    private MessageRepository&MockObject $messages;
    private MessageMetaRepository&MockObject $meta;
    private SharedFileAccess $access;

    protected function setUp(): void
    {
        $this->gate = $this->createMock(AccessGate::class);
        $this->iamConfig = $this->createMock(IamConfig::class);
        $this->messages = $this->createMock(MessageRepository::class);
        $this->meta = $this->createMock(MessageMetaRepository::class);
        $this->access = new SharedFileAccess(
            $this->gate,
            $this->iamConfig,
            $this->messages,
            $this->meta,
            $this->createMock(FileRepository::class),
        );
    }

    public function testOwnerAlwaysReads(): void
    {
        $this->iamConfig->expects(self::never())->method('isSharingEnabled');

        self::assertTrue($this->access->canRead($this->user(9), $this->file(9)));
    }

    public function testSharingOffDeniesForeignFile(): void
    {
        $this->iamConfig->method('isSharingEnabled')->willReturn(false);
        $this->gate->expects(self::never())->method('decide');

        self::assertFalse($this->access->canRead($this->user(3), $this->file(9)));
    }

    public function testRefWithoutLiveShareIsDenied(): void
    {
        $this->iamConfig->method('isSharingEnabled')->willReturn(true);
        $this->messages->method('findChatIdsByFileId')->willReturn([5]);
        $this->gate->method('decide')->willReturn(false);
        $this->meta->expects(self::never())->method('userHasSharedFileRef');

        self::assertFalse($this->access->canRead($this->user(3), $this->file(9)));
    }

    public function testConversationShareReachesFileThroughMessageLink(): void
    {
        $this->iamConfig->method('isSharingEnabled')->willReturn(true);
        $this->messages->method('findChatIdsByFileId')->willReturn([5]);
        $this->gate->expects(self::once())
            ->method('decide')
            ->with(self::anything(), ConversationKind::KEY, '5', Permission::Read)
            ->willReturn(true);

        self::assertTrue($this->access->canRead($this->user(3), $this->file(9)));
    }

    private function user(int $id): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private function file(int $ownerId): File
    {
        $file = (new File())->setUserId($ownerId)->setFileName('one.pdf');
        (new \ReflectionProperty(File::class, 'id'))->setValue($file, 42);

        return $file;
    }
}
