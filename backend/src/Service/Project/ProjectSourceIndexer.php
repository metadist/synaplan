<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\File;
use App\Entity\Project;
use App\Entity\User;
use App\Service\File\FileHelper;
use App\Service\File\FileStorageService;
use App\Service\File\VectorizationService;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use App\Service\RateLimitService;
use App\Service\Usage\StorageQuotaService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Stores the text of a link or pasted-text source, indexes it under the
 * project's folder, and checks the account's limits before any of it.
 */
final readonly class ProjectSourceIndexer
{
    private const REASON_MAX = 500;

    public function __construct(
        private FileStorageService $storage,
        private VectorizationService $vectors,
        private VectorStorageFacade $vectorStorage,
        private StorageQuotaService $quota,
        private RateLimitService $rates,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * Checked before a page is fetched or a text is stored, so a burst of
     * requests cannot make the server fetch pages without limit.
     */
    public function assertRate(User $user): void
    {
        if (!($this->rates->checkLimit($user, 'FILE_ANALYSIS')['allowed'] ?? false)) {
            throw ProjectException::rateLimited('Rate limit exceeded for file analysis');
        }
    }

    public function assertStorage(User $user, int $bytes): void
    {
        try {
            $this->quota->checkStorageLimit($user, $bytes);
        } catch (\RuntimeException $e) {
            throw ProjectException::limit($e->getMessage());
        }
    }

    /**
     * Replaces the stored text and the index entries of the row. A row whose
     * text cannot be stored or indexed ends as failed with the reason.
     */
    public function index(File $file, User $user, Project $project, string $text, string $title): void
    {
        $stored = $this->storage->storeRawContent($text, $project->getUserId(), $title.'.txt', 'text/plain');
        if (!$stored['success']) {
            $this->fail($file, (string) ($stored['error'] ?? 'The text could not be stored'));

            return;
        }
        $oldPath = $file->getFilePath();
        if ('' !== $oldPath) {
            $this->storage->deleteFile($oldPath);
        }
        $file->setFilePath((string) $stored['path'])->setFileSize((int) $stored['size'])->setFileText($text);
        $file->setStatus('extracted');
        $this->em->flush();

        $this->vectorStorage->deleteByFile($project->getUserId(), (int) $file->getId());
        $vector = $this->vectors->vectorizeAndStore(
            $text,
            $project->getUserId(),
            (int) $file->getId(),
            $project->getGroupKey(),
            FileHelper::getFileTypeCode('txt'),
        );
        if (!($vector['success'] ?? false)) {
            $error = $vector['error'] ?? null;
            $this->fail($file, \is_string($error) && '' !== $error ? $error : 'Indexing failed');

            return;
        }
        $file->markVectorized();
        $file->setChunkCount((int) ($vector['chunks_created'] ?? 0));
        $this->em->flush();
        $this->rates->recordFileAnalysisOnce($user, (int) $file->getId(), ['source' => 'PROJECT', 'filename' => $title]);
    }

    /**
     * Marks a row failed with its reason. The reason lives in the file text
     * of a failed row, which is never indexed.
     */
    public function fail(File $file, string $reason): void
    {
        $file->setStatus('failed');
        $file->setVectorState(File::VECTOR_STATE_FAILED);
        $file->setFileText(mb_substr($reason, 0, self::REASON_MAX));
        $this->em->flush();
    }
}
