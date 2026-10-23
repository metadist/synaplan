<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution;

use App\Entity\File;
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

    private function service(?File $file = null, ?FileProcessor $processor = null, ?RateLimitService $rateLimits = null): InputFileText
    {
        $files = $this->createStub(FileRepository::class);
        $files->method('find')->willReturn($file);
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
