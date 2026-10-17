<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Handler\Media;

use App\Entity\File;
use App\Entity\Message;
use App\Service\Destination\RequestedFolderDelivery;
use App\Service\File\ThumbnailService;
use App\Service\File\UserUploadPathBuilder;
use App\Service\Media\GeneratedFileRegistrar;
use App\Service\Message\Handler\Media\GeneratedMediaStore;
use App\Service\Message\Handler\Media\MediaDownloader;
use App\Service\Message\Handler\Media\MediaModel;
use App\Service\Message\Handler\Media\MediaReferences;
use App\Service\Message\Handler\Media\MediaTurnRequest;
use App\Service\Message\Handler\Media\MediaUploadStore;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class GeneratedMediaStoreTest extends TestCase
{
    private const PIXEL = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAACklEQVR4nGMAAQAABQABDQottAAAAABJRU5ErkJggg==';

    private string $uploadDir = '';

    /** @var list<mixed> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/generated-media-'.bin2hex(random_bytes(4));
        mkdir($this->uploadDir);
    }

    protected function tearDown(): void
    {
        $paths = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->uploadDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($paths as $path) {
            $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
        }
        rmdir($this->uploadDir);
    }

    public function testAPictureIsSavedAndListedInTheGeneratedGallery(): void
    {
        $stored = $this->store()->keep($this->request('image'), ['provider' => 'openai'], 'data:image/png;base64,'.self::PIXEL, null);

        self::assertFileExists($this->uploadDir.'/'.$stored->localPath);
        self::assertSame('/api/v1/files/uploads/'.$stored->localPath, $stored->displayUrl);
        self::assertNull($stored->thumbnailPath);
        self::assertSame([['register', $stored->localPath, 'image', 'openai', false, null]], $this->log);
    }

    public function testADownloadThatFailsSavesNothing(): void
    {
        $this->expectExceptionMessage('Failed to download image from: https://cdn.provider.test/fox.png');

        $this->store(downloadFails: true)->keep($this->request('image'), [], 'https://cdn.provider.test/fox.png', null);
    }

    public function testAVideoKeepsItsPosterOnTheFileRow(): void
    {
        $stored = $this->store(poster: true)->keep($this->request('video', ['incognito' => true]), [], 'https://cdn.provider.test/clip.mp4', null);

        self::assertSame($stored->localPath.'.jpg', $stored->thumbnailPath);
        self::assertSame(['register', 'thumbnail', 'poster '.$stored->localPath.'.jpg', 'flush'], array_map(static fn (array $entry): string => (string) $entry[0], $this->log));
        self::assertTrue($this->log[0][4], 'An incognito file is ephemeral.');
    }

    public function testSpeechKeepsItsScriptOnTheFileRow(): void
    {
        $this->store()->keepSpeech($this->request('audio'), '07/000/00007/tts/welcome.mp3', 'openai');

        self::assertSame([['register', '07/000/00007/tts/welcome.mp3', 'audio', 'openai', false, 'A fox']], $this->log);
    }

    public function testAFileGoesToTheFolderOnlyWhenTheUserAskedForIt(): void
    {
        file_put_contents($this->uploadDir.'/fox.png', 'bytes');

        self::assertSame(
            [null, null, 'Saved to Nextcloud'],
            [
                $this->store(asked: true)->deliverToFolder($this->message(), 'fox.png', ['skip_folder_delivery' => true]),
                $this->store(asked: false)->deliverToFolder($this->message(), 'fox.png', []),
                $this->store(asked: true)->deliverToFolder($this->message(), 'fox.png', []),
            ],
        );
        self::assertSame([['folder', 7, [['path' => $this->uploadDir.'/fox.png', 'name' => 'fox.png']]]], $this->log);
    }

    private function store(bool $downloadFails = false, bool $poster = false, bool $asked = false): GeneratedMediaStore
    {
        $downloader = $this->createStub(MediaDownloader::class);
        if ($downloadFails) {
            $downloader->method('fetch')->willThrowException(new \Exception('cURL download failed (HTTP 404): '));
        } else {
            $downloader->method('fetch')->willReturn(base64_decode(self::PIXEL));
        }
        $registrar = $this->createStub(GeneratedFileRegistrar::class);
        $registrar->method('register')->willReturnCallback(function (int $userId, ?string $path, string $type, ?int $messageId = null, ?string $provider = null, bool $ephemeral = false, ?string $fileText = null): File {
            $this->log[] = ['register', $path, $type, $provider, $ephemeral, $fileText];
            $file = $this->createStub(File::class);
            $file->method('setThumbPath')->willReturnCallback(function (string $thumb) use ($file): File {
                $this->log[] = ['poster '.$thumb];

                return $file;
            });

            return $file;
        });
        $thumbnails = $this->createStub(ThumbnailService::class);
        $thumbnails->method('generateThumbnail')->willReturnCallback(function (string $path) use ($poster): ?string {
            $this->log[] = ['thumbnail'];

            return $poster ? $path.'.jpg' : null;
        });
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('flush')->willReturnCallback(function (): void {
            $this->log[] = ['flush'];
        });
        $folders = $this->createStub(RequestedFolderDelivery::class);
        $folders->method('userAskedToSaveToFolder')->willReturn($asked);
        $folders->method('send')->willReturnCallback(function (int $ownerId, array $files): array {
            $this->log[] = ['folder', $ownerId, $files];

            return ['message' => 'Saved to Nextcloud'];
        });

        return new GeneratedMediaStore(
            new MediaUploadStore(new UserUploadPathBuilder(), $downloader, new NullLogger(), $this->uploadDir),
            $registrar,
            $thumbnails,
            $em,
            new NullLogger(),
            $this->uploadDir,
            $folders,
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function request(string $mediaType, array $options = []): MediaTurnRequest
    {
        return new MediaTurnRequest($this->message(), [], [], $options, 'A fox', $mediaType, new MediaReferences([], [], null), new MediaModel(41, 'openai', 'gpt-image-1', []), 7);
    }

    private function message(): Message
    {
        $message = $this->createStub(Message::class);
        $message->method('getUserId')->willReturn(7);
        $message->method('getId')->willReturn(1001);
        $message->method('getText')->willReturn('Draw a fox and save it to Nextcloud');

        return $message;
    }
}
