<?php

namespace App\Tests\Integration;

use App\Entity\File;
use App\Entity\User;
use App\Repository\FileRepository;
use App\Service\StorageQuotaService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration Test for StorageQuotaService.
 *
 * Tests the service with real database interactions
 */
class StorageQuotaServiceIntegrationTest extends KernelTestCase
{
    private StorageQuotaService $service;
    private FileRepository $fileRepository;
    private User $testUser;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();
        $this->service = $container->get(StorageQuotaService::class);
        $this->fileRepository = $container->get(FileRepository::class);

        // Create a test user (PRO level for 5GB limit)
        $em = $container->get('doctrine')->getManager();
        $this->testUser = new User();
        $this->testUser->setMail('test-storage@example.com');
        $this->testUser->setProviderId('test-provider-'.time());
        $this->testUser->setPw('dummy_hash');
        $this->testUser->setUserLevel('PRO');
        $this->testUser->setEmailVerified(true);
        $this->testUser->setCreated(date('Y-m-d H:i:s'));

        $em->persist($this->testUser);
        $em->flush();
    }

    protected function tearDown(): void
    {
        // Clean up test data
        $em = static::getContainer()->get('doctrine')->getManager();

        // Remove all files for test user
        $files = $this->fileRepository->findBy(['userId' => $this->testUser->getId()]);
        foreach ($files as $file) {
            $em->remove($file);
        }

        // Remove test user
        $em->remove($this->testUser);
        $em->flush();

        parent::tearDown();
    }

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
        $em = static::getContainer()->get('doctrine')->getManager();

        // Create test files
        $file1 = new File();
        $file1->setUserId($this->testUser->getId());
        $file1->setFileName('test1.pdf');
        $file1->setFilePath('/uploads/test1.pdf');
        $file1->setFileType('application/pdf');
        $file1->setFileSize(1024 * 1024); // 1 MB
        $file1->setFileMime('application/pdf');
        $file1->setStatus('uploaded');

        $file2 = new File();
        $file2->setUserId($this->testUser->getId());
        $file2->setFileName('test2.pdf');
        $file2->setFilePath('/uploads/test2.pdf');
        $file2->setFileType('application/pdf');
        $file2->setFileSize(2 * 1024 * 1024); // 2 MB
        $file2->setFileMime('application/pdf');
        $file2->setStatus('uploaded');

        $em->persist($file1);
        $em->persist($file2);
        $em->flush();

        $usage = $this->service->getStorageUsage($this->testUser);

        // Should be 3 MB
        $this->assertEquals(3 * 1024 * 1024, $usage);
    }

    public function testGetRemainingStorage(): void
    {
        $em = static::getContainer()->get('doctrine')->getManager();

        // Create a 1 GB file
        $file = new File();
        $file->setUserId($this->testUser->getId());
        $file->setFileName('large.pdf');
        $file->setFilePath('/uploads/large.pdf');
        $file->setFileType('application/pdf');
        $file->setFileSize(1024 * 1024 * 1024); // 1 GB
        $file->setFileMime('application/pdf');
        $file->setStatus('uploaded');

        $em->persist($file);
        $em->flush();

        $remaining = $this->service->getRemainingStorage($this->testUser);

        // Should be 4 GB remaining (5 GB - 1 GB)
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
        $em = static::getContainer()->get('doctrine')->getManager();

        // Create a 250 MB file (instead of 2.5 GB to avoid INT overflow)
        $file = new File();
        $file->setUserId($this->testUser->getId());
        $file->setFileName('large.pdf');
        $file->setFilePath('/uploads/large.pdf');
        $file->setFileType('application/pdf');
        $file->setFileSize(250 * 1024 * 1024); // 250 MB
        $file->setFileMime('application/pdf');
        $file->setStatus('uploaded');

        $em->persist($file);
        $em->flush();

        $stats = $this->service->getStorageStats($this->testUser);

        $this->assertEquals(5 * 1024 * 1024 * 1024, $stats['limit']); // 5 GB limit
        $this->assertEquals(250 * 1024 * 1024, $stats['usage']); // 250 MB usage
        $this->assertEquals(5 * 1024 * 1024 * 1024 - 250 * 1024 * 1024, $stats['remaining']);
        $this->assertEquals(4.88, $stats['percentage']); // 250 MB of 5 GB = ~4.88%
        $this->assertEquals('5 GB', $stats['limit_formatted']);
        $this->assertEquals('250 MB', $stats['usage_formatted']);
    }

    public function testStorageLimitForDifferentUserLevels(): void
    {
        $em = static::getContainer()->get('doctrine')->getManager();

        // Test BUSINESS user (100 GB)
        $businessUser = new User();
        $businessUser->setMail('business@example.com');
        $businessUser->setProviderId('business-provider-'.time());
        $businessUser->setPw('dummy_hash');
        $businessUser->setUserLevel('BUSINESS');
        $businessUser->setEmailVerified(true);
        $businessUser->setCreated(date('Y-m-d H:i:s'));
        $em->persist($businessUser);
        $em->flush();

        $businessLimit = $this->service->getStorageLimit($businessUser);
        $this->assertEquals(100 * 1024 * 1024 * 1024, $businessLimit);

        // Test NEW user (100 MB)
        $newUser = new User();
        $newUser->setMail('new@example.com');
        $newUser->setProviderId('new-provider-'.time());
        $newUser->setPw('dummy_hash');
        $newUser->setUserLevel('NEW');
        $newUser->setEmailVerified(true);
        $newUser->setCreated(date('Y-m-d H:i:s'));
        $em->persist($newUser);
        $em->flush();

        $newLimit = $this->service->getStorageLimit($newUser);
        $this->assertEquals(100 * 1024 * 1024, $newLimit);

        // Clean up
        $em->remove($businessUser);
        $em->remove($newUser);
        $em->flush();
    }
}
