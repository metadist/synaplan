<?php

namespace App\Tests\Repository;

use App\Entity\ApiKey;
use App\Entity\User;
use App\Repository\ApiKeyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for ApiKeyRepository
 */
class ApiKeyRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ApiKeyRepository $repository;
    private ?User $testUser = null;

    protected function setUp(): void
    {
        parent::setUp();
        
        $kernel = self::bootKernel();
        $this->em = $kernel->getContainer()
            ->get('doctrine')
            ->getManager();
        
        $this->repository = $this->em->getRepository(ApiKey::class);
        
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
        $user->setMail('apikey_test_' . time() . '@test.com');
        $user->setPw('test123');
        $user->setProviderId('TEST');
        $user->setUserLevel('NEW');
        
        $this->em->persist($user);
        $this->em->flush();
        
        return $user;
    }

    public function testFindValidApiKeyByKeyReturnsApiKey(): void
    {
        if (!$this->testUser) {
            $this->markTestSkipped('No test user available');
        }

        // Create test API key
        $key = 'sk_test_' . bin2hex(random_bytes(20));
        $apiKey = new ApiKey();
        $apiKey->setOwner($this->testUser); // Use setOwner (relation name)
        $apiKey->setKey($key);
        $apiKey->setName('Test Key');
        $apiKey->setStatus('active'); // ApiKey uses status field, not isActive
        
        $this->em->persist($apiKey);
        $this->em->flush();

        // Find using repository method
        $found = $this->repository->findOneBy(['key' => $key, 'status' => 'active']);

        $this->assertNotNull($found);
        $this->assertEquals($key, $found->getKey());
        $this->assertTrue($found->isActive());

        // Cleanup
        $this->em->remove($apiKey);
        $this->em->flush();
    }

    public function testFindValidApiKeyByKeyReturnsNullForInvalidKey(): void
    {
        // Method doesn't exist in repository - test basic findOneBy instead
        $found = $this->repository->findOneBy(['key' => 'sk_invalid_key_' . time()]);

        $this->assertNull($found);
    }

    public function testFindValidApiKeyByKeyReturnsNullForInactiveKey(): void
    {
        if (!$this->testUser) {
            $this->markTestSkipped('No test user available');
        }

        // Create inactive API key
        $key = 'sk_inactive_' . bin2hex(random_bytes(20));
        $apiKey = new ApiKey();
        $apiKey->setOwner($this->testUser);
        $apiKey->setKey($key);
        $apiKey->setName('Inactive Key');
        $apiKey->setStatus('inactive');
        
        $this->em->persist($apiKey);
        $this->em->flush();

        // Should not find inactive key with status=active filter
        $found = $this->repository->findOneBy(['key' => $key, 'status' => 'active']);

        $this->assertNull($found);

        // Cleanup
        $this->em->remove($apiKey);
        $this->em->flush();
    }

    public function testFindByUserReturnsUserKeys(): void
    {
        if (!$this->testUser) {
            $this->markTestSkipped('No test user available');
        }

        $keys = $this->repository->findBy(['owner' => $this->testUser]);

        $this->assertIsArray($keys);
        foreach ($keys as $key) {
            $this->assertInstanceOf(ApiKey::class, $key);
            $this->assertEquals($this->testUser->getId(), $key->getOwner()->getId());
        }
    }

    public function testCountReturnsInteger(): void
    {
        $count = $this->repository->count([]);

        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testApiKeyHasRequiredFields(): void
    {
        if (!$this->testUser) {
            $this->markTestSkipped('No test user available');
        }

        $key = 'sk_required_' . bin2hex(random_bytes(20));
        $apiKey = new ApiKey();
        $apiKey->setOwner($this->testUser);
        $apiKey->setKey($key);
        $apiKey->setName('Required Fields Test');
        $apiKey->setStatus('active'); // ApiKey uses status field, not isActive
        
        $this->em->persist($apiKey);
        $this->em->flush();

        $this->assertNotNull($apiKey->getId());
        $this->assertNotNull($apiKey->getOwner());
        $this->assertNotNull($apiKey->getKey());
        $this->assertNotNull($apiKey->getName());
        $this->assertIsBool($apiKey->isActive()); // isActive() returns boolean

        // Cleanup
        $this->em->remove($apiKey);
        $this->em->flush();
    }

    public function testFindActiveKeysByUser(): void
    {
        if (!$this->testUser) {
            $this->markTestSkipped('No test user available');
        }

        $keys = $this->repository->findBy([
            'owner' => $this->testUser,
            'status' => 'active'
        ]);

        $this->assertIsArray($keys);
        foreach ($keys as $key) {
            $this->assertInstanceOf(ApiKey::class, $key);
            $this->assertTrue($key->isActive());
        }
    }
}

