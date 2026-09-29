<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Telegram;

use App\Entity\Message;
use App\Entity\User;
use App\Service\File\FileStorageService;
use App\Service\StorageQuotaService;
use App\Service\Telegram\TelegramBotApi;
use App\Service\Telegram\TelegramChannelException;
use App\Service\Telegram\TelegramMediaDownloader;
use App\Service\Telegram\TelegramMediaRef;
use App\Service\Telegram\TelegramMediaRejected;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
final class TelegramMediaDownloaderTest extends TestCase
{
    private const PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\xf8\x0f\0\0\x01\x01\0\x05\x18\xd8N\0\0\0\0IEND\xaeB`\x82";

    /** @var list<array{name: string, mime: string}> */
    private array $stored = [];

    public function testAPhotoIsStoredAsATelegramFileOnTheMessage(): void
    {
        $message = new Message();
        (new \ReflectionProperty(Message::class, 'id'))->setValue($message, 12);

        $file = $this->downloader(self::PNG)->attach('token', $this->owner(), new TelegramMediaRef(TelegramMediaRef::PHOTO, 'f', 100, null, null), $message);

        $this->assertSame('telegram', $file->getSource());
        $this->assertSame('png', $file->getFileType());
        $this->assertSame('uploaded', $file->getStatus());
        $this->assertSame(12, $file->getMessageId());
        $this->assertSame(1, $message->getFiles()->count());
        $this->assertSame(1, $message->getFile());
    }

    public function testTheSentFileNameIsKeptWhenItMatchesTheContent(): void
    {
        $this->downloader('%PDF-1.4 test')->attach('token', $this->owner(), new TelegramMediaRef(TelegramMediaRef::DOCUMENT, 'f', 100, 'Offer 2026.pdf', 'application/pdf'), new Message());

        $this->assertSame('Offer 2026.pdf', $this->stored[0]['name']);
    }

    public function testAFileNamePretendingAnotherTypeIsRenamed(): void
    {
        $file = $this->downloader(self::PNG)->attach('token', $this->owner(), new TelegramMediaRef(TelegramMediaRef::DOCUMENT, 'f', 100, 'invoice.pdf', 'application/pdf'), new Message());

        $this->assertSame('png', $file->getFileType());
        $this->assertSame('invoice.png', $this->stored[0]['name']);
    }

    public function testAnExecutableIsRefused(): void
    {
        $this->expectExceptionObject(new TelegramMediaRejected(TelegramMediaRejected::UNSUPPORTED));

        $this->downloader("MZ\x90\0\x03\0\0\0\x04\0\0\0\xff\xff\0\0".str_repeat("\0", 64).'PE')->attach('token', $this->owner(), new TelegramMediaRef(TelegramMediaRef::DOCUMENT, 'f', 100, 'setup.pdf', null), new Message());
    }

    public function testAFileAboveTheBotLimitIsRefusedBeforeDownloading(): void
    {
        $this->expectExceptionObject(new TelegramMediaRejected(TelegramMediaRejected::TOO_LARGE));

        $this->downloader(null)->attach('token', $this->owner(), new TelegramMediaRef(TelegramMediaRef::VIDEO, 'f', TelegramBotApi::MAX_DOWNLOAD_BYTES + 1, null, null), new Message());
    }

    public function testAFullStorageIsRefused(): void
    {
        $this->expectExceptionObject(new TelegramMediaRejected(TelegramMediaRejected::STORAGE_FULL));

        $this->downloader(self::PNG, remaining: 10)->attach('token', $this->owner(), new TelegramMediaRef(TelegramMediaRef::PHOTO, 'f', null, null, null), new Message());
    }

    public function testAFailedDownloadSaysSo(): void
    {
        $this->expectExceptionObject(new TelegramMediaRejected(TelegramMediaRejected::DOWNLOAD_FAILED));

        $this->downloader(null, downloadError: TelegramChannelException::DOWNLOAD_FAILED)->attach('token', $this->owner(), new TelegramMediaRef(TelegramMediaRef::PHOTO, 'f', 100, null, null), new Message());
    }

    private function downloader(?string $content, int $remaining = 1_000_000, ?string $downloadError = null): TelegramMediaDownloader
    {
        $api = $this->createMock(TelegramBotApi::class);
        if (null === $content && null === $downloadError) {
            $api->expects($this->never())->method('getFile');
        }
        $api->method('getFile')->willReturn(['path' => 'documents/file_1', 'size' => null]);
        $api->method('downloadFile')->willReturnCallback(static function () use ($content, $downloadError): string {
            if (null !== $downloadError) {
                throw new TelegramChannelException($downloadError);
            }

            return (string) $content;
        });

        $storage = $this->createStub(FileStorageService::class);
        $storage->method('storeRawContent')->willReturnCallback(function (string $bytes, int $userId, string $name, string $mime): array {
            $this->stored[] = ['name' => $name, 'mime' => $mime];

            return ['success' => true, 'path' => '7/'.$name, 'size' => strlen($bytes), 'mime' => $mime, 'error' => null];
        });
        $quota = $this->createStub(StorageQuotaService::class);
        $quota->method('getRemainingStorage')->willReturn($remaining);

        return new TelegramMediaDownloader($api, $storage, $quota, $this->createStub(EntityManagerInterface::class), new NullLogger());
    }

    private function owner(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);

        return $user;
    }
}
