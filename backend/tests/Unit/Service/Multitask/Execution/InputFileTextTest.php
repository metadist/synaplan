<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution;

use App\Entity\File;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\FileRepository;
use App\Repository\UserRepository;
use App\Service\File\FileProcessor;
use App\Service\Multitask\Execution\InputFileText;
use App\Service\RateLimitService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class InputFileTextTest extends TestCase
{
    public function testTextOnTheDescriptorIsUsedAsItIs(): void
    {
        $processor = $this->createMock(FileProcessor::class);
        $processor->expects(self::never())->method('extractText');

        $text = $this->service(processor: $processor)->of(['path' => 'a.pdf', 'text' => 'HELLO', 'file_id' => 7], 1);

        self::assertSame('HELLO', $text);
    }

    public function testAFolderFileIsExtractedOnceKeptAndBilledOnce(): void
    {
        $file = $this->file(7, 1, 'inv-0101.txt');
        $processor = $this->createMock(FileProcessor::class);
        $processor->expects(self::once())->method('extractText')
            ->with('01/inv-0101.txt', 'txt', 1)
            ->willReturn(['Vendor: ACME, total 12.00', []]);
        $rateLimits = $this->createMock(RateLimitService::class);
        $rateLimits->expects(self::once())->method('recordFileAnalysisOnce')
            ->with(self::isInstanceOf(User::class), 7, self::arrayHasKey('filename'));
        $service = $this->service($file, $processor, $rateLimits);

        $first = $service->of(['local_path' => '01/inv-0101.txt', 'file_id' => 7], 1);
        $second = $service->of(['local_path' => '01/inv-0101.txt', 'file_id' => 7], 1);

        self::assertSame('Vendor: ACME, total 12.00', $first);
        self::assertSame($first, $second);
        self::assertSame($first, $file->getFileText());
    }

    public function testAnotherPersonsFileIsNeverRead(): void
    {
        $processor = $this->createMock(FileProcessor::class);
        $processor->expects(self::never())->method('extractText');

        $text = $this->service($this->file(7, 2, 'secret.pdf'), $processor)->of(['file_id' => 7], 1);

        self::assertSame('', $text);
    }

    public function testAFailedExtractionReadsAsNoText(): void
    {
        $file = $this->file(7, 1, 'scan.pdf');
        $processor = $this->createStub(FileProcessor::class);
        $processor->method('extractText')->willThrowException(new \RuntimeException('Tika is down'));
        $rateLimits = $this->createMock(RateLimitService::class);
        $rateLimits->expects(self::never())->method('recordFileAnalysisOnce');

        $text = $this->service($file, $processor, $rateLimits)->of(['file_id' => 7], 1);

        self::assertSame('', $text);
        self::assertSame('', $file->getFileText());
    }

    public function testAnImageOnlyBundleKeepsTheVisionPath(): void
    {
        $processor = $this->createMock(FileProcessor::class);
        $processor->expects(self::never())->method('extractText');
        $message = new Message();
        $message->setUserId(1);

        $unread = $this->service([$this->file(7, 1, 'a.png'), $this->file(8, 1, 'b.jpg')], $processor)
            ->attachTo($message, [['file_id' => 7, 'local_path' => '01/a.png'], ['file_id' => 8, 'local_path' => '01/b.jpg']]);

        self::assertSame([], $unread);
        self::assertCount(2, $message->getFiles());
    }

    public function testImagesInAMixedBundleAndAudioAreReadLikeUploads(): void
    {
        $processor = $this->createMock(FileProcessor::class);
        $processor->expects(self::exactly(3))->method('extractText')->willReturnCallback(static fn (string $path): array => match (basename($path)) {
            'memo.mp3' => ['Please pay by Friday.', []],
            'invoice.pdf' => ['ACME, 12.00', []],
            default => ['', []],
        });
        $message = new Message();
        $message->setUserId(1);

        $unread = $this->service([$this->file(7, 1, 'invoice.pdf'), $this->file(8, 1, 'scan.png'), $this->file(9, 1, 'memo.mp3')], $processor)
            ->attachTo($message, [['file_id' => 7, 'local_path' => 'a'], ['file_id' => 8, 'local_path' => 'b'], ['file_id' => 9, 'local_path' => 'c']]);

        self::assertSame(['scan.png'], $unread);
        self::assertCount(3, $message->getFiles());
        self::assertStringContainsString('Please pay by Friday.', $message->getAllFilesText());
    }

    public function testAStepThatNeedsTextReadsImagesToo(): void
    {
        $processor = $this->createMock(FileProcessor::class);
        $processor->expects(self::once())->method('extractText')->willReturn(['Receipt: 4.20 EUR', []]);
        $message = new Message();
        $message->setUserId(1);

        $unread = $this->service($this->file(7, 1, 'receipt.png'), $processor)
            ->attachTo($message, [['file_id' => 7, 'local_path' => '01/receipt.png']], imagesAsText: true);

        self::assertSame([], $unread);
        self::assertSame('Receipt: 4.20 EUR', $message->getAllFilesText());
    }

    private function service(File|array|null $file = null, ?FileProcessor $processor = null, ?RateLimitService $rateLimits = null): InputFileText
    {
        $byId = [];
        foreach (is_array($file) ? $file : array_filter([$file]) as $one) {
            $byId[(int) $one->getId()] = $one;
        }
        $files = $this->createStub(FileRepository::class);
        $files->method('find')->willReturnCallback(static fn (mixed $id): ?File => $byId[$id] ?? null);
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturn(new User());

        return new InputFileText(
            $files,
            $users,
            $processor ?? $this->createStub(FileProcessor::class),
            $rateLimits ?? $this->createStub(RateLimitService::class),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
        );
    }

    private function file(int $id, int $userId, string $name): File
    {
        $file = new File();
        (new \ReflectionProperty(File::class, 'id'))->setValue($file, $id);
        $file->setUserId($userId);
        $file->setFileName($name);
        $file->setFilePath('01/'.$name);
        $file->setFileType(pathinfo($name, PATHINFO_EXTENSION));

        return $file;
    }
}
