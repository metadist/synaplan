<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Chat;
use App\Tests\Trait\AuthenticatedTestTrait;
use App\Tests\Trait\CreatesTestEntities;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The chat library's writes: archive, unarchive and tags are stored for the
 * owner, and a chat of another account is not found and stays unchanged.
 */
final class ChatLibraryControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;
    use CreatesTestEntities;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->removeCreatedEntities();
        parent::tearDown();
    }

    public function testArchiveUnarchiveAndTagsAreStoredForTheOwner(): void
    {
        $owner = $this->createUser('library-owner');
        $chat = $this->chatOf((int) $owner->getId());
        $this->authenticateClient($this->client, $owner);

        $this->client->request('POST', '/api/v1/chats/'.$chat->getId().'/archive');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->reload($chat)->isArchived());

        $this->client->request('POST', '/api/v1/chats/'.$chat->getId().'/unarchive');
        self::assertResponseIsSuccessful();
        self::assertFalse($this->reload($chat)->isArchived());

        $this->client->request('PUT', '/api/v1/chats/'.$chat->getId().'/tags', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['tags' => ['work', 42, 'travel']]));
        self::assertResponseIsSuccessful();
        self::assertSame(['work', 'travel'], $this->reload($chat)->getTags());
    }

    public function testAChatOfAnotherAccountIsNotFoundAndStaysUnchanged(): void
    {
        $chat = $this->chatOf((int) $this->createUser('library-owner')->getId());
        $this->authenticateClient($this->client, $this->createUser('library-stranger'));

        $this->client->request('POST', '/api/v1/chats/'.$chat->getId().'/archive');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('PUT', '/api/v1/chats/'.$chat->getId().'/tags', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['tags' => ['mine']]));
        self::assertResponseStatusCodeSame(404);

        $stored = $this->reload($chat);
        self::assertSame([false, []], [$stored->isArchived(), $stored->getTags()]);
    }

    public function testAnonymousArchiveRoutesAreUnauthorized(): void
    {
        $chat = $this->chatOf((int) $this->createUser('library-owner')->getId(), true, 'Private');
        foreach (['/archive', '/unarchive'] as $suffix) {
            $this->client->request('POST', '/api/v1/chats/'.$chat->getId().$suffix);
            self::assertResponseStatusCodeSame(401);
        }
        $this->client->request('PUT', '/api/v1/chats/'.$chat->getId().'/tags', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['tags' => ['mine']]));
        self::assertResponseStatusCodeSame(401);
        $this->client->request('GET', '/api/v1/chats/'.$chat->getId().'/export');
        self::assertResponseStatusCodeSame(401);

        $stored = $this->reload($chat);
        self::assertTrue($stored->isArchived());
        self::assertSame([], $stored->getTags());
    }

    public function testUnarchiveAndExportOfAnotherAccountAreNotFound(): void
    {
        $chat = $this->chatOf((int) $this->createUser('library-owner')->getId(), true, 'Secret title');
        $this->authenticateClient($this->client, $this->createUser('library-stranger'));

        $this->client->request('POST', '/api/v1/chats/'.$chat->getId().'/unarchive');
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('Secret title', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/api/v1/chats/'.$chat->getId().'/export');
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('Secret title', (string) $this->client->getResponse()->getContent());

        self::assertTrue($this->reload($chat)->isArchived());
    }

    public function testChatListIsolatesArchivedRowsAndAccounts(): void
    {
        $owner = $this->createUser('library-owner');
        $ownerId = (int) $owner->getId();
        $open = $this->chatOf($ownerId, false, 'Open row');
        $older = $this->chatOf($ownerId, true, 'Older archived');
        $newer = $this->chatOf($ownerId, true, 'Newer archived');
        $older->setUpdatedAt(new \DateTime('2020-01-01 00:00:00'));
        $newer->setUpdatedAt(new \DateTime('2024-06-01 00:00:00'));
        $stranger = $this->chatOf((int) $this->createUser('library-stranger')->getId(), true, 'Stranger archived');
        $this->em->flush();
        $this->authenticateClient($this->client, $owner);

        $this->client->request('GET', '/api/v1/chats?archived=0');
        self::assertResponseIsSuccessful();
        self::assertSame([$open->getTitle()], $this->titles());
        self::assertNotContains($stranger->getTitle(), $this->titles());

        $this->client->request('GET', '/api/v1/chats?archived=1');
        self::assertResponseIsSuccessful();
        self::assertSame([$newer->getTitle(), $older->getTitle()], $this->titles());
        self::assertNotContains($stranger->getTitle(), $this->titles());
        self::assertSame([true, true], $this->archivedFlags());

        $this->client->request('GET', '/api/v1/chats?archived=1&limit=1&offset=0');
        self::assertResponseIsSuccessful();
        self::assertSame([$newer->getTitle()], $this->titles());

        $this->client->request('GET', '/api/v1/chats?archived=1&limit=1&offset=1');
        self::assertResponseIsSuccessful();
        self::assertSame([$older->getTitle()], $this->titles());

        $this->client->request('GET', '/api/v1/chats?archived=0&limit=30&offset=0');
        self::assertResponseIsSuccessful();
        self::assertSame([$open->getTitle()], $this->titles());
        self::assertSame([false], $this->archivedFlags());
    }

    private function chatOf(int $userId, bool $archived = false, string $title = 'Library'): Chat
    {
        return $this->persistEntity((new Chat())
            ->setUserId($userId)
            ->setSource('web')
            ->setTitle($title)
            ->setArchived($archived));
    }

    /**
     * @return list<string>
     */
    private function titles(): array
    {
        return array_map(static fn (array $chat): string => (string) $chat['title'], $this->chatRows());
    }

    /**
     * @return list<bool>
     */
    private function archivedFlags(): array
    {
        return array_map(static fn (array $chat): bool => true === ($chat['archived'] ?? null), $this->chatRows());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function chatRows(): array
    {
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        $chats = $payload['chats'] ?? null;
        self::assertIsArray($chats);
        $rows = [];
        foreach ($chats as $chat) {
            self::assertIsArray($chat);
            $rows[] = $chat;
        }

        return $rows;
    }

    private function reload(Chat $chat): Chat
    {
        $this->em->clear();
        $stored = $this->em->find(Chat::class, $chat->getId());
        self::assertNotNull($stored);

        return $stored;
    }
}
