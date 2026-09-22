<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Entity\UserMemory;
use App\Repository\UserMemoryRepository;
use App\Service\VectorSearch\QdrantClientInterface;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * #2075 — DELETE /api/v1/feedback/{id} must answer 200 when the row existed
 * and 404 only when it did not. CI has no Qdrant, so the index client is a
 * stub that reports itself available; the SQL row is the assertion.
 */
final class FeedbackDeleteTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        // The browser reboots the kernel after each request and drops the
        // stub. CI has no Qdrant, so the second DELETE would then be 503
        // instead of 404.
        $this->client->disableReboot();
        $this->em = static::getContainer()->get('doctrine')->getManager();

        $qdrant = $this->createMock(QdrantClientInterface::class);
        $qdrant->expects($this->atLeastOnce())->method('isAvailable')->willReturn(true);
        static::getContainer()->set(QdrantClientInterface::class, $qdrant);
    }

    public function testDeleteExistingReturns200AndASecondDeleteReturns404(): void
    {
        $user = $this->createUser('feedback-delete-404@synaplan.internal');
        $memoryId = $this->createFeedbackRow((int) $user->getId());
        $this->authenticateClient($this->client, $user);

        $this->client->request('DELETE', '/api/v1/feedback/'.$memoryId);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $this->em->clear();
        self::assertNull($this->memories()->find($memoryId));

        $this->client->request('DELETE', '/api/v1/feedback/'.$memoryId);

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
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
            ->setProviderId('feedback-delete-'.uniqid())
            ->setUserLevel('NEW');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createFeedbackRow(int $userId): int
    {
        $memory = new UserMemory(
            id: random_int(1_000_000_000_000, 9_000_000_000_000),
            userId: $userId,
            category: 'feedback_positive',
            key: 'positive_example',
            value: 'The capital of Australia is Canberra.',
            source: UserMemory::SOURCE_USER_CREATED,
            namespace: 'feedback_positive',
        );
        $this->em->persist($memory);
        $this->em->flush();

        return $memory->getId();
    }

    private function memories(): UserMemoryRepository
    {
        $repository = $this->em->getRepository(UserMemory::class);
        self::assertInstanceOf(UserMemoryRepository::class, $repository);

        return $repository;
    }
}
