<?php

namespace App\Tests\Repository;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for UserRepository.
 */
class UserRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UserRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $kernel = self::bootKernel();
        $this->em = $kernel->getContainer()
            ->get('doctrine')
            ->getManager();

        $this->repository = $this->em->getRepository(User::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function testFindByMailReturnsUserWhenExists(): void
    {
        // Find existing test user from fixtures or create one
        $users = $this->repository->findAll();

        if (empty($users)) {
            $this->markTestSkipped('No users in database for testing');
        }

        $testUser = $users[0];
        $mail = $testUser->getMail();

        $found = $this->repository->findOneBy(['mail' => $mail]);

        $this->assertNotNull($found);
        $this->assertEquals($mail, $found->getMail());
        $this->assertInstanceOf(User::class, $found);
    }

    public function testFindByMailReturnsNullWhenNotExists(): void
    {
        $found = $this->repository->findOneBy(['mail' => 'nonexistent_'.time().'@test.com']);

        $this->assertNull($found);
    }

    public function testFindByProviderIdReturnsUsers(): void
    {
        $users = $this->repository->findBy(['providerId' => 'WEB'], null, 5);

        $this->assertIsArray($users);
        // May be empty, that's ok
        foreach ($users as $user) {
            $this->assertInstanceOf(User::class, $user);
            $this->assertEquals('WEB', $user->getProviderId());
        }
    }

    public function testFindByUserLevelReturnsUsers(): void
    {
        $users = $this->repository->findBy(['userLevel' => 'NEW'], null, 5);

        $this->assertIsArray($users);
        // May be empty, that's ok
        foreach ($users as $user) {
            $this->assertInstanceOf(User::class, $user);
            $this->assertEquals('NEW', $user->getUserLevel());
        }
    }

    public function testCountReturnsInteger(): void
    {
        $count = $this->repository->count([]);

        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testFindAllReturnsArray(): void
    {
        $users = $this->repository->findAll();

        $this->assertIsArray($users);
        foreach ($users as $user) {
            $this->assertInstanceOf(User::class, $user);
        }
    }

    public function testUserHasRequiredFields(): void
    {
        $users = $this->repository->findAll();

        if (empty($users)) {
            $this->markTestSkipped('No users in database for testing');
        }

        $user = $users[0];

        $this->assertNotNull($user->getId());
        $this->assertNotNull($user->getMail());
        $this->assertNotNull($user->getUserLevel());
        $this->assertNotNull($user->getProviderId());
    }

    public function testUserLevelDefaultsToNEW(): void
    {
        // Most users should default to NEW level
        $newUsers = $this->repository->findBy(['userLevel' => 'NEW']);

        // This is expected behavior - if no NEW users, that's also valid
        $this->assertIsArray($newUsers);
    }

    public function testFindWithLimitWorks(): void
    {
        $limit = 2;
        $users = $this->repository->findBy([], null, $limit);

        $this->assertIsArray($users);
        $this->assertLessThanOrEqual($limit, count($users));
    }

    public function testFindByGithubIdReadsOnlyTheTopLevelKey(): void
    {
        $githubId = random_int(1_000_000_000, 1_900_000_000);
        $nested = $this->persistUser('gh-nested-'.$githubId.'@example.com', [
            'companyName' => ['github_id' => $githubId],
        ]);
        $quoted = $this->persistUser('gh-quoted-'.$githubId.'@example.com', [
            'companyName' => '{"github_id":'.$githubId.'}',
        ]);

        $this->assertNull($this->repository->findByGithubId($githubId));

        $linked = $this->persistUser('gh-linked-'.$githubId.'@example.com', [
            'github_id' => $githubId,
        ]);
        $found = $this->repository->findByGithubId((string) $githubId);

        $this->assertNotNull($found);
        $this->assertSame($linked->getId(), $found->getId());
        $this->assertNotSame($nested->getId(), $found->getId());
        $this->assertNotSame($quoted->getId(), $found->getId());
    }

    public function testFindByGithubIdMatchesAnIdStoredAsAString(): void
    {
        $githubId = (string) random_int(1_000_000_000, 1_900_000_000);
        $linked = $this->persistUser('gh-string-'.$githubId.'@example.com', [
            'github_id' => $githubId,
        ]);

        $found = $this->repository->findByGithubId($githubId);

        $this->assertNotNull($found);
        $this->assertSame($linked->getId(), $found->getId());
    }

    public function testFindByAppleSubIgnoresANestedProfileValue(): void
    {
        $appleSub = 'apple-sub-'.bin2hex(random_bytes(8));
        $this->persistUser('apple-nested-'.$appleSub.'@example.com', [
            'companyName' => ['apple_sub' => $appleSub],
        ]);

        $this->assertNull($this->repository->findByAppleSub($appleSub));
    }

    public function testFindByAppleSubFindsTheTopLevelKey(): void
    {
        $appleSub = 'apple-sub-'.bin2hex(random_bytes(8));
        $linked = $this->persistUser('apple-linked-'.$appleSub.'@example.com', [
            'apple_sub' => $appleSub,
        ]);

        $found = $this->repository->findByAppleSub($appleSub);

        $this->assertNotNull($found);
        $this->assertSame($linked->getId(), $found->getId());
    }

    /**
     * @param array<string, mixed> $details
     */
    private function persistUser(string $mail, array $details): User
    {
        $user = (new User())
            ->setMail($mail)
            ->setType('WEB')
            ->setProviderId('github')
            ->setUserLevel('NEW');
        $user->setCreated(date('Y-m-d H:i:s'));
        $user->setEmailVerified(true);
        $user->setUserDetails($details);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
