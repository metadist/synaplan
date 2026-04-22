<?php

namespace App\Tests\Integration;

use App\Entity\Config;
use App\Entity\File;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\FileRepository;
use App\Service\StorageQuotaService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration Test for StorageQuotaService.
 *
 * Tests the service with real database interactions.
 * The test is self-contained: it inserts the required rate-limit config rows
 * on boot (skipping rows that already exist) and removes only the ones it
 * created on teardown, so it works regardless of whether the seeder has run.
 */
class StorageQuotaServiceIntegrationTest extends KernelTestCase
{
    private StorageQuotaService $service;
    private FileRepository $fileRepository;
    private EntityManagerInterface $em;
    private User $testUser;

    /** @var Config[] config rows inserted by this test run that must be removed in tearDown */
    private array $insertedConfigs = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();
        $this->service = $container->get(StorageQuotaService::class);
        $this->fileRepository = $container->get(FileRepository::class);
        $this->em = $container->get('doctrine')->getManager();

        $this->seedRateLimitConfigs();

        // Create a test user (PRO level for 5 GB limit)
        $this->testUser = new User();
        $this->testUser->setMail('test-storage@example.com');
        $this->testUser->setProviderId('test-provider-'.time());
        $this->testUser->setPw('dummy_hash');
        $this->testUser->setUserLevel('PRO');
        $this->testUser->setEmailVerified(true);
        $this->testUser->setCreated(date('Y-m-d H:i:s'));

        $this->em->persist($this->testUser);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        // Remove all files for test user
        $files = $this->fileRepository->findBy(['userId' => $this->testUser->getId()]);
        foreach ($files as $file) {
            $this->em->remove($file);
        }

        // Remove test user
        $this->em->remove($this->testUser);

        // Remove only the config rows we inserted (leave seeder-provided rows intact)
        foreach ($this->insertedConfigs as $config) {
            $this->em->remove($config);
        }

        $this->em->flush();

        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Tests
    // -----------------------------------------------------------------------

    public function testGetStorageLimitForProUser(): void
    {
        $limit = $this->service->getStorageLimit($this->testUser);

        // PRO users should have 5 GB = 5 * 1024 * 1024 * 1024 bytes
        $expectedLimit = 5 * 1024 * 1024 * 1024;
        $this->assertEquals($expectedLimit, $limit);
    }

    public function testGetStorageUsageWithNoFiles(): void
    {
        $usage = $this->service->getStorageUsage($this->testUser);

        $this->assertEquals(0, $usage);
    }

    public function testGetStorageUsageWithFiles(): void
    {
        $file1 = $this->createFile('test1.pdf', 1024 * 1024); // 1 MB
        $file2 = $this->createFile('test2.pdf', 2 * 1024 * 1024); // 2 MB

        $this->em->persist($file1);
        $this->em->persist($file2);
        $this->em->flush();

        $usage = $this->service->getStorageUsage($this->testUser);

        $this->assertEquals(3 * 1024 * 1024, $usage);
    }

    public function testGetRemainingStorage(): void
    {
        $file = $this->createFile('large.pdf', 1024 * 1024 * 1024); // 1 GB
        $this->em->persist($file);
        $this->em->flush();

        $remaining = $this->service->getRemainingStorage($this->testUser);

        // PRO limit = 5 GB, used = 1 GB → 4 GB remaining
        $this->assertEquals(4 * 1024 * 1024 * 1024, $remaining);
    }

    public function testCheckStorageLimitAllowsUpload(): void
    {
        // Should not throw exception for 100 MB file (within 5 GB limit)
        $this->expectNotToPerformAssertions();

        $this->service->checkStorageLimit($this->testUser, 100 * 1024 * 1024);
    }

    public function testCheckStorageLimitThrowsExceptionWhenExceeded(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Storage limit exceeded');

        // Try to upload 6 GB file (exceeds 5 GB limit)
        $this->service->checkStorageLimit($this->testUser, 6 * 1024 * 1024 * 1024);
    }

    public function testGetStorageStats(): void
    {
        $file = $this->createFile('large.pdf', 250 * 1024 * 1024); // 250 MB
        $this->em->persist($file);
        $this->em->flush();

        $stats = $this->service->getStorageStats($this->testUser);

        $this->assertEquals(5 * 1024 * 1024 * 1024, $stats['limit']); // 5 GB limit
        $this->assertEquals(250 * 1024 * 1024, $stats['usage']); // 250 MB usage
        $this->assertEquals(5 * 1024 * 1024 * 1024 - 250 * 1024 * 1024, $stats['remaining']);
        $this->assertEquals(4.88, $stats['percentage']); // 250 MB of 5 GB ≈ 4.88 %
        $this->assertEquals('5 GB', $stats['limit_formatted']);
        $this->assertEquals('250 MB', $stats['usage_formatted']);
    }

    public function testStorageLimitForDifferentUserLevels(): void
    {
        // BUSINESS user (100 GB)
        $businessUser = $this->createUserWithLevel('BUSINESS', 'business@example.com');
        $businessLimit = $this->service->getStorageLimit($businessUser);
        $this->assertEquals(100 * 1024 * 1024 * 1024, $businessLimit);

        // NEW user (100 MB)
        $newUser = $this->createUserWithLevel('NEW', 'new@example.com');
        $newLimit = $this->service->getStorageLimit($newUser);
        $this->assertEquals(100 * 1024 * 1024, $newLimit);

        // Clean up
        $this->em->remove($businessUser);
        $this->em->remove($newUser);
        $this->em->flush();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function createFile(string $name, int $size): File
    {
        $file = new File();
        $file->setUserId($this->testUser->getId());
        $file->setFileName($name);
        $file->setFilePath('/uploads/'.$name);
        $file->setFileType('application/pdf');
        $file->setFileSize($size);
        $file->setFileMime('application/pdf');
        $file->setStatus('uploaded');

        return $file;
    }

    private function createUserWithLevel(string $level, string $mail): User
    {
        $user = new User();
        $user->setMail($mail);
        $user->setProviderId($level.'-provider-'.time());
        $user->setPw('dummy_hash');
        $user->setUserLevel($level);
        $user->setEmailVerified(true);
        $user->setCreated(date('Y-m-d H:i:s'));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * Ensure the rate-limit config rows exist.
     * Rows already present (e.g. from the seeder) are left untouched.
     * Rows inserted here are tracked in $insertedConfigs for cleanup.
     */
    private function seedRateLimitConfigs(): void
    {
        $configRepository = static::getContainer()->get(ConfigRepository::class);

        $defaults = [
            ['group' => 'RATELIMITS_PRO',      'setting' => 'STORAGE_GB', 'value' => '5'],
            ['group' => 'RATELIMITS_BUSINESS',  'setting' => 'STORAGE_GB', 'value' => '100'],
            ['group' => 'RATELIMITS_NEW',       'setting' => 'STORAGE_MB', 'value' => '100'],
            ['group' => 'RATELIMITS_TEAM',      'setting' => 'STORAGE_GB', 'value' => '20'],
        ];

        foreach ($defaults as $row) {
            $existing = $configRepository->findOneBy([
                'ownerId' => 0,
                'group'   => $row['group'],
                'setting' => $row['setting'],
            ]);

            if ($existing) {
                continue;
            }

            $config = new Config();
            $config->setOwnerId(0);
            $config->setGroup($row['group']);
            $config->setSetting($row['setting']);
            $config->setValue($row['value']);

            $this->em->persist($config);
            $this->insertedConfigs[] = $config;
        }

        $this->em->flush();
    }
}
