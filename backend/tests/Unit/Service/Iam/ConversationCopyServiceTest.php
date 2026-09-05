<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iam;

use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\FileRepository;
use App\Repository\MessageRepository;
use App\Service\Iam\ConversationCopyService;
use App\Service\RAG\RagScopeResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class ConversationCopyServiceTest extends TestCase
{
    public function testCopyCreatesOwnedChatAndDoesNotLoadOwnerFilesAsNewRows(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $messages = $this->createMock(MessageRepository::class);
        $files = $this->createMock(FileRepository::class);

        $source = new Chat();
        $source->setUserId(1);
        $source->setTitle('Playbook');

        $original = new Message();
        $original->setUserId(1);
        $original->setTrackingId(10);
        $original->setText('hello');
        $original->setFile(0);

        $messages->method('findBy')->willReturn([$original]);
        $files->expects(self::never())->method('find');
        $files->method('findBy')->willReturn([]);

        $persisted = [];
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $em->method('flush');

        $service = new ConversationCopyService($em, $messages, $files);
        $member = $this->createMock(User::class);
        $member->method('getId')->willReturn(7);

        $copy = $service->copyForUser($source, $member);

        self::assertSame(7, $copy->getUserId());
        self::assertSame('Playbook', $copy->getTitle());
        self::assertFalse($copy->isPublic());
        self::assertNotSame($source, $copy);
        $copiedMessages = array_filter($persisted, static fn (object $e): bool => $e instanceof Message);
        self::assertCount(1, $copiedMessages);
        $copied = array_values($copiedMessages)[0];
        self::assertSame(7, $copied->getUserId());
        self::assertSame('hello', $copied->getText());
        self::assertNull($copied->getMeta(RagScopeResolver::SHARED_FILE_REF));
    }
}
