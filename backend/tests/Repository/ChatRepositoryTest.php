<?php

namespace App\Tests\Repository;

use App\Entity\Chat;
use App\Entity\User;
use App\Repository\ChatRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for ChatRepository.
 */
class ChatRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ChatRepository $repository;
    private ?User $testUser = null;

    protected function setUp(): void
    {
        parent::setUp();

        $kernel = self::bootKernel();
        $this->em = $kernel->getContainer()
            ->get('doctrine')
            ->getManager();

        $this->repository = $this->em->getRepository(Chat::class);

        // Get or create test user
        $userRepo = $this->em->getRepository(User::class);
        $this->testUser = $userRepo->findOneBy([]) ?? $this->createTestUser();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    private function createTestUser(): User
    {
        $user = new User();
        $user->setMail('chatrepo_test_'.time().'@test.com');
        $user->setPw('test123');
        $user->setProviderId('TEST');
        $user->setUserLevel('NEW');

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testFindByUserIdReturnsChats(): void
    {
        if (!$this->testUser) {
            $this->markTestSkipped('No test user available');
        }

        $chats = $this->repository->findBy(['userId' => $this->testUser->getId()]);

        $this->assertIsArray($chats);
        foreach ($chats as $chat) {
            $this->assertInstanceOf(Chat::class, $chat);
            $this->assertEquals($this->testUser->getId(), $chat->getUserId());
        }
    }

    public function testFindByTitleReturnsChat(): void
    {
        if (!$this->testUser) {
            $this->markTestSkipped('No test user available');
        }

        // Create a test chat
        $chat = new Chat();
        $chat->setUserId($this->testUser->getId());
        $chat->setTitle('Test Chat '.time());

        $this->em->persist($chat);
        $this->em->flush();

        // Find it
        $found = $this->repository->findOneBy([
            'userId' => $this->testUser->getId(),
            'title' => $chat->getTitle(),
        ]);

        $this->assertNotNull($found);
        $this->assertEquals($chat->getTitle(), $found->getTitle());
        $this->assertEquals($this->testUser->getId(), $found->getUserId());

        // Cleanup
        $this->em->remove($chat);
        $this->em->flush();
    }

    public function testCountReturnsInteger(): void
    {
        $count = $this->repository->count([]);

        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testFindAllReturnsArray(): void
    {
        $chats = $this->repository->findAll();

        $this->assertIsArray($chats);
        foreach ($chats as $chat) {
            $this->assertInstanceOf(Chat::class, $chat);
        }
    }

    public function testChatHasRequiredFields(): void
    {
        if (!$this->testUser) {
            $this->markTestSkipped('No test user available');
        }

        $chat = new Chat();
        $chat->setUserId($this->testUser->getId());
        $chat->setTitle('Required Fields Test');

        $this->em->persist($chat);
        $this->em->flush();

        $this->assertNotNull($chat->getId());
        $this->assertNotNull($chat->getUserId());
        $this->assertNotNull($chat->getTitle());

        // Cleanup
        $this->em->remove($chat);
        $this->em->flush();
    }

    public function testFindWithLimitWorks(): void
    {
        $limit = 3;
        $chats = $this->repository->findBy([], null, $limit);

        $this->assertIsArray($chats);
        $this->assertLessThanOrEqual($limit, count($chats));
    }

    public function testFindByUserIdAndOrderById(): void
    {
        if (!$this->testUser) {
            $this->markTestSkipped('No test user available');
        }

        // Use 'id' for ordering instead of non-existent 'created' field
        $chats = $this->repository->findBy(
            ['userId' => $this->testUser->getId()],
            ['id' => 'DESC'],
            5
        );

        $this->assertIsArray($chats);

        // Check ordering if multiple chats exist
        if (count($chats) > 1) {
            $prevId = PHP_INT_MAX;
            foreach ($chats as $chat) {
                $this->assertLessThanOrEqual($prevId, $chat->getId());
                $prevId = $chat->getId();
            }
        }
    }
}
