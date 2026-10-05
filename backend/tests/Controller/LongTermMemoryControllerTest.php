<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Chat;
use App\Entity\MessageDigest;
use App\Entity\User;
use App\Repository\MessageDigestRepository;
use App\Service\Digest\MessageDigestConfig;
use App\Service\Digest\MessageDigestService;
use App\Service\VectorSearch\QdrantClientInterface;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * List, delete and export for long-term memory (message digests).
 */
final class LongTermMemoryControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var list<int> */
    private array $userIds = [];

    /** @var list<list<string>> */
    private array $digestPointBatches = [];

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $em = static::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->userIds = [];
        $this->digestPointBatches = [];

        $qdrant = $this->createMock(QdrantClientInterface::class);
        $qdrant->method('isAvailable')->willReturn(true);
        $qdrant->method('deleteDigests')->willReturnCallback(function (array $pointIds): void {
            $this->digestPointBatches[] = $pointIds;
        });
        $qdrant->expects($this->never())->method('deleteDigest');
        static::getContainer()->set(QdrantClientInterface::class, $qdrant);
    }

    protected function tearDown(): void
    {
        if ([] !== $this->userIds && $this->em->isOpen()) {
            $this->em->getConnection()->executeStatement(
                'DELETE FROM BMESSAGEDIGESTS WHERE BUSERID IN (:ids)',
                ['ids' => $this->userIds],
                ['ids' => ArrayParameterType::INTEGER],
            );
            $this->em->getConnection()->executeStatement(
                'DELETE FROM BCHATS WHERE BUSERID IN (:ids)',
                ['ids' => $this->userIds],
                ['ids' => ArrayParameterType::INTEGER],
            );
            foreach ($this->userIds as $userId) {
                $user = $this->em->find(User::class, $userId);
                if ($user instanceof User) {
                    $this->em->remove($user);
                }
            }
            $this->em->flush();
        }

        static::ensureKernelShutdown();
        parent::tearDown();
    }

    public function testListRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/user/message-digests/entries');

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
    }

    public function testListPagesOrdersAndNullsChatTitles(): void
    {
        $owner = $this->createUser(false);
        $other = $this->createUser(true);
        $realChat = $this->createChat($owner, 'Q3 planning');
        $newChat = $this->createChat($owner, 'New Chat');
        $prefixed = $this->createChat($owner, 'Chat 12');
        $german = $this->createChat($owner, 'Neuer Chat');
        $foreignChat = $this->createChat($other, 'Secret project');

        $base = random_int(8_000_000_000_000, 8_800_000_000_000);
        $messageBase = random_int(800_000_000, 890_000_000);
        $ownerId = (int) $owner->getId();
        $rows = [
            'oldest' => ['id' => $base + 1, 'messageId' => $messageBase + 1, 'chatId' => $realChat, 'title' => 'oldest real chat', 'channel' => 'web', 'sourceDate' => 1_000, 'created' => 10],
            'placeholder' => ['id' => $base + 2, 'messageId' => $messageBase + 2, 'chatId' => $newChat, 'title' => 'new chat placeholder', 'channel' => 'whatsapp', 'sourceDate' => 2_000, 'created' => 20],
            'prefixed' => ['id' => $base + 3, 'messageId' => $messageBase + 3, 'chatId' => $prefixed, 'title' => 'prefixed placeholder', 'channel' => 'email', 'sourceDate' => 3_000, 'created' => 30],
            'tieHigh' => ['id' => $base + 4, 'messageId' => $messageBase + 4, 'chatId' => $foreignChat, 'title' => 'foreign chat', 'channel' => 'web', 'sourceDate' => 3_000, 'created' => 40],
            'newest' => ['id' => $base + 5, 'messageId' => $messageBase + 5, 'chatId' => 0, 'title' => 'no chat', 'channel' => 'api', 'sourceDate' => 4_000, 'created' => 50],
            'gone' => ['id' => $base + 6, 'messageId' => $messageBase + 6, 'chatId' => 999_999_991, 'title' => 'gone chat', 'channel' => 'web', 'sourceDate' => 1_500, 'created' => 60],
            'german' => ['id' => $base + 7, 'messageId' => $messageBase + 7, 'chatId' => $german, 'title' => 'german placeholder', 'channel' => 'web', 'sourceDate' => 2_500, 'created' => 70],
        ];
        foreach ($rows as $row) {
            $this->insertDigest($row['id'], $ownerId, $row['messageId'], $row['chatId'], $row['title'], $row['channel'], $row['sourceDate'], true, $row['created']);
        }
        $this->insertDigest($base + 8, $ownerId, $messageBase + 8, $realChat, 'inactive', 'web', 9_000, false, 80);
        $this->insertDigest($base + 9, (int) $other->getId(), $messageBase + 9, $foreignChat, 'other user', 'web', 8_000, true, 90);

        $this->asUser($owner);
        $this->client->request('GET', '/api/v1/user/message-digests/entries', ['page' => 1, 'limit' => 3]);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $page1 = $this->jsonBody();
        $config = static::getContainer()->get(MessageDigestConfig::class);
        self::assertInstanceOf(MessageDigestConfig::class, $config);
        self::assertSame($config->isEnabled(), $page1['enabled']);
        self::assertFalse($page1['memoriesEnabled']);
        self::assertSame(7, $page1['total']);
        self::assertSame(1, $page1['page']);
        self::assertSame(3, $page1['limit']);
        self::assertSame(
            [$rows['newest']['id'], $rows['tieHigh']['id'], $rows['prefixed']['id']],
            array_column($page1['entries'], 'id'),
        );
        self::assertNull($page1['entries'][0]['chatId']);
        self::assertNull($page1['entries'][0]['chatTitle']);
        self::assertSame('no chat', $page1['entries'][0]['title']);
        self::assertSame('api', $page1['entries'][0]['channel']);
        self::assertSame(4_000, $page1['entries'][0]['sourceDate']);
        self::assertSame(50, $page1['entries'][0]['created']);
        self::assertSame($rows['newest']['messageId'], $page1['entries'][0]['messageId']);
        self::assertSame($foreignChat, $page1['entries'][1]['chatId']);
        self::assertNull($page1['entries'][1]['chatTitle']);
        self::assertNull($page1['entries'][2]['chatTitle']);

        $this->client->request('GET', '/api/v1/user/message-digests/entries', ['page' => 2, 'limit' => 3]);
        $page2 = $this->jsonBody();
        self::assertSame(
            [$rows['german']['id'], $rows['placeholder']['id'], $rows['gone']['id']],
            array_column($page2['entries'], 'id'),
        );
        self::assertNull($page2['entries'][0]['chatTitle']);
        self::assertNull($page2['entries'][1]['chatTitle']);
        self::assertSame(999_999_991, $page2['entries'][2]['chatId']);
        self::assertNull($page2['entries'][2]['chatTitle']);

        $this->client->request('GET', '/api/v1/user/message-digests/entries', ['page' => 3, 'limit' => 3]);
        $page3 = $this->jsonBody();
        self::assertSame([$rows['oldest']['id']], array_column($page3['entries'], 'id'));
        self::assertSame($realChat, $page3['entries'][0]['chatId']);
        self::assertSame('Q3 planning', $page3['entries'][0]['chatTitle']);
        self::assertSame('oldest real chat', $page3['entries'][0]['title']);
        self::assertSame('web', $page3['entries'][0]['channel']);
        self::assertSame(1_000, $page3['entries'][0]['sourceDate']);
        self::assertSame(10, $page3['entries'][0]['created']);

        $this->asUser($other);
        $this->client->request('GET', '/api/v1/user/message-digests/entries');
        $otherPage = $this->jsonBody();
        self::assertSame(1, $otherPage['total']);
        self::assertSame('other user', $otherPage['entries'][0]['title']);
        self::assertSame($foreignChat, $otherPage['entries'][0]['chatId']);
        self::assertSame('Secret project', $otherPage['entries'][0]['chatTitle']);
        self::assertTrue($otherPage['memoriesEnabled']);
        self::assertSame(1, $otherPage['page']);
        self::assertSame(25, $otherPage['limit']);
        self::assertSame([], $this->digestPointBatches);
    }

    public function testListClampsPageAndLimit(): void
    {
        $owner = $this->createUser(true);
        $ownerId = (int) $owner->getId();
        $base = random_int(8_000_000_000_000, 8_800_000_000_000);
        $this->insertDigest($base + 1, $ownerId, $base + 11, 0, 'older', 'web', 100, true, 1);
        $this->insertDigest($base + 2, $ownerId, $base + 12, 0, 'newer', 'web', 200, true, 2);

        $this->asUser($owner);
        $this->client->request('GET', '/api/v1/user/message-digests/entries', ['page' => 0, 'limit' => 1000]);
        $clamped = $this->jsonBody();
        self::assertSame(1, $clamped['page']);
        self::assertSame(100, $clamped['limit']);
        self::assertSame(2, $clamped['total']);
        self::assertCount(2, $clamped['entries']);

        $this->client->request('GET', '/api/v1/user/message-digests/entries', ['limit' => 1]);
        $one = $this->jsonBody();
        self::assertSame(1, $one['limit']);
        self::assertSame(2, $one['total']);
        self::assertSame(['newer'], array_column($one['entries'], 'title'));
    }

    public function testDeleteOneDeactivatesTheEntryAndKeepsItsMessageDigested(): void
    {
        $owner = $this->createUser(true);
        $other = $this->createUser(true);
        $ownerId = (int) $owner->getId();
        $base = random_int(8_000_000_000_000, 8_800_000_000_000);
        $messageId = random_int(800_000_000, 890_000_000);
        $ownId = $base + 1;
        $foreignId = $base + 2;
        $inactiveId = $base + 3;
        $this->insertDigest($ownId, $ownerId, $messageId, 0, 'mine', 'web', 100, true, 1);
        $this->insertDigest($foreignId, (int) $other->getId(), $messageId + 1, 0, 'theirs', 'web', 100, true, 1);
        $this->insertDigest($inactiveId, $ownerId, $messageId + 2, 0, 'already gone', 'web', 100, false, 1);

        $this->asUser($owner);
        $this->client->request('DELETE', '/api/v1/user/message-digests/'.$foreignId);
        $this->assertStatus(Response::HTTP_NOT_FOUND);
        self::assertSame(['error' => 'Long-term memory entry not found'], $this->jsonBody());
        self::assertSame(1, $this->activeFlag($foreignId));

        $this->client->request('DELETE', '/api/v1/user/message-digests/'.($base + 99));
        $this->assertStatus(Response::HTTP_NOT_FOUND);
        self::assertSame(['error' => 'Long-term memory entry not found'], $this->jsonBody());

        $this->client->request('DELETE', '/api/v1/user/message-digests/'.$inactiveId);
        $this->assertStatus(Response::HTTP_NOT_FOUND);
        self::assertSame(0, $this->activeFlag($inactiveId));
        self::assertSame([], $this->digestPointBatches);

        $this->client->request('DELETE', '/api/v1/user/message-digests/'.$ownId);
        $this->assertStatus(Response::HTTP_OK);
        self::assertSame(['success' => true], $this->jsonBody());
        self::assertSame(0, $this->activeFlag($ownId));
        self::assertSame([[MessageDigestService::qdrantPointId($ownerId, $ownId)]], $this->digestPointBatches);

        $repository = $this->em->getRepository(MessageDigest::class);
        self::assertInstanceOf(MessageDigestRepository::class, $repository);
        self::assertEqualsCanonicalizing(
            [$messageId, $messageId + 2],
            $repository->findDigestedMessageIds($ownerId, [$messageId, $messageId + 2]),
        );

        $this->client->request('DELETE', '/api/v1/user/message-digests/'.$ownId);
        $this->assertStatus(Response::HTTP_NOT_FOUND);

        $this->client->request('GET', '/api/v1/user/message-digests/entries');
        self::assertSame([], $this->jsonBody()['entries']);
    }

    public function testDeleteAllDeactivatesOnlyTheCurrentUsersActiveEntries(): void
    {
        $owner = $this->createUser(true);
        $other = $this->createUser(true);
        $ownerId = (int) $owner->getId();
        $otherId = (int) $other->getId();
        $base = random_int(8_000_000_000_000, 8_800_000_000_000);
        $activeIds = [$base + 1, $base + 4, $base + 2];
        foreach ($activeIds as $offset => $id) {
            $this->insertDigest($id, $ownerId, $base + 20 + $offset, 0, 'active '.$id, 'web', 100 + $offset, true, 1);
        }
        $inactiveId = $base + 3;
        $foreignId = $base + 5;
        $this->insertDigest($inactiveId, $ownerId, $base + 30, 0, 'inactive', 'web', 50, false, 1);
        $this->insertDigest($foreignId, $otherId, $base + 40, 0, 'foreign', 'web', 80, true, 1);

        $this->asUser($owner);
        $this->client->request('DELETE', '/api/v1/user/message-digests');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame(['success' => true, 'deleted' => 3], $this->jsonBody());
        foreach ($activeIds as $id) {
            self::assertSame(0, $this->activeFlag($id));
        }
        self::assertSame(0, $this->activeFlag($inactiveId));
        self::assertSame(1, $this->activeFlag($foreignId));

        $expected = array_map(
            static fn (int $id): string => MessageDigestService::qdrantPointId($ownerId, $id),
            [$base + 1, $base + 2, $base + 4],
        );
        self::assertSame([$expected], $this->digestPointBatches);

        $repository = $this->em->getRepository(MessageDigest::class);
        self::assertInstanceOf(MessageDigestRepository::class, $repository);
        self::assertEqualsCanonicalizing(
            [$base + 20, $base + 21, $base + 22],
            $repository->findDigestedMessageIds($ownerId, [$base + 20, $base + 21, $base + 22]),
        );
    }

    public function testExportIncludesActiveLongTermMemoryInListOrder(): void
    {
        $owner = $this->createUser(true);
        $ownerId = (int) $owner->getId();
        $chatId = $this->createChat($owner, 'Q3 planning');
        $base = random_int(8_000_000_000_000, 8_800_000_000_000);
        $olderMessage = random_int(800_000_000, 890_000_000);
        $newerMessage = $olderMessage + 1;
        $this->insertDigest($base + 1, $ownerId, $olderMessage, 0, 'older', 'email', 100, true, 1);
        $this->insertDigest($base + 2, $ownerId, $newerMessage, $chatId, 'newer', 'web', 200, true, 2);
        $this->insertDigest($base + 3, $ownerId, $olderMessage + 2, $chatId, 'inactive', 'web', 900, false, 3);

        $this->asUser($owner);
        $this->client->request('GET', '/api/v1/user/memories/export');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('attachment;', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $body = $this->jsonBody();
        self::assertIsArray($body['memories']);
        self::assertSame([
            ['title' => 'newer', 'messageId' => $newerMessage, 'chatId' => $chatId, 'channel' => 'web', 'sourceDate' => 200],
            ['title' => 'older', 'messageId' => $olderMessage, 'chatId' => null, 'channel' => 'email', 'sourceDate' => 100],
        ], $body['longTermMemory']);
    }

    public function testResolveStillReturnsOnlyActiveOwnDigests(): void
    {
        $owner = $this->createUser(true);
        $other = $this->createUser(true);
        $ownerId = (int) $owner->getId();
        $base = random_int(8_000_000_000_000, 8_800_000_000_000);
        $activeMessage = random_int(800_000_000, 890_000_000);
        $inactiveMessage = $activeMessage + 1;
        $foreignMessage = $activeMessage + 2;
        $this->insertDigest($base + 1, $ownerId, $activeMessage, 4, 'visible', 'web', 100, true, 1);
        $this->insertDigest($base + 2, $ownerId, $inactiveMessage, 4, 'hidden', 'web', 100, false, 1);
        $this->insertDigest($base + 3, (int) $other->getId(), $foreignMessage, 4, 'foreign', 'web', 100, true, 1);

        $this->asUser($owner);
        $this->client->request('GET', '/api/v1/user/message-digests', [
            'ids' => $activeMessage.','.$inactiveMessage.','.$foreignMessage,
        ]);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $body = $this->jsonBody();
        self::assertSame(1, $body['total']);
        self::assertSame($activeMessage, $body['digests'][0]['messageId']);
        self::assertSame('visible', $body['digests'][0]['title']);
    }

    private function assertStatus(int $expected): void
    {
        self::assertSame($expected, $this->client->getResponse()->getStatusCode());
    }

    private function asUser(User $user): void
    {
        $this->authenticateClient($this->client, $user);
    }

    private function createUser(bool $memoriesEnabled): User
    {
        $user = (new User())
            ->setMail('ltm-'.bin2hex(random_bytes(6)).'@example.com')
            ->setType('WEB')
            ->setProviderId('ltm'.bin2hex(random_bytes(8)))
            ->setUserLevel('NEW')
            ->setMemoriesEnabled($memoriesEnabled);
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();
        $id = $user->getId();
        self::assertNotNull($id);
        $this->userIds[] = $id;

        return $user;
    }

    private function createChat(User $user, string $title): int
    {
        $chat = (new Chat())->setUserId((int) $user->getId())->setTitle($title);
        $this->em->persist($chat);
        $this->em->flush();
        $id = $chat->getId();
        self::assertNotNull($id);

        return $id;
    }

    private function insertDigest(
        int $id,
        int $userId,
        int $messageId,
        int $chatId,
        string $title,
        string $channel,
        int $sourceDate,
        bool $active,
        int $created,
    ): void {
        $stmt = $this->em->getConnection()->prepare(
            <<<'SQL'
                INSERT INTO BMESSAGEDIGESTS
                    (BID, BUSERID, BCHATID, BMESSAGEID, BTITLE, BCHANNEL, BSOURCEDATE, BACTIVE, BCREATED)
                VALUES
                    (:id, :userId, :chatId, :messageId, :title, :channel, :sourceDate, :active, :created)
                SQL,
        );
        $stmt->bindValue('id', $id);
        $stmt->bindValue('userId', $userId);
        $stmt->bindValue('chatId', $chatId);
        $stmt->bindValue('messageId', $messageId);
        $stmt->bindValue('title', $title);
        $stmt->bindValue('channel', $channel);
        $stmt->bindValue('sourceDate', $sourceDate);
        $stmt->bindValue('active', $active ? 1 : 0);
        $stmt->bindValue('created', $created);
        $stmt->executeStatement();
    }

    private function activeFlag(int $id): ?int
    {
        $stmt = $this->em->getConnection()->prepare('SELECT BACTIVE FROM BMESSAGEDIGESTS WHERE BID = :id');
        $stmt->bindValue('id', $id);
        $value = $stmt->executeQuery()->fetchOne();

        return false === $value || null === $value ? null : (int) $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        $raw = $this->client->getResponse()->getContent();
        self::assertIsString($raw);
        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
