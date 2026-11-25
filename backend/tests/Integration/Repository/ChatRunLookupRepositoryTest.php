<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Chat;
use App\Entity\File;
use App\Entity\Message;
use App\Repository\ChatFileRepository;
use App\Repository\ChatMessageSearchRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What a chat run may look up in its chat (03 §3): every file of the chat
 * and of its project, and every message word for word. Nothing of another
 * chat or another person ever comes back.
 */
final class ChatRunLookupRepositoryTest extends KernelTestCase
{
    private const OWNER_ID = 987_654_401;
    private const STRANGER_ID = 987_654_402;

    private EntityManagerInterface $em;
    private ChatFileRepository $files;
    private ChatMessageSearchRepository $messages;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->files = static::getContainer()->get(ChatFileRepository::class);
        $this->messages = static::getContainer()->get(ChatMessageSearchRepository::class);
    }

    public function testEveryFileOfTheChatIsListedPagedNewestFirst(): void
    {
        $chat = $this->chat(self::OWNER_ID);
        $ids = [];
        for ($i = 1; $i <= 12; ++$i) {
            $message = $this->message($chat, 'IN', 'message '.$i);
            $ids[] = $this->file(self::OWNER_ID, 'picture-'.$i.'.png', $message->getId());
        }

        $first = $this->files->page(self::OWNER_ID, (int) $chat->getId(), null, 0, 10);
        $second = $this->files->page(self::OWNER_ID, (int) $chat->getId(), null, 10, 10);

        self::assertSame(12, $this->files->total(self::OWNER_ID, (int) $chat->getId(), null));
        self::assertCount(10, $first);
        self::assertSame($ids[11], $first[0]->getId());
        self::assertSame([$ids[1], $ids[0]], array_map(static fn (File $f): ?int => $f->getId(), $second));
    }

    public function testAnAttachedFileAndAProjectSourceBelongToTheChat(): void
    {
        $chat = $this->chat(self::OWNER_ID);
        $message = $this->message($chat, 'IN', 'see attachment');
        $attached = $this->persistFile(self::OWNER_ID, 'contract.pdf', null);
        $message->addFile($attached);
        $source = $this->file(self::OWNER_ID, 'brief.txt', null, 'project:abc');
        $this->em->flush();

        self::assertNotNull($this->files->findInChat(self::OWNER_ID, (int) $chat->getId(), null, (int) $attached->getId()));
        self::assertNull($this->files->findInChat(self::OWNER_ID, (int) $chat->getId(), null, $source), 'outside the project the source is not part of the chat');
        self::assertNotNull($this->files->findInChat(self::OWNER_ID, (int) $chat->getId(), 'project:abc', $source));
        self::assertSame(2, $this->files->total(self::OWNER_ID, (int) $chat->getId(), 'project:abc'));
    }

    public function testAHiddenProjectSourceIsNotPartOfTheChat(): void
    {
        $chat = $this->chat(self::OWNER_ID);
        $deleted = $this->persistFile(self::OWNER_ID, 'gone.txt', null, 'project:abc')->setSourceDeletedAt(time());
        $incognito = $this->persistFile(self::OWNER_ID, 'incognito.txt', null, 'project:abc')->setEphemeral(true);
        $this->em->flush();

        self::assertNull($this->files->findInChat(self::OWNER_ID, (int) $chat->getId(), 'project:abc', (int) $deleted->getId()));
        self::assertNull($this->files->findInChat(self::OWNER_ID, (int) $chat->getId(), 'project:abc', (int) $incognito->getId()));
        self::assertSame(0, $this->files->total(self::OWNER_ID, (int) $chat->getId(), 'project:abc'));
    }

    public function testAFileOfAnotherChatOrPersonDoesNotResolve(): void
    {
        $chat = $this->chat(self::OWNER_ID);
        $other = $this->chat(self::OWNER_ID);
        $elsewhere = $this->file(self::OWNER_ID, 'elsewhere.png', $this->message($other, 'IN', 'x')->getId());
        $theirs = $this->file(self::STRANGER_ID, 'theirs.png', $this->message($chat, 'IN', 'y')->getId());

        self::assertNull($this->files->findInChat(self::OWNER_ID, (int) $chat->getId(), null, $elsewhere));
        self::assertNull($this->files->findInChat(self::OWNER_ID, (int) $chat->getId(), null, $theirs));
        self::assertNull($this->files->findInChat(self::STRANGER_ID, (int) $chat->getId(), null, $theirs), 'the chat is not theirs');
    }

    public function testEveryTermMustMatchAndWildcardsAreLiteral(): void
    {
        $chat = $this->chat(self::OWNER_ID);
        $this->message($chat, 'IN', 'The budget for the kitchen is 4,800 euros.');
        $this->message($chat, 'OUT', 'A budget of 100% is not possible.');
        $hit = $this->message($chat, 'IN', 'Kitchen BUDGET again');

        $both = $this->messages->search(self::OWNER_ID, (int) $chat->getId(), ['budget', 'kitchen'], 10);
        $percent = $this->messages->search(self::OWNER_ID, (int) $chat->getId(), ['100%'], 10);
        $wildcard = $this->messages->search(self::OWNER_ID, (int) $chat->getId(), ['%'], 10);
        $withoutQuestion = $this->messages->search(self::OWNER_ID, (int) $chat->getId(), ['budget'], 10, [(int) $hit->getId()]);

        self::assertCount(2, $both);
        self::assertSame($hit->getId(), $both[0]->getId(), 'newest first, case does not matter');
        self::assertCount(1, $percent);
        self::assertCount(1, $wildcard, 'a % matches only a literal %');
        self::assertCount(2, $withoutQuestion, 'the run\'s own question is not a hit');
        self::assertNotContains($hit->getId(), array_map(static fn (Message $m): ?int => $m->getId(), $withoutQuestion));
    }

    public function testSearchStaysInsideTheChatOfItsOwner(): void
    {
        $chat = $this->chat(self::OWNER_ID);
        $other = $this->chat(self::OWNER_ID);
        $this->message($other, 'IN', 'secret plan');

        self::assertSame([], $this->messages->search(self::OWNER_ID, (int) $chat->getId(), ['secret'], 10));
        self::assertSame([], $this->messages->search(self::STRANGER_ID, (int) $other->getId(), ['secret'], 10));
    }

    public function testAroundReadsTheNeighboursOfAMessageInThisChatOnly(): void
    {
        $chat = $this->chat(self::OWNER_ID);
        $list = [];
        for ($i = 1; $i <= 7; ++$i) {
            $list[] = $this->message($chat, 0 === $i % 2 ? 'OUT' : 'IN', 'line '.$i);
        }
        $other = $this->chat(self::OWNER_ID);
        $foreign = $this->message($other, 'IN', 'foreign');

        $around = $this->messages->around(self::OWNER_ID, (int) $chat->getId(), (int) $list[3]->getId(), 2);

        self::assertSame(['line 2', 'line 3', 'line 4', 'line 5', 'line 6'], array_map(static fn (Message $m): string => $m->getText(), $around));
        self::assertSame([], $this->messages->around(self::OWNER_ID, (int) $chat->getId(), (int) $foreign->getId(), 2));
        self::assertSame([], $this->messages->around(self::STRANGER_ID, (int) $chat->getId(), (int) $list[3]->getId(), 2));
    }

    private function chat(int $userId): Chat
    {
        $chat = (new Chat())->setUserId($userId)->setTitle('Lookup chat')->setSource('web');
        $this->em->persist($chat);
        $this->em->flush();

        return $chat;
    }

    private function message(Chat $chat, string $direction, string $text): Message
    {
        $message = (new Message())
            ->setUserId($chat->getUserId())
            ->setChat($chat)
            ->setChatId($chat->getId())
            ->setText($text)
            ->setDirection($direction)
            ->setTrackingId(time())
            ->setProviderIndex('test')
            ->setDateTime(date('Y-m-d H:i:s'))
            ->setUnixTimestamp(time());
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function file(int $userId, string $name, ?int $messageId, ?string $groupKey = null): int
    {
        $file = $this->persistFile($userId, $name, $messageId, $groupKey);

        return (int) $file->getId();
    }

    private function persistFile(int $userId, string $name, ?int $messageId, ?string $groupKey = null): File
    {
        $file = (new File())->setUserId($userId)->setFileName($name)->setFilePath('test/'.$name)
            ->setFileType(pathinfo($name, \PATHINFO_EXTENSION))->setFileMime('application/octet-stream')
            ->setMessageId($messageId)->setGroupKey($groupKey);
        $this->em->persist($file);
        $this->em->flush();

        return $file;
    }
}
