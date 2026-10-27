<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution\Runner;

use App\Service\File\UserUploadPathBuilder;
use App\Service\Multitask\Execution\Runner\UploadedNodeFiles;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class UploadedNodeFilesTest extends TestCase
{
    private const SERVE_PREFIX = '/api/v1/files/uploads/';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/uploaded-node-files-'.bin2hex(random_bytes(4));
        foreach ([7, 8] as $userId) {
            $dir = $this->root.'/'.$this->relative($userId, '');
            mkdir($dir, 0777, true);
            file_put_contents($dir.'report.pdf', 'pdf');
        }
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
    }

    public function testResolvesTheUsersOwnFile(): void
    {
        $relative = $this->relative(7, 'report.pdf');

        $files = $this->files()->resolve([['path' => self::SERVE_PREFIX.$relative, 'local_path' => $relative, 'name' => 'Report.pdf']], 7);

        self::assertCount(1, $files);
        self::assertSame('Report.pdf', $files[0]['name']);
    }

    public function testSkipsAnotherUsersFile(): void
    {
        $relative = $this->relative(8, 'report.pdf');

        self::assertSame([], $this->files()->resolve([['path' => self::SERVE_PREFIX.$relative, 'local_path' => $relative]], 7));
        self::assertSame([], $this->files()->resolve([['path' => self::SERVE_PREFIX.$relative]], 7));
    }

    public function testSkipsAPathThatClimbsOutOfTheUsersFolder(): void
    {
        $relative = $this->relative(7, '').'../../../../../'.$this->relative(8, 'report.pdf');

        self::assertSame([], $this->files()->resolve([['path' => self::SERVE_PREFIX.$relative, 'local_path' => $relative]], 7));
    }

    public function testResolvesNothingWithoutAUser(): void
    {
        $relative = $this->relative(7, 'report.pdf');

        self::assertSame([], $this->files()->resolve([['path' => self::SERVE_PREFIX.$relative, 'local_path' => $relative]], 0));
    }

    private function relative(int $userId, string $file): string
    {
        return (new UserUploadPathBuilder())->buildUserBaseRelativePath($userId).'/2026/10/'.$file;
    }

    private function files(): UploadedNodeFiles
    {
        return new UploadedNodeFiles(new NullLogger(), $this->root);
    }
}
