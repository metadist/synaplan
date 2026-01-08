<?php

namespace App\Tests\Service\File;

use App\Service\File\FileStorageService;
use App\Service\File\ThumbnailService;
use App\Service\File\UserUploadPathBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class FileStorageServiceTest extends TestCase
{
    private FileStorageService $service;
    private string $testUploadDir;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        // Create temp upload directory
        $this->testUploadDir = sys_get_temp_dir().'/synaplan_test_uploads_'.uniqid();
        mkdir($this->testUploadDir, 0755, true);

        $this->logger = $this->createMock(LoggerInterface::class);
        $thumbnailService = new ThumbnailService($this->testUploadDir, $this->logger);
        $this->service = new FileStorageService(
            $this->testUploadDir,
            $this->logger,
            new UserUploadPathBuilder(),
            $thumbnailService
        );
    }

    protected function tearDown(): void
    {
        // Clean up test directory
        if (is_dir($this->testUploadDir)) {
            $this->recursiveDelete($this->testUploadDir);
        }
    }

    public function testStoreUploadedFileSuccess(): void
    {
        // Create test file
        $testFile = $this->createTestFile('test.txt', 'Hello World');

        $uploadedFile = new UploadedFile(
            $testFile,
            'test.txt',
            'text/plain',
            null,
            true
        );

        $result = $this->service->storeUploadedFile($uploadedFile, 123);

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['path']);
        $this->assertGreaterThan(0, $result['size']);
        $this->assertEquals('text/plain', $result['mime']);
        $this->assertNull($result['error']);

        // Verify file exists
        $this->assertTrue($this->service->fileExists($result['path']));
    }

    public function testStoreUploadedFileWithDisallowedExtension(): void
    {
        $testFile = $this->createTestFile('test.exe', 'binary data');

        $uploadedFile = new UploadedFile(
            $testFile,
            'test.exe',
            'application/x-msdownload',
            null,
            true
        );

        $result = $this->service->storeUploadedFile($uploadedFile, 123);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not allowed', $result['error']);
    }

    public function testStoreUploadedFileWithLargeSize(): void
    {
        // Create a file that reports as larger than allowed
        $testFile = $this->createTestFile('large.pdf', 'test');

        // Mock the UploadedFile to report a large size
        $uploadedFile = $this->getMockBuilder(UploadedFile::class)
            ->setConstructorArgs([$testFile, 'large.pdf', 'application/pdf', null, true])
            ->onlyMethods(['getSize'])
            ->getMock();

        $uploadedFile->method('getSize')->willReturn(150 * 1024 * 1024); // 150 MB

        $result = $this->service->storeUploadedFile($uploadedFile, 123);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('too large', $result['error']);
    }

    public function testFileExistsReturnsTrueForExistingFile(): void
    {
        $testFile = $this->createTestFile('exists.txt', 'content');

        $uploadedFile = new UploadedFile(
            $testFile,
            'exists.txt',
            'text/plain',
            null,
            true
        );

        $result = $this->service->storeUploadedFile($uploadedFile, 123);

        $this->assertTrue($this->service->fileExists($result['path']));
    }

    public function testFileExistsReturnsFalseForNonExistingFile(): void
    {
        $this->assertFalse($this->service->fileExists('nonexistent/file.txt'));
    }

    public function testDeleteFileSuccess(): void
    {
        $testFile = $this->createTestFile('delete.txt', 'to be deleted');

        $uploadedFile = new UploadedFile(
            $testFile,
            'delete.txt',
            'text/plain',
            null,
            true
        );

        $result = $this->service->storeUploadedFile($uploadedFile, 123);
        $path = $result['path'];

        $this->assertTrue($this->service->fileExists($path));

        $deleted = $this->service->deleteFile($path);

        $this->assertTrue($deleted);
        $this->assertFalse($this->service->fileExists($path));
    }

    public function testDeleteNonExistentFile(): void
    {
        $deleted = $this->service->deleteFile('nonexistent/file.txt');

        $this->assertFalse($deleted);
    }

    public function testGenerateStoragePathCreatesOrganizedStructure(): void
    {
        $testFile = $this->createTestFile('organized.pdf', 'content');

        $uploadedFile = new UploadedFile(
            $testFile,
            'organized.pdf',
            'application/pdf',
            null,
            true
        );

        $result = $this->service->storeUploadedFile($uploadedFile, 456);
        $path = $result['path'];

        // Path should contain: {last2}/{prev3}/{paddedUserId}/year/month/filename
        $this->assertStringContainsString('56/004/00456/', $path);
        $this->assertStringContainsString(date('Y').'/', $path);
        $this->assertStringContainsString(date('m').'/', $path);
    }

    // Helper methods

    private function createTestFile(string $filename, string $content): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'synaplan_test_');
        file_put_contents($tempFile, $content);

        return $tempFile;
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir.'/'.$file;
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }

        rmdir($dir);
    }
}
