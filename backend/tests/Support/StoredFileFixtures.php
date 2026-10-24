<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\File;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\FileRepository;
use App\Repository\UserRepository;
use App\Service\File\FileProcessor;
use App\Service\Multitask\Execution\InputFileText;
use App\Service\RateLimitService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;

/**
 * Files an earlier step stored for one person, and the real
 * {@see InputFileText} over them with a fake extractor.
 *
 * @phpstan-require-extends \PHPUnit\Framework\TestCase
 */
trait StoredFileFixtures
{
    private const OWNER = 2;

    private function storedFile(int $id, string $name): File
    {
        $file = new File();
        (new \ReflectionProperty(File::class, 'id'))->setValue($file, $id);
        $file->setUserId(self::OWNER);
        $file->setFileName($name);
        $file->setFilePath('02/'.$name);
        $file->setFileType(pathinfo($name, PATHINFO_EXTENSION));

        return $file;
    }

    /**
     * @param list<File>            $files
     * @param array<string, string> $texts extracted text per file name
     */
    private function inputFileText(array $files, array $texts): InputFileText
    {
        $byId = [];
        foreach ($files as $file) {
            $byId[(int) $file->getId()] = $file;
        }
        $repository = $this->createStub(FileRepository::class);
        $repository->method('find')->willReturnCallback(static fn (mixed $id): ?File => $byId[$id] ?? null);
        $processor = $this->createStub(FileProcessor::class);
        $processor->method('extractText')->willReturnCallback(static fn (string $path): array => [$texts[basename($path)] ?? '', []]);
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturn(new User());

        return new InputFileText(
            $repository,
            $users,
            $processor,
            $this->createStub(RateLimitService::class),
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
        );
    }

    /**
     * The turn's message, with what the person uploaded to it.
     *
     * @param list<File> $uploads
     */
    private function owner(string $text = 'make one table of these invoices', array $uploads = []): Message
    {
        $message = $this->createStub(Message::class);
        $message->method('getUserId')->willReturn(self::OWNER);
        $message->method('getText')->willReturn($text);
        $message->method('getFiles')->willReturn(new ArrayCollection($uploads));

        return $message;
    }
}
