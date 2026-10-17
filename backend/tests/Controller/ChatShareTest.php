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
 * POST /api/v1/chats/{id}/share: the owner makes a chat public with a share
 * token and private again (the token stays), and another account is not
 * found and changes nothing.
 */
final class ChatShareTest extends WebTestCase
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

    public function testTheOwnerSharesAndUnsharesAChat(): void
    {
        $owner = $this->createUser('share-owner');
        $chat = $this->persistEntity((new Chat())->setUserId((int) $owner->getId())->setTitle('Holiday plans'));
        $this->authenticateClient($this->client, $owner);

        $shared = $this->share($chat, true);
        self::assertResponseIsSuccessful();
        self::assertTrue($shared['isShared']);
        self::assertNotEmpty($shared['shareToken']);
        self::assertTrue($this->reload($chat)->isPublic());

        $unshared = $this->share($chat, false);
        self::assertResponseIsSuccessful();
        self::assertSame([false, $shared['shareToken'], null], [$unshared['isShared'], $unshared['shareToken'], $unshared['shareUrl']]);
        self::assertFalse($this->reload($chat)->isPublic());
    }

    public function testAnotherAccountIsNotFoundAndChangesNothing(): void
    {
        $chat = $this->persistEntity((new Chat())->setUserId((int) $this->createUser('share-owner')->getId())->setTitle('Private'));
        $this->authenticateClient($this->client, $this->createUser('share-stranger'));

        $this->share($chat, true);

        self::assertResponseStatusCodeSame(404);
        $stored = $this->reload($chat);
        self::assertSame([false, null], [$stored->isPublic(), $stored->getShareToken()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function share(Chat $chat, bool $enable): array
    {
        $this->client->request('POST', '/api/v1/chats/'.$chat->getId().'/share', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['enable' => $enable]));

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function reload(Chat $chat): Chat
    {
        $this->em->clear();
        $stored = $this->em->find(Chat::class, $chat->getId());
        self::assertNotNull($stored);

        return $stored;
    }
}
