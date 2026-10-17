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

    private function chatOf(int $userId): Chat
    {
        return $this->persistEntity((new Chat())->setUserId($userId)->setSource('web')->setTitle('Library'));
    }

    private function reload(Chat $chat): Chat
    {
        $this->em->clear();
        $stored = $this->em->find(Chat::class, $chat->getId());
        self::assertNotNull($stored);

        return $stored;
    }
}
