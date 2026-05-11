<?php

declare(strict_types=1);

namespace App\Tests\Service\File;

use App\Entity\User;
use App\Service\File\FileProcessor;
use App\Service\File\FileStorageService;
use App\Service\File\FileUploadService;
use App\Service\File\VectorizationService;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use App\Service\RateLimitService;
use App\Service\StorageQuotaService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for FileUploadService::checkUpload() — the pre-flight quota check
 * introduced to fix issue #213 (uploads near storage limit timing out).
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
class FileUploadServiceCheckUploadTest extends TestCase
{
    private FileUploadService $service;
    private StorageQuotaService&MockObject $storageQuotaService;
    private RateLimitService&MockObject $rateLimitService;

    protected function setUp(): void
    {
        $this->storageQuotaService = $this->createMock(StorageQuotaService::class);
        $this->rateLimitService = $this->createMock(RateLimitService::class);

        $this->service = new FileUploadService(
            $this->createStub(FileStorageService::class),
            $this->createStub(FileProcessor::class),
            $this->createStub(VectorizationService::class),
            $this->createStub(VectorStorageFacade::class),
            $this->storageQuotaService,
            $this->rateLimitService,
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(LoggerInterface::class),
            '/tmp/uploads',
        );
    }

    private function createUser(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(1);
        $user->method('getRateLimitLevel')->willReturn('PRO');

        return $user;
    }

    private function expectRateLimitAllowed(): void
    {
        $this->rateLimitService
            ->method('checkLimit')
            ->willReturn(['allowed' => true, 'used' => 0, 'limit' => 100]);
    }

    public function testAllowsUploadWhenWithinQuota(): void
    {
        $this->expectRateLimitAllowed();
        $this->storageQuotaService->method('getRemainingStorage')->willReturn(10 * 1024 * 1024); // 10MB

        $result = $this->service->checkUpload($this->createUser(), 'document.pdf', 1024 * 1024); // 1MB

        $this->assertTrue($result['allowed']);
        $this->assertArrayNotHasKey('reason', $result);
        $this->assertSame(FileStorageService::getMaxFileSize(), $result['max_file_size']);
        $this->assertContains('pdf', $result['allowed_extensions']);
    }

    public function testBlocksUploadWhenStorageExceeded(): void
    {
        $this->expectRateLimitAllowed();
        $this->storageQuotaService->method('getRemainingStorage')->willReturn(500 * 1024); // 500KB remaining

        $result = $this->service->checkUpload($this->createUser(), 'document.pdf', 2 * 1024 * 1024); // 2MB

        $this->assertFalse($result['allowed']);
        $this->assertSame('storage_exceeded', $result['reason']);
        $this->assertNotEmpty($result['message']);
        $this->assertStringContainsString('Storage limit exceeded', $result['message']);
        $this->assertSame(500 * 1024, $result['remaining']);
    }

    public function testBlocksUploadWhenFileTooLarge(): void
    {
        $this->expectRateLimitAllowed();
        $this->storageQuotaService->method('getRemainingStorage')->willReturn(10 * 1024 * 1024 * 1024); // 10GB

        $oversize = FileStorageService::getMaxFileSize() + 1;
        $result = $this->service->checkUpload($this->createUser(), 'big.pdf', $oversize);

        $this->assertFalse($result['allowed']);
        $this->assertSame('file_too_large', $result['reason']);
    }

    public function testBlocksUploadWhenExtensionNotAllowed(): void
    {
        $this->expectRateLimitAllowed();
        $this->storageQuotaService->method('getRemainingStorage')->willReturn(10 * 1024 * 1024);

        $result = $this->service->checkUpload($this->createUser(), 'malicious.exe', 1024);

        $this->assertFalse($result['allowed']);
        $this->assertSame('extension_not_allowed', $result['reason']);
    }

    public function testBlocksUploadWhenRateLimitExceeded(): void
    {
        $this->rateLimitService
            ->method('checkLimit')
            ->willReturn(['allowed' => false, 'used' => 100, 'limit' => 100]);
        // Quota service should not gate when rate limit comes first; still need a remaining value
        $this->storageQuotaService->method('getRemainingStorage')->willReturn(1024 * 1024);

        $result = $this->service->checkUpload($this->createUser(), 'document.pdf', 1024);

        $this->assertFalse($result['allowed']);
        $this->assertSame('rate_limit_exceeded', $result['reason']);
        $this->assertSame(100, $result['used']);
        $this->assertSame(100, $result['limit']);
    }

    public function testRejectsZeroByteFiles(): void
    {
        $this->expectRateLimitAllowed();
        $this->storageQuotaService->method('getRemainingStorage')->willReturn(10 * 1024 * 1024);

        $result = $this->service->checkUpload($this->createUser(), 'empty.pdf', 0);

        $this->assertFalse($result['allowed']);
        $this->assertSame('file_too_large', $result['reason']);
    }

    public function testIncludesQuotaMetadataInAllResponses(): void
    {
        $this->expectRateLimitAllowed();
        $this->storageQuotaService->method('getRemainingStorage')->willReturn(0);

        $result = $this->service->checkUpload($this->createUser(), 'doc.pdf', 1024);

        $this->assertArrayHasKey('max_file_size', $result);
        $this->assertArrayHasKey('allowed_extensions', $result);
        $this->assertArrayHasKey('remaining', $result);
        $this->assertSame(0, $result['remaining']);
    }
}
