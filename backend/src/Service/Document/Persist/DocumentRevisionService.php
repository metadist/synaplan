<?php

declare(strict_types=1);

namespace App\Service\Document\Persist;

use App\Entity\DocumentRevision;
use App\Entity\File;
use App\Repository\DocumentRevisionRepository;
use App\Service\Document\DocumentToolsConfig;
use App\Service\Document\Model\DeckModel;
use App\Service\Document\Model\SpreadsheetModel;
use App\Service\Document\Model\WordModel;
use App\Service\Document\Render\DocumentRenderer;
use App\Service\Document\Serializer\DocumentModelSerializer;
use App\Service\File\FileHelper;
use App\Service\File\Office\DocumentExportService;
use App\Service\File\Office\DocumentThumbnailDispatcher;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DocumentRevisionService
{
    public function __construct(
        private DocumentRevisionRepository $revisions,
        private DocumentModelSerializer $serializer,
        private DocumentTextProjector $projector,
        private DocumentRenderer $renderer,
        private DocumentToolsConfig $config,
        private EntityManagerInterface $em,
        private string $uploadDir,
        private ?DocumentThumbnailDispatcher $thumbnails = null,
    ) {
    }

    public function latestFor(File $file): ?DocumentRevision
    {
        return $this->revisions->latestForFile((int) $file->getId());
    }

    /**
     * @return list<DocumentRevision>
     */
    public function listFor(File $file): array
    {
        return $this->revisions->listForFile((int) $file->getId());
    }

    public function append(
        File $file,
        SpreadsheetModel|WordModel|DeckModel $model,
        string $summary,
        string $source = DocumentRevision::SOURCE_MODEL,
        ?string $absolutePath = null,
    ): DocumentRevision {
        $latest = $this->latestFor($file);
        $version = (null === $latest ? 0 : $latest->getVersion()) + 1;
        $json = $this->serializer->encode($model);
        $sha = null;
        $path = $absolutePath ?? $this->absolutePath($file);
        if (is_string($path) && is_file($path)) {
            $sha = hash_file('sha256', $path) ?: null;
        }

        $revision = new DocumentRevision();
        $revision->setFileId((int) $file->getId());
        $revision->setUserId($file->getUserId());
        $revision->setVersion($version);
        $revision->setSchemaVersion(1);
        $revision->setModel($json);
        $revision->setSummary($summary);
        $revision->setSource($source);
        $revision->setBinarySha($sha);
        $revision->setCreated(time());
        $this->em->persist($revision);
        $this->em->flush();

        $file->setFileText($this->projector->project($model));
        $this->em->flush();

        $this->invalidateExportCache($file);
        $this->thumbnails?->dispatchIfNeeded($file);

        $keep = $this->config->keepRevisions();
        $all = $this->revisions->listForFile((int) $file->getId());
        if (count($all) > $keep) {
            $keepVersions = array_map(
                static fn (DocumentRevision $row): int => $row->getVersion(),
                array_slice($all, 0, $keep),
            );
            $this->revisions->pruneExcept((int) $file->getId(), $keepVersions);
        }

        return $revision;
    }

    public function restore(File $file, int $version): ?File
    {
        $revision = $this->revisions->findVersion((int) $file->getId(), $version);
        if (null === $revision || $revision->getUserId() !== $file->getUserId()) {
            return null;
        }
        $model = $this->serializer->decode($revision->getModel());
        $absolute = $this->absolutePath($file);
        if (null === $absolute) {
            return null;
        }
        $this->renderer->render($model, $absolute);
        FileHelper::setFilePermissions($absolute);
        $size = filesize($absolute);
        if (false !== $size) {
            $file->setFileSize($size);
        }
        $this->em->flush();
        $this->append($file, $model, 'Restored version '.$version, DocumentRevision::SOURCE_MODEL, $absolute);
        $this->invalidateExportCache($file);
        $this->thumbnails?->dispatchIfNeeded($file);

        return $file;
    }

    public function deleteForFile(File $file): void
    {
        $this->revisions->deleteForFile((int) $file->getId());
    }

    public function currentBinarySha(File $file): ?string
    {
        $path = $this->absolutePath($file);
        if (null === $path || !is_file($path)) {
            return null;
        }

        return hash_file('sha256', $path) ?: null;
    }

    public function binaryMatchesLatest(File $file): bool
    {
        $latest = $this->latestFor($file);
        if (null === $latest || null === $latest->getBinarySha()) {
            return true;
        }
        $current = $this->currentBinarySha($file);

        return null !== $current && hash_equals($latest->getBinarySha(), $current);
    }

    public function writeBinary(File $file, SpreadsheetModel|WordModel|DeckModel $model): void
    {
        $absolute = $this->absolutePath($file);
        if (null === $absolute) {
            throw new \RuntimeException('File has no on-disk path');
        }
        $this->renderer->render($model, $absolute);
        FileHelper::setFilePermissions($absolute);
        $size = filesize($absolute);
        if (false !== $size) {
            $file->setFileSize($size);
        }
        $this->invalidateExportCache($file);
        $this->thumbnails?->dispatchIfNeeded($file);
    }

    private function absolutePath(File $file): ?string
    {
        $relative = $file->getFilePath();
        if ('' === $relative) {
            return null;
        }

        return $this->uploadDir.'/'.ltrim($relative, '/');
    }

    private function invalidateExportCache(File $file): void
    {
        foreach (DocumentExportService::cachedRelativePaths($file->getFilePath()) as $relative) {
            $export = $this->uploadDir.'/'.ltrim($relative, '/');
            if (is_file($export)) {
                @unlink($export);
            }
        }
    }
}
