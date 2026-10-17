<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Handler\Media;

use App\Service\File\UserUploadPathBuilder;
use App\Service\Message\Handler\Media\MediaDownloader;
use App\Service\Message\Handler\Media\MediaUploadStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MediaUploadStoreTest extends TestCase
{
    private const PIXEL = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAACklEQVR4nGMAAQAABQABDQottAAAAABJRU5ErkJggg==';

    private string $uploadDir = '';

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/media-upload-store-'.bin2hex(random_bytes(4));
        mkdir($this->uploadDir);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->uploadDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->uploadDir);
    }

    public function testADataUrlIsSavedInTheUsersMonthFolder(): void
    {
        $path = $this->store()->saveDataUrlAsFile('data:image/png;base64,'.self::PIXEL, 31, 7, 'Open AI');

        self::assertMatchesRegularExpression('#^'.preg_quote($this->monthFolder(7), '#').'31_\w+_\d+\.png$#', (string) $path);
        self::assertSame(base64_decode(self::PIXEL), file_get_contents($this->uploadDir.'/'.$path));
        self::assertNull($this->store()->saveDataUrlAsFile('not a data url', 31, 7, 'openai'));
    }

    public function testGeneratedMp4AudioIsStoredAsM4a(): void
    {
        $path = $this->store()->saveDataUrlAsFile('data:audio/mp4;base64,'.base64_encode('mp4 audio'), 31, 7, 'openai');

        self::assertStringEndsWith('.m4a', (string) $path);
    }

    public function testADownloadIsSavedWithTheExtensionOfItsContent(): void
    {
        $path = $this->store(fetched: base64_decode(self::PIXEL))->downloadMedia('https://cdn.example.com/out', 31, 7, 'openai', 'image');

        self::assertMatchesRegularExpression('#^'.preg_quote($this->monthFolder(7), '#').'31_openai_\d+\.png$#', (string) $path);
        self::assertSame(base64_decode(self::PIXEL), file_get_contents($this->uploadDir.'/'.$path));
    }

    public function testAFailedOrEmptyDownloadSavesNothing(): void
    {
        self::assertNull($this->store(fetchFails: true)->downloadMedia('https://cdn.example.com/out', 31, 7, 'openai', 'image'));
        self::assertNull($this->store(fetched: '')->downloadMedia('https://cdn.example.com/out', 31, 7, 'openai', 'image'));
        self::assertSame([], glob($this->uploadDir.'/*'));
    }

    public function testAnInputImageIsPublishedAsAPublicCopy(): void
    {
        $source = $this->uploadDir.'/photo.jpg';
        file_put_contents($source, 'jpeg bytes');

        $url = (string) $this->store()->publishInputImageForRemoteFetch($source, 7);

        self::assertMatchesRegularExpression('#^https://app\.example\.com/api/v1/files/uploads/'.preg_quote($this->monthFolder(7), '#').'ai_i2vsrc_7_\d+_[0-9a-f]{12}\.jpg$#', $url);
        self::assertSame('jpeg bytes', file_get_contents($this->uploadDir.'/'.substr($url, strlen('https://app.example.com/api/v1/files/uploads/'))));
        self::assertNull($this->store()->publishInputImageForRemoteFetch($this->uploadDir.'/missing.jpg', 7));
        self::assertNull($this->store(publicBaseUrl: '')->publishInputImageForRemoteFetch($source, 7));
    }

    #[DataProvider('baseUrls')]
    public function testOnlyAnInternetHostIsReachableForAProvider(string $publicBaseUrl, bool $reachable): void
    {
        self::assertSame($reachable, $this->store(publicBaseUrl: $publicBaseUrl)->isPublicBaseUrlReachable());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function baseUrls(): iterable
    {
        yield 'a public host' => ['https://web.synaplan.com', true];
        yield 'a public address' => ['http://8.8.8.8', true];
        yield 'none' => ['', false];
        yield 'localhost' => ['http://localhost:8000', false];
        yield 'the docker host' => ['http://host.docker.internal', false];
        yield 'a local suffix' => ['https://synaplan.test', false];
        yield 'a private address' => ['http://192.168.1.20', false];
        yield 'loopback IPv6' => ['http://[::1]:8000', false];
    }

    private function store(string $fetched = '', bool $fetchFails = false, string $publicBaseUrl = 'https://app.example.com'): MediaUploadStore
    {
        $downloader = $this->createStub(MediaDownloader::class);
        if ($fetchFails) {
            $downloader->method('fetch')->willThrowException(new \Exception('cURL download failed (HTTP 404): '));
        } else {
            $downloader->method('fetch')->willReturn($fetched);
        }

        return new MediaUploadStore(new UserUploadPathBuilder(), $downloader, new NullLogger(), $this->uploadDir, $publicBaseUrl);
    }

    private function monthFolder(int $userId): string
    {
        return (new UserUploadPathBuilder())->buildUserBaseRelativePath($userId).'/'.date('Y').'/'.date('m').'/';
    }
}
