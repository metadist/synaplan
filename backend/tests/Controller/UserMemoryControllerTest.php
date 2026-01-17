<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Entity\UserMemory;
use App\Repository\UserMemoryRepository;
use App\Tests\Trait\AuthenticatedTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration tests for UserMemoryController.
 * Tests all memory management endpoints with authentication.
 */
class UserMemoryControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private $client;
    private $em;
    private $user;
    private $token;
    private UserMemoryRepository $memoryRepository;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get('doctrine')->getManager();
        $this->memoryRepository = $this->em->getRepository(UserMemory::class);

        // Create test user
        $this->user = new User();
        $this->user->setMail('memorytest@example.com');
        $this->user->setPw(password_hash('testpass', PASSWORD_BCRYPT));
        $this->user->setUserLevel('PRO');
        $this->user->setProviderId('test-provider');
        $this->user->setCreated(date('YmdHis'));

        $this->em->persist($this->user);
        $this->em->flush();

        // Generate access token
        $this->token = $this->authenticateClient($this->client, $this->user);
    }

    protected function tearDown(): void
    {
        if ($this->user) {
            // Get a fresh entity manager if the current one is closed
            if (!$this->em || !$this->em->isOpen()) {
                self::bootKernel();
                $this->em = self::getContainer()->get('doctrine')->getManager();
            }

            // Cleanup: Remove test memories
            $memories = $this->memoryRepository->findBy(['userId' => $this->user->getId()]);
            foreach ($memories as $memory) {
                $this->em->remove($memory);
            }

            // Remove test user
            $user = $this->em->find(User::class, $this->user->getId());
            if ($user) {
                $this->em->remove($user);
            }
            $this->em->flush();
        }

        static::ensureKernelShutdown();
        parent::tearDown();
    }

    public function testGetMemoriesWithoutAuth(): void
    {
        self::ensureKernelShutdown();
        $unauthClient = static::createClient();

        $unauthClient->request('GET', '/api/v1/user/memories');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testGetMemoriesEmpty(): void
    {
        $this->client->request('GET', '/api/v1/user/memories');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('memories', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertIsArray($data['memories']);
        $this->assertSame(0, $data['total']);
    }

    public function testCreateMemory(): void
    {
        $payload = [
            'category' => 'preferences',
            'key' => 'tech_stack',
            'value' => 'TypeScript with Vue 3',
        ];

        $this->client->request(
            'POST',
            '/api/v1/user/memories',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload)
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('memory', $data);
        $this->assertSame('preferences', $data['memory']['category']);
        $this->assertSame('tech_stack', $data['memory']['key']);
        $this->assertSame('TypeScript with Vue 3', $data['memory']['value']);
        $this->assertSame('user_created', $data['memory']['source']);
    }

    public function testCreateMemoryWithMissingFields(): void
    {
        $payload = [
            'category' => 'preferences',
            // Missing 'key' and 'value'
        ];

        $this->client->request(
            'POST',
            '/api/v1/user/memories',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload)
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testGetMemoriesWithCategory(): void
    {
        // Create test memories in different categories
        $this->createTestMemory('preferences', 'tech', 'TypeScript');
        $this->createTestMemory('work', 'role', 'Developer');
        $this->createTestMemory('preferences', 'editor', 'VS Code');

        $this->client->request('GET', '/api/v1/user/memories?category=preferences');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertSame(2, $data['total']);
        foreach ($data['memories'] as $memory) {
            $this->assertSame('preferences', $memory['category']);
        }
    }

    public function testGetCategories(): void
    {
        // Create memories in different categories
        $this->createTestMemory('preferences', 'tech', 'TypeScript');
        $this->createTestMemory('preferences', 'editor', 'VS Code');
        $this->createTestMemory('work', 'role', 'Developer');

        $this->client->request('GET', '/api/v1/user/memories/categories');

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('categories', $data);
        $this->assertIsArray($data['categories']);
        $this->assertGreaterThan(0, count($data['categories']));

        // Check structure
        foreach ($data['categories'] as $cat) {
            $this->assertArrayHasKey('category', $cat);
            $this->assertArrayHasKey('count', $cat);
        }
    }

    public function testUpdateMemory(): void
    {
        $memory = $this->createTestMemory('preferences', 'tech', 'React');

        $payload = [
            'value' => 'Vue 3 with TypeScript',
        ];

        $this->client->request(
            'PUT',
            '/api/v1/user/memories/'.$memory->getId(),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload)
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('memory', $data);
        $this->assertSame('Vue 3 with TypeScript', $data['memory']['value']);
        $this->assertSame('user_edited', $data['memory']['source']);
    }

    public function testUpdateNonExistentMemory(): void
    {
        $payload = ['value' => 'New value'];

        $this->client->request(
            'PUT',
            '/api/v1/user/memories/999999',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload)
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testDeleteMemory(): void
    {
        $memory = $this->createTestMemory('preferences', 'tech', 'TypeScript');
        $memoryId = $memory->getId();

        $this->client->request('DELETE', '/api/v1/user/memories/'.$memoryId);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('success', $data);
        $this->assertTrue($data['success']);

        // Verify memory is soft-deleted
        $this->em->clear();
        $deletedMemory = $this->memoryRepository->find($memoryId);
        $this->assertTrue($deletedMemory->isDeleted());
    }

    public function testSearchMemories(): void
    {
        // Create test memories
        $this->createTestMemory('preferences', 'programming', 'TypeScript and Vue.js');
        $this->createTestMemory('work', 'tools', 'VS Code editor');

        $payload = [
            'query' => 'TypeScript',
            'limit' => 5,
        ];

        $this->client->request(
            'POST',
            '/api/v1/user/memories/search',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload)
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('memories', $data);
        $this->assertIsArray($data['memories']);
    }

    public function testSearchMemoriesWithoutQuery(): void
    {
        $payload = ['limit' => 5];

        $this->client->request(
            'POST',
            '/api/v1/user/memories/search',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload)
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testUserCannotAccessOtherUsersMemories(): void
    {
        // Create another user
        $otherUser = new User();
        $otherUser->setMail('other@example.com');
        $otherUser->setPw(password_hash('pass', PASSWORD_BCRYPT));
        $otherUser->setUserLevel('FREE');
        $otherUser->setProviderId('other-provider');
        $otherUser->setCreated(date('YmdHis'));
        $this->em->persist($otherUser);
        $this->em->flush();

        // Create memory for other user
        $memory = new UserMemory();
        $memory->setUser($otherUser);
        $memory->setCategory('preferences');
        $memory->setKey('secret');
        $memory->setValue('confidential data');
        $memory->setSource('user_created');
        $this->em->persist($memory);
        $this->em->flush();

        $memoryId = $memory->getId();

        // Try to access with current user
        $this->client->request('GET', '/api/v1/user/memories/'.$memoryId);
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        // Try to update
        $this->client->request(
            'PUT',
            '/api/v1/user/memories/'.$memoryId,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['value' => 'hacked'])
        );
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        // Try to delete
        $this->client->request('DELETE', '/api/v1/user/memories/'.$memoryId);
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        // Cleanup
        $this->em->remove($memory);
        $this->em->remove($otherUser);
        $this->em->flush();
    }

    private function createTestMemory(string $category, string $key, string $value): UserMemory
    {
        $memory = new UserMemory();
        $memory->setUser($this->user);
        $memory->setCategory($category);
        $memory->setKey($key);
        $memory->setValue($value);
        $memory->setSource('user_created');

        $this->em->persist($memory);
        $this->em->flush();

        return $memory;
    }
}
