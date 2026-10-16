<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\User;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cancel and stop must not flag another user's turn.
 */
class StreamCancellationAccessTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $owner;
    private User $stranger;
    private Chat $ownerChat;
    private Chat $strangerChat;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get('doctrine')->getManager();

        $suffix = bin2hex(random_bytes(4));
        $this->owner = $this->persistUser('cancel-owner-'.$suffix.'@example.com', 'own-'.$suffix);
        $this->stranger = $this->persistUser('cancel-stranger-'.$suffix.'@example.com', 'str-'.$suffix);
        $this->ownerChat = $this->persistChat($this->owner, 'Owner chat');
        $this->strangerChat = $this->persistChat($this->stranger, 'Stranger chat');
    }

    protected function tearDown(): void
    {
        if (isset($this->owner, $this->stranger)) {
            if (!$this->em->isOpen()) {
                self::bootKernel();
                $this->em = self::getContainer()->get('doctrine')->getManager();
            }

            foreach ([$this->owner, $this->stranger] as $user) {
                $fresh = $this->em->find(User::class, $user->getId());
                if (null === $fresh) {
                    continue;
                }
                foreach ($this->em->getRepository(Message::class)->findBy(['userId' => $fresh->getId()]) as $message) {
                    $this->em->remove($message);
                }
            }
            $this->em->flush();

            foreach ([$this->owner, $this->stranger] as $user) {
                foreach ($this->em->getRepository(Chat::class)->findBy(['userId' => $user->getId()]) as $chat) {
                    $this->em->remove($chat);
                }
            }
            $this->em->flush();

            foreach ([$this->owner, $this->stranger] as $user) {
                $fresh = $this->em->find(User::class, $user->getId());
                if (null !== $fresh) {
                    $this->em->remove($fresh);
                }
            }
            $this->em->flush();
        }

        static::ensureKernelShutdown();
        parent::tearDown();
    }

    public function testStrangerCannotCancelAnotherUsersNode(): void
    {
        $trackId = $this->uniqueTrackId();
        $this->persistIncoming($this->owner, $this->ownerChat, $trackId);

        $this->postAs($this->stranger, '/api/v1/messages/cancel-node', [
            'trackId' => (string) $trackId,
            'nodeId' => 'n1',
            'chatId' => $this->strangerChat->getId(),
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testOwnerCanCancelOwnNode(): void
    {
        $trackId = $this->uniqueTrackId();
        $this->persistIncoming($this->owner, $this->ownerChat, $trackId);

        $this->postAs($this->owner, '/api/v1/messages/cancel-node', [
            'trackId' => (string) $trackId,
            'nodeId' => 'n1',
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function testStrangerCannotStopAnotherUsersStream(): void
    {
        $trackId = $this->uniqueTrackId();
        $this->persistIncoming($this->owner, $this->ownerChat, $trackId);

        $this->postAs($this->stranger, '/api/v1/messages/stop-stream', [
            'trackId' => $trackId,
            'chatId' => $this->strangerChat->getId(),
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testOwnerCanStopUnpersistedTurnOnOwnChat(): void
    {
        $this->postAs($this->owner, '/api/v1/messages/stop-stream', [
            'trackId' => $this->uniqueTrackId(),
            'chatId' => $this->ownerChat->getId(),
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function testStrangerCannotStopUnpersistedTurnByNamingOwnersChat(): void
    {
        $this->postAs($this->stranger, '/api/v1/messages/stop-stream', [
            'trackId' => $this->uniqueTrackId(),
            'chatId' => $this->ownerChat->getId(),
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postAs(User $user, string $path, array $body): void
    {
        $token = $this->authenticateClient($this->client, $user);
        $this->client->request(
            'POST',
            $path,
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            ],
            (string) json_encode($body)
        );
    }

    private function persistUser(string $mail, string $providerId): User
    {
        $user = new User();
        $user->setMail($mail);
        $user->setPw(password_hash('testpass', PASSWORD_BCRYPT));
        $user->setUserLevel('PRO');
        $user->setProviderId($providerId);
        $user->setCreated(date('YmdHis'));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function persistChat(User $user, string $title): Chat
    {
        $chat = new Chat();
        $chat->setUserId($user->getId());
        $chat->setTitle($title);
        $chat->setCreatedAt(new \DateTime());
        $chat->setUpdatedAt(new \DateTime());
        $this->em->persist($chat);
        $this->em->flush();

        return $chat;
    }

    private function persistIncoming(User $user, Chat $chat, int $trackId): void
    {
        $message = new Message();
        $message->setUserId($user->getId());
        $message->setChat($chat);
        $message->setTrackingId($trackId);
        $message->setUnixTimestamp(time());
        $message->setDateTime(date('YmdHis'));
        $message->setText('Please summarize this note.');
        $message->setDirection('IN');
        $message->setProviderIndex('WEB');
        $this->em->persist($message);
        $this->em->flush();
    }

    private function uniqueTrackId(): int
    {
        return random_int(1_000_000_000, 2_000_000_000);
    }
}
