<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\File;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\FileRepository;
use App\Service\File\FileGroupDeletionService;
use App\Service\File\FileUploadService;
use App\Service\Research\UrlContentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The sources of a project: files, links and pasted text. A source is an
 * ordinary file row under the project's knowledge folder
 * (`BFILES.BGROUPKEY`), indexed once when it is added. A chat in the project
 * searches that index; it never reads the source again.
 *
 * A link is a snapshot of the page when it was added. `refresh` fetches it
 * again into the same row. A link that cannot be read is kept as a `failed`
 * row with the reason, so the person sees it and can remove or retry it.
 * Pages are read hop by hop, and every redirect target passes the SSRF guard.
 */
final readonly class ProjectSourceService
{
    public const SOURCE_LINK = 'project_link';
    public const SOURCE_TEXT = 'project_text';
    public const SOURCE_FILE = 'project_file';

    private const TITLE_MAX = 200;
    private const TEXT_MAX_CHARS = 500000;
    private const LINK_MAX_CHARS = 200000;

    public function __construct(
        private FileRepository $files,
        private FileUploadService $uploads,
        private UrlContentService $urls,
        private ProjectSourceIndexer $indexer,
        private FileGroupDeletionService $deletion,
        private ProjectsConfig $config,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @return list<ProjectSource>
     */
    public function list(Project $project): array
    {
        $out = [];
        foreach ($this->files->findByUserAndGroupKey($project->getUserId(), $project->getGroupKey()) as $file) {
            $out[] = $this->present($file);
        }
        usort($out, static fn (ProjectSource $a, ProjectSource $b): int => $b->createdAt <=> $a->createdAt);

        return $out;
    }

    /**
     * @param list<UploadedFile> $uploaded
     *
     * @return array{added: list<ProjectSource>, errors: list<array{filename: string, error: string}>}
     */
    public function addFiles(Project $project, User $user, array $uploaded): array
    {
        $this->assertRoom($project, \count($uploaded));
        $result = $this->uploads->uploadBatch($uploaded, $user, $project->getGroupKey(), 'full');

        $added = [];
        foreach ($result['files'] as $row) {
            $id = \is_array($row) ? ($row['id'] ?? $row['file_id'] ?? null) : null;
            $file = \is_int($id) ? $this->files->find($id) : null;
            if ($file instanceof File && $file->getUserId() === $project->getUserId()) {
                $file->setSource(self::SOURCE_FILE);
                $added[] = $this->present($file);
            }
        }
        $this->em->flush();

        $errors = [];
        foreach ($result['errors'] as $error) {
            if (\is_array($error)) {
                $errors[] = [
                    'filename' => \is_string($error['filename'] ?? null) ? $error['filename'] : '',
                    'error' => \is_string($error['error'] ?? null) ? $error['error'] : 'Upload failed',
                ];
            }
        }

        return ['added' => $added, 'errors' => $errors];
    }

    public function addLink(Project $project, User $user, string $url): ProjectSource
    {
        $url = trim($url);
        if ('' === $url || !preg_match('#^https?://#i', $url)) {
            throw ProjectException::invalid('url must start with http:// or https://');
        }
        $this->assertRoom($project, 1);
        $this->indexer->assertRate($user);

        $file = $this->newRow($project, $url, self::SOURCE_LINK, 'link')->setSourceId($url);
        $this->em->persist($file);
        $this->em->flush();

        return $this->fillLink($file, $user, $project);
    }

    public function addText(Project $project, User $user, string $title, string $text): ProjectSource
    {
        $title = trim($title);
        if ('' === $title || mb_strlen($title) > self::TITLE_MAX) {
            throw ProjectException::invalid('title must be 1 to '.self::TITLE_MAX.' characters');
        }
        if ('' === trim($text)) {
            throw ProjectException::invalid('text must not be empty');
        }
        if (mb_strlen($text) > self::TEXT_MAX_CHARS) {
            throw ProjectException::invalid('text is longer than '.self::TEXT_MAX_CHARS.' characters');
        }
        $this->assertRoom($project, 1);
        $this->indexer->assertRate($user);
        $this->indexer->assertStorage($user, \strlen($text));

        $file = $this->newRow($project, $title, self::SOURCE_TEXT, 'text');
        $this->em->persist($file);
        $this->em->flush();
        $this->indexer->index($file, $user, $project, $text, $title);

        return $this->present($file);
    }

    public function refresh(Project $project, User $user, int $fileId): ProjectSource
    {
        $file = $this->find($project, $fileId);
        if (self::SOURCE_LINK !== $file->getSource()) {
            throw ProjectException::invalid('Only a link can be refreshed');
        }
        $this->indexer->assertRate($user);

        return $this->fillLink($file, $user, $project);
    }

    public function remove(Project $project, int $fileId): void
    {
        $this->deletion->deleteFile($project->getUserId(), $this->find($project, $fileId));
    }

    public function find(Project $project, int $fileId): File
    {
        $file = $this->files->find($fileId);
        if (!$file instanceof File
            || $file->getUserId() !== $project->getUserId()
            || $file->getGroupKey() !== $project->getGroupKey()
        ) {
            throw ProjectException::notFound('Source');
        }

        return $file;
    }

    /**
     * Reads the page into the row. A first read that fails leaves a failed
     * row with the reason; a refresh that fails keeps the earlier snapshot
     * and answers with the reason instead.
     */
    private function fillLink(File $file, User $user, Project $project): ProjectSource
    {
        $hasSnapshot = '' !== $file->getFilePath() && File::VECTOR_STATE_VECTORIZED === $file->getVectorState();
        $page = $this->urls->fetchForReading((string) $file->getSourceId(), self::LINK_MAX_CHARS);
        if (!$page->success || '' === trim($page->extractedText)) {
            return $this->failLink($file, $hasSnapshot, $page->error ?? 'The page has no readable text');
        }
        try {
            $this->indexer->assertStorage($user, \strlen($page->extractedText));
        } catch (ProjectException $e) {
            return $this->failLink($file, $hasSnapshot, $e->getMessage(), $e);
        }

        $title = '' !== trim($page->title) ? trim($page->title) : $page->hostname;
        $file->setFileName(mb_substr($title, 0, 250));
        $file->setOriginalName((string) $file->getSourceId());
        $this->indexer->index($file, $user, $project, $page->extractedText, $title);

        return $this->present($file);
    }

    private function failLink(File $file, bool $hasSnapshot, string $reason, ?ProjectException $cause = null): ProjectSource
    {
        if ($hasSnapshot) {
            throw $cause ?? ProjectException::upstream($reason);
        }
        $this->indexer->fail($file, $reason);

        return $this->present($file);
    }

    private function newRow(Project $project, string $name, string $source, string $kind): File
    {
        return (new File())
            ->setUserId($project->getUserId())
            ->setFilePath('')
            ->setFileType('txt')
            ->setFileName(mb_substr($name, 0, 250))
            ->setFileSize(0)
            ->setFileMime('text/plain')
            ->setFileText('')
            ->setGroupKey($project->getGroupKey())
            ->setSource($source)
            ->setOriginKind($kind)
            ->setStatus('uploaded');
    }

    private function assertRoom(Project $project, int $adding): void
    {
        $have = \count($this->files->findByUserAndGroupKey($project->getUserId(), $project->getGroupKey()));
        if ($have + $adding > $this->config->maxSources()) {
            throw ProjectException::limit('A project holds at most '.$this->config->maxSources().' sources');
        }
    }

    private function present(File $file): ProjectSource
    {
        $failed = File::VECTOR_STATE_FAILED === $file->getVectorState() || 'failed' === $file->getStatus() || 'error' === $file->getStatus();
        $ready = !$failed && File::VECTOR_STATE_VECTORIZED === $file->getVectorState();
        $source = $file->getSource();

        return new ProjectSource(
            (int) $file->getId(),
            $file->getFileName(),
            self::SOURCE_LINK === $source ? ProjectSource::KIND_LINK : (self::SOURCE_TEXT === $source ? ProjectSource::KIND_TEXT : ProjectSource::KIND_FILE),
            $failed ? ProjectSource::STATE_FAILED : ($ready ? ProjectSource::STATE_READY : ProjectSource::STATE_READING),
            $failed ? ('' !== $file->getFileText() ? $file->getFileText() : null) : null,
            self::SOURCE_LINK === $source ? $file->getSourceId() : null,
            self::SOURCE_LINK === $source && $ready ? $file->getUpdatedAt() : null,
            $file->getFileSize(),
            $file->getCreatedAt(),
        );
    }
}
