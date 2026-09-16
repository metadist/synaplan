<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Entity\File;
use App\Entity\User;
use App\Repository\FileRepository;
use App\Service\Document\Import\DocumentImporter;
use App\Service\Document\Model\DeckModel;
use App\Service\Document\Model\SpreadsheetModel;
use App\Service\Document\Model\WordModel;
use App\Service\Document\Persist\DocumentRevisionService;
use App\Service\Document\Persist\DocumentTextProjector;
use App\Service\Document\Render\DocumentRenderer;
use App\Service\File\FileHelper;
use App\Service\File\GeneratedDocumentStore;
use App\Service\File\Office\DocumentCombineException;
use App\Service\File\UserUploadPathBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Combine same-kind office files into one editable document (B7).
 */
final readonly class DocumentOfficeMergeService
{
    public function __construct(
        private FileRepository $fileRepository,
        private DocumentImporter $importer,
        private DocumentModelMerger $merger,
        private DocumentRenderer $renderer,
        private DocumentTextProjector $projector,
        private DocumentRevisionService $revisions,
        private UserUploadPathBuilder $userUploadPathBuilder,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private string $uploadDir,
        private int $officeCombineMaxFiles = 20,
    ) {
    }

    /**
     * @param list<int> $fileIds
     */
    public function combine(User $user, array $fileIds, string $format, ?string $filename = null): File
    {
        $format = strtolower($format);
        if (!DocumentKind::isKnown($format)) {
            throw new DocumentCombineException('unsupported', 'Unsupported combine format', 400);
        }
        $ids = array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $fileIds)));
        if (count($ids) < 2) {
            throw new DocumentCombineException('too_few', 'Select at least two files to combine', 400);
        }
        if (count($ids) > $this->officeCombineMaxFiles) {
            throw new DocumentCombineException('too_many', 'Too many files to combine', 400);
        }

        $found = $this->fileRepository->findByUserAndIds($user->getId(), $ids);
        $byId = [];
        foreach ($found as $file) {
            $byId[$file->getId()] = $file;
        }
        $ordered = [];
        foreach ($ids as $id) {
            if (!isset($byId[$id])) {
                throw new DocumentCombineException('not_found', 'One or more files were not found', 404);
            }
            $ordered[] = $byId[$id];
        }

        $base = $this->importModel($ordered[0], $format);
        foreach (array_slice($ordered, 1) as $file) {
            $this->merger->merge($base, $this->importModel($file, $format));
        }

        $display = $this->displayName($filename, $format);
        $sanitized = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($display, PATHINFO_FILENAME)) ?? 'combined';
        $relative = $this->userUploadPathBuilder->buildUserBaseRelativePath($user->getId())
            .'/'.date('Y').'/'.date('m').'/'.$sanitized.'_'.time().'.'.$format;
        $absolute = $this->uploadDir.'/'.$relative;
        if (!FileHelper::ensureParentDirectory($absolute)) {
            throw new DocumentCombineException('failed', 'Could not create the output directory', 500);
        }
        $this->renderer->render($base, $absolute);
        FileHelper::setFilePermissions($absolute);
        $size = filesize($absolute);
        if (false === $size || $size < 1) {
            @unlink($absolute);
            throw new DocumentCombineException('failed', 'Combined file is empty', 500);
        }

        $out = new File();
        $out->setUserId($user->getId());
        $out->setFilePath($relative);
        $out->setFileType($format);
        $out->setFileName($display);
        $out->setFileSize($size);
        $out->setFileMime(GeneratedDocumentStore::mimeTypeForExtension($format));
        $out->setFileText($this->projector->project($base));
        $out->setStatus('generated');
        $out->setSource('generated');
        $out->setOriginKind('document');
        $this->em->persist($out);
        $this->em->flush();
        $this->revisions->append($out, $base, 'Combined from '.count($ordered).' files');

        $this->logger->info('DocumentOfficeMergeService: combined office file stored', [
            'file_id' => $out->getId(),
            'format' => $format,
            'inputs' => $ids,
        ]);

        return $out;
    }

    private function importModel(File $file, string $format): SpreadsheetModel|WordModel|DeckModel
    {
        $absolute = $this->uploadDir.'/'.ltrim($file->getFilePath(), '/');
        $ext = $file->getFileType() ?: pathinfo($file->getFileName(), PATHINFO_EXTENSION);
        $kind = DocumentKind::fromExtension((string) $ext);
        if ($format !== $kind) {
            throw new DocumentCombineException('unsupported', 'All files must be the same type', 400);
        }
        $imported = $this->importer->import($absolute, (string) $ext);
        if (null === $imported) {
            throw new DocumentCombineException('failed', 'Could not read '.$file->getFileName(), 500);
        }

        return $imported['model'];
    }

    private function displayName(?string $filename, string $format): string
    {
        $base = trim(pathinfo((string) $filename, PATHINFO_FILENAME));
        if ('' === $base) {
            $base = 'combined';
        }

        return $base.'.'.$format;
    }
}
