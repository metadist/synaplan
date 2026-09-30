<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Chat;
use App\Entity\File;
use App\Entity\User;
use App\Message\SearchIndexMessage;
use App\Repository\SearchIndexRepository;
use App\Service\SmartSearch\Index\SearchIndexer;
use App\Tests\Trait\AuthenticatedTestTrait;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class SearchControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();
    }

    public function testRequiresAuthentication(): void
    {
        $this->postSearch(['q' => 'anything']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testRejectsEmptyAndOverlongQueries(): void
    {
        $this->authenticateClient($this->client, $this->createUser('search-validate@synaplan.internal'));

        $this->postSearch(['q' => '   ']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $this->postSearch(['q' => str_repeat('a', 201)]);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testFindsOwnChatButNotAnotherUsersChat(): void
    {
        $owner = $this->createUser('search-owner@synaplan.internal');
        $stranger = $this->createUser('search-stranger@synaplan.internal');
        $chat = $this->createChat((int) $owner->getId(), 'Q3 plan');
        $indexer = static::getContainer()->get(SearchIndexer::class);
        $indexer->reindexUser((int) $owner->getId());
        $indexer->reindexUser((int) $stranger->getId());

        $this->authenticateClient($this->client, $owner);
        $body = $this->postSearch(['q' => 'Q3']);
        self::assertResponseIsSuccessful();
        self::assertFalse($body['semanticAvailable']);
        self::assertSame('chat:'.$chat->getId(), $body['results'][0]['id']);
        self::assertSame('/?chat='.$chat->getId(), $body['results'][0]['route']);
        self::assertSame('lexical', $body['results'][0]['matchedBy']);

        $this->authenticateClient($this->client, $stranger);
        $body = $this->postSearch(['q' => 'Q3']);
        self::assertSame([], array_values(array_filter(
            $body['results'],
            static fn (array $hit): bool => 'chat' === $hit['kind'],
        )));
    }

    public function testDeletedItemIsNotReturnedFromAStaleRow(): void
    {
        $owner = $this->createUser('search-stale@synaplan.internal');
        $chat = $this->createChat((int) $owner->getId(), 'Q4 offsite');
        static::getContainer()->get(SearchIndexer::class)->reindexUser((int) $owner->getId());

        $this->em->remove($chat);
        $this->em->flush();

        $this->authenticateClient($this->client, $owner);
        $body = $this->postSearch(['q' => 'Q4']);
        self::assertSame([], $body['results']);
    }

    public function testSettingsAreOnlyOfferedToAdmins(): void
    {
        $this->authenticateClient($this->client, $this->createUser('search-member@synaplan.internal'));
        $body = $this->postSearch(['q' => 'FEATURE_IAM_GROUPS_ENABLED']);
        self::assertSame([], $body['results']);

        $this->authenticateClient($this->client, $this->createUser('search-admin@synaplan.internal', 'ADMIN'));
        $body = $this->postSearch(['q' => 'FEATURE_IAM_GROUPS_ENABLED']);
        self::assertSame('setting:FEATURE_IAM_GROUPS_ENABLED', $body['results'][0]['id']);
        self::assertStringContainsString('highlight=FEATURE_IAM_GROUPS_ENABLED', $body['results'][0]['route']);
    }

    public function testKindsNarrowTheResults(): void
    {
        $this->authenticateClient($this->client, $this->createUser('search-kinds@synaplan.internal', 'ADMIN'));

        $body = $this->postSearch(['q' => 'FEATURE_IAM_GROUPS_ENABLED', 'kinds' => ['file']]);

        self::assertSame([], $body['results']);
    }

    public function testChatChangesQueueAnIndexRefresh(): void
    {
        $owner = $this->createUser('search-listener@synaplan.internal');
        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async_index');
        $transport->reset();

        $chat = $this->createChat((int) $owner->getId(), 'Budget review');
        $chat->setTitle('Budget review 2027');
        $this->em->flush();

        $refreshes = array_values(array_filter(
            array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent()),
            static fn (object $message): bool => $message instanceof SearchIndexMessage && 'chat' === $message->kind,
        ));
        self::assertCount(2, $refreshes);
        self::assertSame((string) $chat->getId(), $refreshes[0]->refId);
        self::assertSame((int) $owner->getId(), $refreshes[0]->userId);
    }

    /**
     * InnoDB applies FULLTEXT changes at commit, so this test commits for real
     * and cleans up after itself.
     */
    #[SkipDatabaseRollback]
    public function testFullTextFindsAFileByItsContent(): void
    {
        $owner = $this->createUser('search-fulltext-'.uniqid().'@synaplan.internal');
        $userId = (int) $owner->getId();
        $file = (new File())
            ->setUserId($userId)
            ->setFileName('forecast.txt')
            ->setFileType('txt')
            ->setFileMime('text/plain')
            ->setFileText('The quarterly revenue forecast for the Lisbon office grew again.');
        $this->em->persist($file);
        $this->em->flush();

        try {
            static::getContainer()->get(SearchIndexer::class)->reindexUser($userId);

            $this->authenticateClient($this->client, $owner);
            $body = $this->postSearch(['q' => 'lisbon revenue']);

            self::assertResponseIsSuccessful();
            self::assertSame('file:'.$file->getId(), $body['results'][0]['id']);
            self::assertSame('forecast.txt', $body['results'][0]['title']);
            self::assertStringContainsString('Lisbon', (string) $body['results'][0]['snippet']);
        } finally {
            static::getContainer()->get(SearchIndexRepository::class)->deleteMissing($userId, 'file', []);
            $this->em->remove($this->em->find(File::class, $file->getId()));
            $this->em->remove($this->em->find(User::class, $userId));
            $this->em->flush();
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function postSearch(array $payload): array
    {
        $this->client->request(
            'POST',
            '/api/v1/search',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode($payload),
        );

        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function createUser(string $email, string $level = 'NEW'): User
    {
        $user = (new User())
            ->setMail($email)
            ->setType('WEB')
            ->setProviderId('search-test-'.uniqid())
            ->setUserLevel($level);
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
}
