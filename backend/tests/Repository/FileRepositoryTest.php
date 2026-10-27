<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Chat;
use App\Entity\ExternalIdentity;
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

    public function testFindByUserPaginatedKeepsAFolderNamedZero(): void
    {
        $user = $this->createUser('folder-zero');
        $inZero = $this->createFile($user, 'in-zero.pdf');
        $inZero->setGroupKey('0');
        $inOther = $this->createFile($user, 'in-other.pdf');
        $inOther->setGroupKey('notes');
        $this->em->flush();

        $found = $this->repository->findByUserPaginated((int) $user->getId(), '0', 0, 50);
        $ids = $this->idsOf($found['files']);

        $this->assertSame([$inZero->getId()], $ids);
        $this->assertSame(1, $found['total']);
    }

    public function testGetGroupCountsByUserDropsFoldersWithoutMatchingFiles(): void
    {
        $user = $this->createUser('group-filter');
        $sheetFolder = 'Sheets_'.bin2hex(random_bytes(3));
        $docFolder = 'Docs_'.bin2hex(random_bytes(3));

        $sheet = $this->createFile($user, 'budget.xlsx');
        $sheet->setFileType('xlsx');
        $sheet->setGroupKey($sheetFolder);

        $csv = $this->createFile($user, 'budget.csv');
        $csv->setFileType('csv');
        $csv->setGroupKey($sheetFolder);

        $doc = $this->createFile($user, 'notes.pdf');
        $doc->setGroupKey($docFolder);
        $this->em->flush();

        $userId = (int) $user->getId();
        $all = $this->repository->getGroupCountsByUser($userId);
        $this->assertSame(2, $all[$sheetFolder]);
        $this->assertSame(1, $all[$docFolder]);

        $sheets = $this->repository->getGroupCountsByUser($userId, [
            'file_type' => 'xlsx,csv',
        ]);

        $this->assertSame(2, $sheets[$sheetFolder]);
        $this->assertArrayNotHasKey($docFolder, $sheets);

        $leadingComma = $this->repository->getGroupCountsByUser($userId, ['file_type' => ',xlsx']);
        $this->assertSame(1, $leadingComma[$sheetFolder]);
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

    public function testTwoCloudsWithTheSameRemoteIdDoNotOverwriteEachOther(): void
    {
        $user = $this->createUser('cloud');
        $this->identity($user, 'nextcloud', 'inst-a');
        $this->identity($user, 'nextcloud', 'inst-b');

        $onA = $this->createFile($user, 'a.txt');
        $onA->setSource('nextcloud');
        $onA->setSourceId('5678');
        $onA->setSourceInstance('inst-a');
        $this->em->flush();

        $found = $this->repository->findForOverwrite((int) $user->getId(), 'nextcloud', '5678', null, null, 'inst-b');
        self::assertNull($found);

        $same = $this->repository->findForOverwrite((int) $user->getId(), 'nextcloud', '5678', null, null, 'inst-a');
        self::assertSame($onA->getId(), $same?->getId());

        $plain = $this->repository->findForOverwrite((int) $user->getId(), 'nextcloud', '5678', null, null, null);
        self::assertNull($plain);
    }

    public function testAnUnscopedFileIsClaimedOnlyForAnOwnerWithOneCloud(): void
    {
        $one = $this->createUser('one-cloud');
        $this->identity($one, 'nextcloud', 'inst-only');
        $file = $this->createFile($one, 'old.txt');
        $file->setSource('nextcloud');
        $file->setSourceId('5678');
        $this->em->flush();

        $claimed = $this->repository->findForOverwrite((int) $one->getId(), 'nextcloud', '5678', null, null, 'inst-only');
        self::assertSame('inst-only', $claimed?->getSourceInstance());

        $two = $this->createUser('two-clouds');
        $this->identity($two, 'nextcloud', 'inst-a');
        $this->identity($two, 'nextcloud', 'inst-b');
        $shared = $this->createFile($two, 'shared.txt');
        $shared->setSource('nextcloud');
        $shared->setSourceId('5678');
        $this->em->flush();

        self::assertNull($this->repository->findForOverwrite((int) $two->getId(), 'nextcloud', '5678', null, null, 'inst-a'));
    }

    public function testAnExactInstanceMatchWinsOverANewerUnscopedRow(): void
    {
        $user = $this->createUser('exact');
        $this->identity($user, 'nextcloud', 'inst-a');
        $this->identity($user, 'nextcloud', 'inst-b');
        $scoped = $this->createFile($user, 'scoped.txt');
        $scoped->setSource('nextcloud');
        $scoped->setSourceId('5678');
        $scoped->setSourceInstance('inst-a');
        $newer = $this->createFile($user, 'newer.txt');
        $newer->setSource('nextcloud');
        $newer->setSourceId('5678');
        $this->em->flush();

        $found = $this->repository->findForOverwrite((int) $user->getId(), 'nextcloud', '5678', null, null, 'inst-a');
        self::assertSame($scoped->getId(), $found?->getId());

        $byName = $this->repository->findForOverwrite((int) $user->getId(), 'nextcloud', null, null, 'scoped.txt', 'inst-b');
        self::assertNull($byName);
    }

    public function testCheckStalePersistsAClaimedInstance(): void
    {
        $user = $this->createUser('stale-claim');
        $this->identity($user, 'nextcloud', 'inst-only');
        $file = $this->createFile($user, 'claim.txt');
        $file->setSource('nextcloud');
        $file->setSourceId('99');
        $this->em->flush();

        $check = static::getContainer()->get(\App\Service\File\SourceStaleCheck::class);
        $check->check((int) $user->getId(), 'nextcloud', [['source_id' => '99', 'source_etag' => null]], 'inst-only');
        $this->em->clear();
        $reloaded = $this->em->find(File::class, $file->getId());
        self::assertSame('inst-only', $reloaded?->getSourceInstance());
    }

    private function identity(User $user, string $source, string $instanceId): void
    {
        $row = (new ExternalIdentity())
            ->setUserId((int) $user->getId())
            ->setSource($source)
            ->setInstanceId($instanceId)
            ->setExternalId($instanceId.'-user')
            ->setStatus(ExternalIdentity::STATUS_ACTIVE);
        $this->em->persist($row);
        $this->em->flush();
        $this->toRemove[] = $row;
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
