<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat;

use App\Entity\Chat;
use App\Entity\File;
use App\Entity\Message;
use App\Repository\ChatRepository;
use App\Repository\ChatSummaryRepository;
use App\Repository\DocumentRevisionRepository;
use App\Repository\FileRepository;
use App\Repository\MessageRepository;
use App\Repository\ShareRepository;
use App\Service\Chat\ChatDeletionService;
use App\Service\Digest\MessageDigestMaintenance;
use App\Service\File\FileStorageService;
use App\Service\File\OgImageService;
use App\Service\RAG\VectorStorage\VectorStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Deleting a chat used to delete every file attached to or generated in it
 * (#1826). Files are library content that happened to enter through a chat:
 * they are detached and kept; only incognito (ephemeral) files die with the
 * conversation, together with their vectors and revisions.
 */
final class ChatDeletionServiceTest extends TestCase
{
    private const USER_ID = 7;
    private const CHAT_ID = 164;

    private EntityManagerInterface&MockObject $em;
    private FileRepository&MockObject $fileRepository;
    private FileStorageService&MockObject $fileStorage;
    private VectorStorageInterface&MockObject $vectorStorage;
    private DocumentRevisionRepository&MockObject $documentRevisions;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->fileRepository = $this->createMock(FileRepository::class);
        $this->fileStorage = $this->createMock(FileStorageService::class);
        $this->vectorStorage = $this->createMock(VectorStorageInterface::class);
        $this->documentRevisions = $this->createMock(DocumentRevisionRepository::class);
    }

    public function testLibraryFilesAreDetachedAndKeptOnDisk(): void
    {
        $message = $this->message(901, 'uploads/7/report.pdf');
        $file = $this->file(93, 'uploads/7/report.pdf', ephemeral: false, messageId: 901);

        $this->fileRepository->expects($this->once())->method('findAllFilesByMessageIds')
            ->with(self::USER_ID, [901])
            ->willReturn([$file]);

        // Neither the library file nor the legacy message copy (same path) is removed.
        $this->fileStorage->expects($this->never())->method('deleteFile');
        $this->vectorStorage->expects($this->never())->method('deleteByFile');
        $this->documentRevisions->expects($this->never())->method('deleteForFile');

        $chat = $this->chat();
        $this->em->expects($this->once())->method('remove')->with($chat);

        $this->service([$message])->deleteOwnedChat(self::USER_ID, $chat);

        self::assertNull($file->getMessageId(), 'File must be detached from the deleted message');
    }

    public function testEphemeralFilesAreRemovedWithVectorsAndRevisions(): void
    {
        $message = $this->message(902, 'incognito/7/scan.png');
        $file = $this->file(94, 'incognito/7/scan.png', ephemeral: true, messageId: 902);

        $this->fileRepository->method('findAllFilesByMessageIds')->willReturn([$file]);

        $this->documentRevisions->expects($this->once())->method('deleteForFile')->with(94);
        $this->vectorStorage->expects($this->once())->method('deleteByFile')->with(self::USER_ID, 94);

        $deletedPaths = [];
        $this->fileStorage->method('deleteFile')->willReturnCallback(
            static function (string $path) use (&$deletedPaths): bool {
                $deletedPaths[] = $path;

                return true;
            }
        );

        $chat = $this->chat();
        $removed = [];
        $this->em->method('remove')->willReturnCallback(static function (object $entity) use (&$removed): void {
            $removed[] = $entity;
        });

        $this->service([$message])->deleteOwnedChat(self::USER_ID, $chat);

        self::assertSame(['incognito/7/scan.png', 'incognito/7/scan.png'], $deletedPaths);
        self::assertSame([$file, $chat], $removed);
    }

    public function testGeneratedDocumentsLinkedOnlyThroughAttachmentsAreReleased(): void
    {
        $message = $this->message(904, '');
        $file = $this->file(95, 'generated/7/brief.docx', ephemeral: true, messageId: null);
        $message->addFile($file);

        $this->fileRepository->method('findAllFilesByMessageIds')->willReturn([]);

        $this->documentRevisions->expects($this->once())->method('deleteForFile')->with(95);
        $this->vectorStorage->expects($this->once())->method('deleteByFile')->with(self::USER_ID, 95);
        $this->fileStorage->expects($this->once())->method('deleteFile')->with('generated/7/brief.docx')->willReturn(true);

        $removed = [];
        $this->em->method('remove')->willReturnCallback(static function (object $entity) use (&$removed): void {
            $removed[] = $entity;
        });

        $this->service([$message])->deleteOwnedChat(self::USER_ID, $this->chat());

        self::assertContains($file, $removed);
    }

    public function testLegacyMessagePathWithoutLibraryFileIsStillRemoved(): void
    {
        $message = $this->message(903, 'legacy/7/voice.ogg');
        $this->fileRepository->method('findAllFilesByMessageIds')->willReturn([]);

        $this->fileStorage->expects($this->once())->method('deleteFile')->with('legacy/7/voice.ogg')->willReturn(true);

        $this->service([$message])->deleteOwnedChat(self::USER_ID, $this->chat());
    }

    public function testForeignChatIsIgnored(): void
    {
        $chat = $this->chat(ownerId: 99);
        $this->fileRepository->expects($this->never())->method('findAllFilesByMessageIds');
        $this->em->expects($this->never())->method('remove');

        $this->service([])->deleteOwnedChat(self::USER_ID, $chat);
    }

    /**
     * @param list<Message> $messages
     */
    private function service(array $messages): ChatDeletionService
    {
        $messageRepository = $this->createMock(MessageRepository::class);
        $messageRepository->method('findAllByChatId')->willReturn($messages);
        $messageRepository->method('deleteByChatIds')->willReturn(count($messages));

        return new ChatDeletionService(
            $this->em,
            $this->createMock(ChatRepository::class),
            $this->createMock(ChatSummaryRepository::class),
            $messageRepository,
            $this->fileRepository,
            $this->fileStorage,
            $this->createMock(MessageDigestMaintenance::class),
            $this->createMock(ShareRepository::class),
            $this->createMock(OgImageService::class),
            $this->vectorStorage,
            $this->documentRevisions,
        );
    }

    private function chat(int $ownerId = self::USER_ID): Chat
    {
        $chat = new Chat();
        $chat->setUserId($ownerId);
        (new \ReflectionProperty(Chat::class, 'id'))->setValue($chat, self::CHAT_ID);

        return $chat;
    }

    private function message(int $id, string $legacyPath): Message
    {
        $message = new Message();
        $message->setUserId(self::USER_ID);
        $message->setFilePath($legacyPath);
        (new \ReflectionProperty(Message::class, 'id'))->setValue($message, $id);

        return $message;
    }

    private function file(int $id, string $path, bool $ephemeral, ?int $messageId): File
    {
        $file = new File();
        $file->setUserId(self::USER_ID);
        $file->setFilePath($path);
        $file->setEphemeral($ephemeral);
        $file->setMessageId($messageId);
        (new \ReflectionProperty(File::class, 'id'))->setValue($file, $id);

        return $file;
    }
}
