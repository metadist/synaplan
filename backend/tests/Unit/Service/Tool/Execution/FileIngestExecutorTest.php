<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tool\Execution;

use App\Entity\User;
use App\Service\File\FileStorageService;
use App\Service\File\VectorizationService;
use App\Service\RateLimitService;
use App\Service\Tool\Execution\FileIngestExecutor;
use App\Service\Tool\Execution\ToolChannel;
use App\Service\Tool\ToolSource;
use App\Service\Usage\StorageQuotaService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class FileIngestExecutorTest extends TestCase
{
    use ToolExecutionExamples;

    public function testEmptyContentFailsBeforeAnythingIsStored(): void
    {
        $storage = $this->createMock(FileStorageService::class);
        $storage->expects(self::never())->method('storeRawContent');
        $executor = $this->executor($storage);
        $result = $executor->execute(
            $this->descriptor(ToolSource::Plugin, 'file_ingest'),
            $this->invocation(ToolChannel::MCP_SERVER, 'file_ingest', ['name' => 'notes', 'content' => '   '], $this->createStub(User::class)),
        );

        self::assertSame('content must not be empty.', $result->text);
    }

    public function testARateLimitFailsBeforeTheQuotaCheck(): void
    {
        $quota = $this->createMock(StorageQuotaService::class);
        $quota->expects(self::never())->method('checkStorageLimit');
        $rates = $this->createStub(RateLimitService::class);
        $rates->method('checkLimit')->willReturn(['allowed' => false]);
        $executor = new FileIngestExecutor(
            $this->createStub(FileStorageService::class),
            $quota,
            $this->createStub(VectorizationService::class),
            $this->createStub(EntityManagerInterface::class),
            $rates,
        );
        $result = $executor->execute(
            $this->descriptor(ToolSource::Plugin, 'file_ingest'),
            $this->invocation(ToolChannel::MCP_SERVER, 'file_ingest', ['name' => 'notes', 'content' => 'hello'], $this->createStub(User::class)),
        );

        self::assertSame('Rate limit exceeded for file ingestion.', $result->text);
    }

    private function executor(FileStorageService $storage): FileIngestExecutor
    {
        return new FileIngestExecutor(
            $storage,
            $this->createStub(StorageQuotaService::class),
            $this->createStub(VectorizationService::class),
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(RateLimitService::class),
        );
    }
}
