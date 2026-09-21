<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Chat;
use App\Entity\Group;
use App\Entity\GroupMember;
use App\Entity\Message;
use App\Entity\Share;
use App\Entity\User;
use App\Entity\UserMemory;
use App\Repository\ConfigRepository;
use App\Repository\UserMemoryRepository;
use App\Service\Chat\ChatDeletionService;
use App\Service\Iam\IamConfig;
use App\Service\Iam\ResourceKind\ConversationKind;
use App\Service\Iam\ShareService;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * #2068 — feedback referencing a message must verify the caller can see that
 * message, and entries derived from a shared conversation must not outlive
 * the grant.
 */
final class FeedbackAccessTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->enableSharing();
    }

    public function testFalsePositiveWithUnknownMessageReturns404(): void
    {
        $user = $this->createUser('feedback-404@synaplan.internal');
        $this->authenticateClient($this->client, $user);

        $this->client->request(
            'POST',
            '/api/v1/feedback/false-positive',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['summary' => 'Claims the moon is made of cheese.', 'messageId' => 999999999])
        );

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    public function testFalsePositiveWithForeignMessageReturns403(): void
    {
        $owner = $this->createUser('feedback-owner@synaplan.internal');
        $stranger = $this->createUser('feedback-stranger@synaplan.internal');
        $message = $this->createMessage($owner, 'Claims the moon is made of cheese.');
        $this->authenticateClient($this->client, $stranger);

        $this->client->request(
            'POST',
            '/api/v1/feedback/false-positive',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['summary' => 'Claims the moon is made of cheese.', 'messageId' => $message->getId()])
        );

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    public function testPositiveWithForeignMessageReturns403(): void
    {
        $owner = $this->createUser('feedback-positive-owner@synaplan.internal');
        $stranger = $this->createUser('feedback-positive-stranger@synaplan.internal');
        $message = $this->createMessage($owner, 'The capital of Australia is Sydney.');
        $this->authenticateClient($this->client, $stranger);

        $this->client->request(
            'POST',
            '/api/v1/feedback/positive',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['text' => 'The capital of Australia is Canberra.', 'messageId' => $message->getId()])
        );

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    /**
     * A shared message passes the access check and reaches the memory service.
     * Whether the entry is then stored depends on Qdrant, which is up locally
     * but absent in CI — so both 200 (stored) and 503 (no memory service)
     * prove the request was not rejected with 403/404 by the new check.
     */
    public function testSharedMessagePassesTheAccessCheck(): void
    {
        $owner = $this->createUser('feedback-shared-owner@synaplan.internal');
        $recipient = $this->createUser('feedback-shared-recipient@synaplan.internal');
        $chat = $this->createChat((int) $owner->getId(), 'shared feedback chat');
        $message = $this->createMessage($owner, 'The capital of Australia is Sydney.', $chat);
        $this->shares()->grant($owner, ConversationKind::KEY, (string) $chat->getId(), Share::SUBJECT_USER, (int) $recipient->getId(), 'use');
        $this->authenticateClient($this->client, $recipient);

        $this->client->request(
            'POST',
            '/api/v1/feedback/false-positive',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['summary' => 'Claims Sydney is the capital of Australia.', 'messageId' => $message->getId()])
        );

        $status = $this->client->getResponse()->getStatusCode();
        self::assertContains(
            $status,
            [Response::HTTP_OK, Response::HTTP_SERVICE_UNAVAILABLE],
            'a shared message must pass the access check (stored, or 503 when Qdrant is down)'
        );
        if (Response::HTTP_OK === $status) {
            $body = json_decode((string) $this->client->getResponse()->getContent(), true);
            self::assertSame($message->getId(), $body['example']['messageId'] ?? null);
        }
    }

    public function testRevokeWithdrawsDerivedFeedback(): void
    {
        $owner = $this->createUser('feedback-revoke-owner@synaplan.internal');
        $recipient = $this->createUser('feedback-revoke-recipient@synaplan.internal');
        $chat = $this->createChat((int) $owner->getId(), 'revoked feedback chat');
        $message = $this->createMessage($owner, 'The capital of Australia is Sydney.', $chat);
        $memoryId = $this->createFeedbackRow((int) $recipient->getId(), (int) $message->getId());
        $this->shares()->grant($owner, ConversationKind::KEY, (string) $chat->getId(), Share::SUBJECT_USER, (int) $recipient->getId(), 'use');

        $this->shares()->revoke($owner, ConversationKind::KEY, (string) $chat->getId(), Share::SUBJECT_USER, (int) $recipient->getId());

        self::assertNull(
            $this->memories()->findForUser($memoryId, (int) $recipient->getId()),
            'derived feedback must not outlive the grant'
        );
    }

    public function testRevokeKeepsFeedbackWhenGroupGrantRemains(): void
    {
        $owner = $this->createUser('feedback-keep-owner@synaplan.internal');
        $recipient = $this->createUser('feedback-keep-recipient@synaplan.internal');
        $chat = $this->createChat((int) $owner->getId(), 'multi-grant feedback chat');
        $message = $this->createMessage($owner, 'The capital of Australia is Sydney.', $chat);
        $memoryId = $this->createFeedbackRow((int) $recipient->getId(), (int) $message->getId());

        $group = $this->createGroup('Feedback Keepers '.uniqid());
        $this->addMember($group, (int) $recipient->getId());
        $this->shares()->grant($owner, ConversationKind::KEY, (string) $chat->getId(), Share::SUBJECT_USER, (int) $recipient->getId(), 'use');
        $this->shares()->grant($owner, ConversationKind::KEY, (string) $chat->getId(), Share::SUBJECT_GROUP, (int) $group->getId(), 'use');

        $this->shares()->revoke($owner, ConversationKind::KEY, (string) $chat->getId(), Share::SUBJECT_USER, (int) $recipient->getId());

        self::assertNotNull(
            $this->memories()->findForUser($memoryId, (int) $recipient->getId()),
            'feedback survives while the recipient keeps access through another grant'
        );
    }

    public function testRevokeWithdrawsFeedbackWhenSharingIsDisabledForTheRecipient(): void
    {
        $owner = $this->createUser('feedback-flag-owner@synaplan.internal');
        $recipient = $this->createUser('feedback-flag-recipient@synaplan.internal');
        $chat = $this->createChat((int) $owner->getId(), 'flag-off feedback chat');
        $message = $this->createMessage($owner, 'The capital of Australia is Sydney.', $chat);
        $memoryId = $this->createFeedbackRow((int) $recipient->getId(), (int) $message->getId());

        $group = $this->createGroup('Feedback Flagged '.uniqid());
        $this->addMember($group, (int) $recipient->getId());
        $this->shares()->grant($owner, ConversationKind::KEY, (string) $chat->getId(), Share::SUBJECT_USER, (int) $recipient->getId(), 'use');
        $this->shares()->grant($owner, ConversationKind::KEY, (string) $chat->getId(), Share::SUBJECT_GROUP, (int) $group->getId(), 'use');

        // Sharing disabled for the recipient only: the group row is stale for
        // them (AccessGate denies), so the entries must go on revoke.
        static::getContainer()->get(ConfigRepository::class)->setValue(
            (int) $recipient->getId(),
            IamConfig::CONFIG_GROUP,
            IamConfig::KEY_SHARING_ENABLED,
            '0'
        );
        $this->em->flush();

        try {
            $this->shares()->revoke($owner, ConversationKind::KEY, (string) $chat->getId(), Share::SUBJECT_USER, (int) $recipient->getId());

            self::assertNull(
                $this->memories()->findForUser($memoryId, (int) $recipient->getId()),
                'a stale share row must not retain derived feedback without effective access'
            );
        } finally {
            static::getContainer()->get(ConfigRepository::class)->setValue(
                (int) $recipient->getId(),
                IamConfig::CONFIG_GROUP,
                IamConfig::KEY_SHARING_ENABLED,
                '1'
            );
            $this->em->flush();
        }
    }

    public function testChatDeletionWithdrawsDerivedFeedback(): void
    {
        $owner = $this->createUser('feedback-delete-owner@synaplan.internal');
        $recipient = $this->createUser('feedback-delete-recipient@synaplan.internal');
        $chat = $this->createChat((int) $owner->getId(), 'deleted feedback chat');
        $message = $this->createMessage($owner, 'The capital of Australia is Sydney.', $chat);
        $memoryId = $this->createFeedbackRow((int) $recipient->getId(), (int) $message->getId());
        $this->shares()->grant($owner, ConversationKind::KEY, (string) $chat->getId(), Share::SUBJECT_USER, (int) $recipient->getId(), 'use');

        static::getContainer()->get(ChatDeletionService::class)->deleteOwnedChat((int) $owner->getId(), $chat);

        self::assertNull(
            $this->memories()->findForUser($memoryId, (int) $recipient->getId()),
            'derived feedback must not outlive the deleted conversation'
        );
    }

    private function shares(): ShareService
    {
        return static::getContainer()->get(ShareService::class);
    }

    private function memories(): UserMemoryRepository
    {
        return static::getContainer()->get(UserMemoryRepository::class);
    }

    private function enableSharing(): void
    {
        $config = static::getContainer()->get(ConfigRepository::class);
        $config->setValue(0, IamConfig::CONFIG_GROUP, IamConfig::KEY_GROUPS_ENABLED, '1');
        $config->setValue(0, IamConfig::CONFIG_GROUP, IamConfig::KEY_SHARING_ENABLED, '1');
        $this->em->flush();
    }

    private function createUser(string $email): User
    {
        $existing = $this->em->getRepository(User::class)->findOneBy(['mail' => $email]);
        if ($existing instanceof User) {
            return $existing;
        }

        $user = (new User())
            ->setMail($email)
            ->setType('WEB')
            ->setProviderId('feedback-test-'.uniqid())
            ->setUserLevel('NEW');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createChat(int $userId, string $title): Chat
    {
        $chat = new Chat();
        $chat->setUserId($userId);
        $chat->setTitle($title);
        $this->em->persist($chat);
        $this->em->flush();

        return $chat;
    }

    private function createMessage(User $owner, string $text, ?Chat $chat = null): Message
    {
        $chat ??= $this->createChat((int) $owner->getId(), 'feedback message chat '.uniqid());
        $message = new Message();
        $message->setText($text);
        $message->setDirection('OUT');
        $message->setUserId($owner->getId());
        $message->setChat($chat);
        $message->setChatId($chat->getId());
        $message->setTrackingId(time());
        $message->setProviderIndex('test');
        $message->setDateTime(date('Y-m-d H:i:s'));
        $message->setUnixTimestamp(time());
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    /**
     * A recipient's derived feedback entry, persisted directly: the creation
     * service needs Qdrant, which the test env does not have, and the revoke
     * path under test only reads these rows.
     */
    private function createFeedbackRow(int $userId, int $messageId): int
    {
        $memory = new UserMemory(
            id: random_int(1_000_000_000_000, 9_000_000_000_000),
            userId: $userId,
            category: 'feedback_negative',
            key: 'false_positive',
            value: 'Claims Sydney is the capital of Australia.',
            source: UserMemory::SOURCE_USER_CREATED,
            messageId: $messageId,
            namespace: 'feedback_false_positive',
        );
        $this->em->persist($memory);
        $this->em->flush();

        return $memory->getId();
    }

    private function createGroup(string $name): Group
    {
        $group = new Group();
        $group->setName($name);
        $group->setSlug(strtolower(str_replace(' ', '-', $name)).'-'.uniqid());
        $this->em->persist($group);
        $this->em->flush();

        return $group;
    }

    private function addMember(Group $group, int $userId): void
    {
        $member = new GroupMember((int) $group->getId(), $userId);
        $this->em->persist($member);
        $this->em->flush();
    }
}
