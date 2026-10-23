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
     * Attach the stored files among the descriptors to a step's message, the
     * way an upload is attached: documents with their text, images and audio
     * as they are for the vision and speech paths. Returns how many.
     *
     * @param list<array<mixed>> $descriptors {@see StepFiles::of()}
     */
    public function attachTo(Message $message, array $descriptors): int
    {
        $attached = 0;
        foreach ($descriptors as $descriptor) {
            $file = $this->file($descriptor, (int) $message->getUserId());
            if (!$file instanceof File) {
                continue;
            }
            $type = strtolower($file->getFileType());
            if (!in_array($type, MessagePreProcessor::IMAGE_EXTENSIONS, true) && !in_array($type, MessagePreProcessor::AUDIO_EXTENSIONS, true)) {
                $this->ensure($file);
            }
            $message->addFile($file);
            ++$attached;
        }

        return $attached;
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
