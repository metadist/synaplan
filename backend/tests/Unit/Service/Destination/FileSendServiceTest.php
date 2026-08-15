<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Destination;

use App\Entity\File;
use App\Repository\FileRepository;
use App\Service\Destination\DestinationRegistry;
use App\Service\Destination\FileSendService;
use App\Service\File\FileStorageService;
use PHPUnit\Framework\TestCase;

final class FileSendServiceTest extends TestCase
{
    public function testOtherUsersFileIsForbidden(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getUserId')->willReturn(2);

        $files = $this->createMock(FileRepository::class);
        $files->method('find')->willReturn($file);

        $service = new FileSendService(
            $files,
            $this->createStub(FileStorageService::class),
            new DestinationRegistry([]),
        );

        $result = $service->send(15, 1, 'email', []);
        $this->assertSame(403, $result['status']);
        $this->assertSame('unauthorized', $result['body']['code']);
    }

    public function testMissingFileIsNotFound(): void
    {
        $files = $this->createMock(FileRepository::class);
        $files->method('find')->willReturn(null);

        $service = new FileSendService(
            $files,
            $this->createStub(FileStorageService::class),
            new DestinationRegistry([]),
        );

        $result = $service->send(15, 1, 'email', []);
        $this->assertSame(404, $result['status']);
    }
}
