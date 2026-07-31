<?php

declare(strict_types=1);

namespace App\Tests\Service\LocalAi;

use App\Service\LocalAi\LocalAiDownloadStatusService;
use PHPUnit\Framework\TestCase;

final class LocalAiDownloadStatusServiceTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpFile = sys_get_temp_dir().'/ollama-download-status-'.uniqid('', true).'.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    public function testMissingFileReturnsIdle(): void
    {
        $service = new LocalAiDownloadStatusService($this->tmpFile.'/does-not-exist.json');

        $status = $service->getStatus();

        self::assertSame(LocalAiDownloadStatusService::STATUS_IDLE, $status['status']);
        self::assertNull($status['percent']);
        self::assertFalse($service->isActivelyDownloading());
    }

    public function testReadsValidStatusFile(): void
    {
        file_put_contents($this->tmpFile, json_encode([
            'status' => 'downloading',
            'currentModel' => 'bge-m3',
            'percent' => 43,
            'message' => 'Downloading bge-m3',
            'models' => [
                ['name' => 'bge-m3', 'state' => 'downloading', 'percent' => 43],
            ],
            'updatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
        ], JSON_THROW_ON_ERROR));

        $service = new LocalAiDownloadStatusService($this->tmpFile);
        $status = $service->getStatus();

        self::assertSame('downloading', $status['status']);
        self::assertSame('bge-m3', $status['currentModel']);
        self::assertSame(43, $status['percent']);
        self::assertTrue($service->isActivelyDownloading());
        self::assertCount(1, $status['models']);
    }

    /**
     * A container killed mid-pull leaves "downloading" behind forever; the UI
     * must not keep showing a download that nobody is running.
     */
    public function testStaleInProgressStatusIsReportedAsIdle(): void
    {
        file_put_contents($this->tmpFile, json_encode([
            'status' => 'downloading',
            'currentModel' => 'bge-m3',
            'percent' => 20,
            'message' => 'Downloading bge-m3',
            'models' => [],
            'updatedAt' => gmdate('Y-m-d\TH:i:s\Z', time() - 7200),
        ], JSON_THROW_ON_ERROR));

        $service = new LocalAiDownloadStatusService($this->tmpFile);

        self::assertSame(LocalAiDownloadStatusService::STATUS_IDLE, $service->getStatus()['status']);
        self::assertFalse($service->isActivelyDownloading());
    }

    public function testFinishedStatusIsKeptRegardlessOfAge(): void
    {
        file_put_contents($this->tmpFile, json_encode([
            'status' => 'ready',
            'currentModel' => null,
            'percent' => 100,
            'message' => 'Local AI models ready',
            'models' => [],
            'updatedAt' => gmdate('Y-m-d\TH:i:s\Z', time() - 7200),
        ], JSON_THROW_ON_ERROR));

        $service = new LocalAiDownloadStatusService($this->tmpFile);

        self::assertSame(LocalAiDownloadStatusService::STATUS_READY, $service->getStatus()['status']);
    }

    public function testCorruptJsonReturnsIdle(): void
    {
        file_put_contents($this->tmpFile, '{not-json');

        $service = new LocalAiDownloadStatusService($this->tmpFile);

        self::assertSame(LocalAiDownloadStatusService::STATUS_IDLE, $service->getStatus()['status']);
    }
}
