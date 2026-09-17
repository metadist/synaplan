<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Chat;
use App\Entity\File;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\FileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for chat-scoped file lookup.
 *
 * findFilesByChatId() must see both BMESSAGEID rows and junction-table
 * attachments without loading every message ID into PHP first.
 */
class FileRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private FileRepository $repository;

    /** @var list<object> */
    private array $toRemove = [];

    protected function setUp(): void
    {
        parent::setUp();

        $kernel = self::bootKernel();
        $this->em = $kernel->getContainer()
            ->get('doctrine')
            ->getManager();
        $repository = $this->em->getRepository(File::class);
        self::assertInstanceOf(FileRepository::class, $repository);
        $this->repository = $repository;
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->toRemove) as $entity) {
            if ($this->em->contains($entity)) {
                $this->em->remove($entity);
            }
        }
        $this->em->flush();

        parent::tearDown();
    }

    public function testFindFilesByChatIdReturnsEmptyForInvalidChatId(): void
    {
        $this->assertSame([], $this->repository->findFilesByChatId(1, 0));
        $this->assertSame([], $this->repository->findFilesByChatId(1, -4));
    }

    public function testFindFilesByChatIdSeesMessageIdAndAttachmentChannels(): void
    {
        $user = $this->createUser('files-chat-both');
        $chat = $this->createChat($user, 'Both channels');
        $linkedMessage = $this->createMessage($user, $chat, 'linked');
        $attachedMessage = $this->createMessage($user, $chat, 'attached');

        $viaMessageId = $this->createFile($user, 'via-message-id.pdf');
        $viaMessageId->setMessageId($linkedMessage->getId());
        $this->em->flush();

        $viaAttachment = $this->createFile($user, 'via-attachment.pdf');
        $attachedMessage->addFile($viaAttachment);
        $this->em->flush();

        $found = $this->repository->findFilesByChatId((int) $user->getId(), (int) $chat->getId());
        $ids = $this->idsOf($found);

        $this->assertContains($viaMessageId->getId(), $ids);
        $this->assertContains($viaAttachment->getId(), $ids);
    }

    public function testFindFilesByChatIdIgnoresOtherChatsAndUsers(): void
    {
        $user = $this->createUser('files-chat-scope');
        $otherUser = $this->createUser('files-chat-other');
        $chat = $this->createChat($user, 'Mine');
        $otherChat = $this->createChat($user, 'Other chat');
        $foreignChat = $this->createChat($otherUser, 'Foreign');

        $mine = $this->createFile($user, 'mine.pdf');
        $mine->setMessageId($this->createMessage($user, $chat, 'mine')->getId());

        $sameUserOtherChat = $this->createFile($user, 'other-chat.pdf');
        $sameUserOtherChat->setMessageId($this->createMessage($user, $otherChat, 'other')->getId());

        $foreign = $this->createFile($otherUser, 'foreign.pdf');
        $foreign->setMessageId($this->createMessage($otherUser, $foreignChat, 'foreign')->getId());
        $this->em->flush();

        $found = $this->repository->findFilesByChatId((int) $user->getId(), (int) $chat->getId());
        $ids = $this->idsOf($found);

        $this->assertSame([$mine->getId()], $ids);
    }

    public function testFindFilesByChatIdRespectsLimitNewestFirst(): void
    {
        $user = $this->createUser('files-chat-limit');
        $chat = $this->createChat($user, 'Limit');

        $older = $this->createFile($user, 'older.pdf');
        $older->setMessageId($this->createMessage($user, $chat, 'older')->getId());
        $this->em->flush();

        $newer = $this->createFile($user, 'newer.pdf');
        $newer->setMessageId($this->createMessage($user, $chat, 'newer')->getId());
        $this->em->flush();

        $found = $this->repository->findFilesByChatId((int) $user->getId(), (int) $chat->getId(), 1);

        $this->assertCount(1, $found);
        $this->assertSame($newer->getId(), $found[0]->getId());
    }

    private function createUser(string $prefix): User
    {
        $user = new User();
        $user->setMail($prefix.'_'.bin2hex(random_bytes(4)).'@test.com');
        $user->setPw('test123');
        $user->setProviderId('WEB');
        $user->setUserLevel('NEW');
        $this->em->persist($user);
        $this->em->flush();
        $this->toRemove[] = $user;

        return $user;
    }

    private function createChat(User $user, string $title): Chat
    {
        $chat = new Chat();
        $chat->setUserId((int) $user->getId());
        $chat->setTitle($title);
        $this->em->persist($chat);
        $this->em->flush();
        $this->toRemove[] = $chat;

        return $chat;
    }

    private function createMessage(User $user, Chat $chat, string $text): Message
    {
        $message = new Message();
        $message->setUserId((int) $user->getId());
        $message->setChat($chat);
        $message->setTrackingId(time());
        $message->setUnixTimestamp(time());
        $message->setDateTime(date('YmdHis'));
        $message->setText($text);
        $message->setDirection('IN');
        $message->setProviderIndex('WEB');
        $message->setMessageType('TEST');
        $message->setTopic('CHAT');
        $message->setLanguage('en');
        $message->setStatus('complete');
        $this->em->persist($message);
        $this->em->flush();
        $this->toRemove[] = $message;

        return $message;
    }

    private function createFile(User $user, string $name): File
    {
        $file = new File();
        $file->setUserId((int) $user->getId());
        $file->setFileName($name);
        $file->setFilePath('/uploads/'.$name);
        $file->setFileType('pdf');
        $file->setFileSize(32);
        $file->setFileMime('application/pdf');
        $file->setStatus('uploaded');
        $this->em->persist($file);
        $this->toRemove[] = $file;

        return $file;
    }

    /**
     * @param list<File> $files
     *
     * @return list<int>
     */
    private function idsOf(array $files): array
    {
        $ids = [];
        foreach ($files as $file) {
            $id = $file->getId();
            if (null !== $id) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
