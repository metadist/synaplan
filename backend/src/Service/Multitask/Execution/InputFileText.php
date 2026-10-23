<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution;

use App\Entity\File;
use App\Entity\Message;
use App\Repository\FileRepository;
use App\Repository\UserRepository;
use App\Service\File\FileProcessor;
use App\Service\Message\MessagePreProcessor;
use App\Service\RateLimitService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The text of a file a step receives from an earlier step. An upload's text
 * was extracted before the turn ran; a file an earlier step produced (a
 * folder read, a cloud pickup) is extracted here on first use, kept on the
 * file and billed once, so it reads like an attachment in every later step.
 */
final readonly class InputFileText
{
    /** Names listed in a sentence; the rest are counted. */
    private const NAMES_IN_TEXT = 5;

    public function __construct(
        private FileRepository $files,
        private UserRepository $users,
        private FileProcessor $processor,
        private RateLimitService $rateLimits,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<mixed> $descriptor a resolved `$nX.files` entry
     */
    public function of(array $descriptor, int $userId): string
    {
        $text = $descriptor['text'] ?? null;
        if (is_string($text) && '' !== trim($text)) {
            return $text;
        }
        $file = $this->file($descriptor, $userId);

        return $file instanceof File ? $this->ensure($file) : '';
    }

    /**
     * The stored file behind a descriptor, only when it belongs to the user.
     *
     * @param array<mixed> $descriptor
     */
    public function file(array $descriptor, int $userId): ?File
    {
        $id = $descriptor['file_id'] ?? null;
        if (!is_int($id) || $id <= 0 || $userId <= 0) {
            return null;
        }
        $file = $this->files->find($id);

        return $file instanceof File && $file->getUserId() === $userId ? $file : null;
    }

    /**
     * Attach the stored files among the descriptors to a step's message the
     * way an upload is attached, with their text: documents, audio and video
     * always, images when other files come along or the step needs text. An
     * image-only bundle keeps the direct vision path.
     *
     * @param list<array<mixed>> $descriptors {@see StepFiles::of()}
     *
     * @return list<string> names of attached files whose text could not be read
     */
    public function attachTo(Message $message, array $descriptors, bool $imagesAsText = false): array
    {
        $files = [];
        foreach ($descriptors as $descriptor) {
            $file = $this->file($descriptor, (int) $message->getUserId());
            if ($file instanceof File) {
                $files[] = $file;
            }
        }
        $imagesAsText = $imagesAsText || [] !== array_filter($files, static fn (File $file): bool => !self::isImage($file));
        $unread = [];
        foreach ($files as $file) {
            if ((!self::isImage($file) || $imagesAsText) && '' === trim($this->ensure($file))) {
                $unread[] = $file->getFileName();
            }
            $message->addFile($file);
        }

        return $unread;
    }

    /**
     * "a.pdf, b.png and 3 more" for a sentence about files.
     *
     * @param list<string> $names
     */
    public static function listNames(array $names): string
    {
        $named = array_values(array_filter($names, static fn (string $name): bool => '' !== $name));
        if ([] === $named) {
            return 1 === count($names) ? 'the file' : count($names).' files';
        }
        $shown = array_slice($named, 0, self::NAMES_IN_TEXT);
        $rest = count($names) - count($shown);

        return implode(', ', $shown).($rest > 0 ? ' and '.$rest.' more' : '');
    }

    public function ensure(File $file): string
    {
        $stored = $file->getFileText();
        if ('' !== trim($stored)) {
            return $stored;
        }
        $path = $file->getFilePath();
        if ('' === $path) {
            return '';
        }
        $extension = strtolower(pathinfo('' !== $file->getFileName() ? $file->getFileName() : $path, PATHINFO_EXTENSION));
        try {
            [$text] = $this->processor->extractText($path, $extension, $file->getUserId());
        } catch (\Throwable $e) {
            $this->logger->warning('InputFileText: extraction failed', [
                'file_id' => $file->getId(),
                'error' => $e->getMessage(),
            ]);

            return '';
        }
        if ('' === trim($text)) {
            return '';
        }
        $file->setFileText($text);
        $this->em->flush();
        $this->bill($file);

        return $text;
    }

    private static function isImage(File $file): bool
    {
        return in_array(strtolower($file->getFileType()), MessagePreProcessor::IMAGE_EXTENSIONS, true);
    }

    private function bill(File $file): void
    {
        $user = $this->users->find($file->getUserId());
        $fileId = $file->getId();
        if (null === $user || null === $fileId) {
            return;
        }
        try {
            $this->rateLimits->recordFileAnalysisOnce($user, $fileId, [
                'filename' => $file->getFileName(),
                'source' => 'WEB',
                'stage' => 'step_input',
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('InputFileText: FILE_ANALYSIS recording failed (non-fatal)', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
