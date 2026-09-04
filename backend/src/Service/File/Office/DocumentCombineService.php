<?php

declare(strict_types=1);

namespace App\Service\File\Office;

use App\Entity\File;
use App\Entity\User;
use App\Repository\FileRepository;
use App\Service\File\FileHelper;
use App\Service\File\GeneratedDocumentStore;
use App\Service\File\UserUploadPathBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Merge office documents and PDFs into one generated PDF via {@code pdfunite}.
 */
final readonly class DocumentCombineService
{
    public function __construct(
        private DocumentExportService $export,
        private OfficeConverterClient $converter,
        private FileRepository $fileRepository,
        private UserUploadPathBuilder $userUploadPathBuilder,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private string $uploadDir,
        private int $officeCombineMaxFiles = 20,
        private ?DocumentThumbnailDispatcher $documentThumbnailDispatcher = null,
        private string $pdfuniteBinary = '/usr/bin/pdfunite',
    ) {
    }

    /**
     * @param list<int> $fileIds
     */
    public function combineToPdf(User $user, array $fileIds, ?string $filename = null): File
    {
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

        $needsEngine = false;
        foreach ($ordered as $file) {
            $ext = DocumentThumbnailGenerator::extensionOf($file);
            if (DocumentThumbnailGenerator::isOffice($ext)) {
                $needsEngine = true;
            } elseif (!DocumentThumbnailGenerator::isPdf($ext)) {
                throw new DocumentCombineException('unsupported', 'Only office documents and PDFs can be combined', 400);
            }
        }

        if ($needsEngine && !$this->converter->isEnabled()) {
            throw new DocumentCombineException('engine_required', 'Combining office documents needs the office engine', 503);
        }

        $pdfPaths = [];
        foreach ($ordered as $file) {
            $pdf = $this->export->exportToPdf($file);
            if (null === $pdf || !is_file($pdf)) {
                throw new DocumentCombineException('failed', 'Could not export a file to PDF', 500);
            }
            $pdfPaths[] = $pdf;
        }

        $outputName = $this->sanitizeFilename($filename, $ordered);
        $relative = $this->userUploadPathBuilder->buildUserBaseRelativePath($user->getId())
            .'/'.date('Y').'/'.date('m').'/'.$outputName;
        $absolute = $this->uploadDir.'/'.$relative;
        if (!FileHelper::ensureParentDirectory($absolute)) {
            throw new DocumentCombineException('failed', 'Could not create the output directory', 500);
        }

        $this->runPdfunite($pdfPaths, $absolute);

        $size = filesize($absolute);
        if (false === $size || $size < 1) {
            @unlink($absolute);
            throw new DocumentCombineException('failed', 'Combined PDF is empty', 500);
        }
        FileHelper::setFilePermissions($absolute);

        $manifest = $this->manifest($ordered);
        $file = new File();
        $file->setUserId($user->getId());
        $file->setFilePath($relative);
        $file->setFileType('pdf');
        $file->setFileName(self::resolvedDisplayName($filename, $ordered));
        $file->setFileSize($size);
        $file->setFileMime(GeneratedDocumentStore::mimeTypeForExtension('pdf'));
        $file->setFileText($manifest);
        $file->setStatus('generated');
        $file->setSource('generated');
        $file->setOriginKind('document');

        $this->em->persist($file);
        $this->em->flush();
        $this->documentThumbnailDispatcher?->dispatchIfNeeded($file);

        $this->logger->info('DocumentCombineService: combined PDF stored', [
            'file_id' => $file->getId(),
            'inputs' => $ids,
            'bytes' => $size,
        ]);

        return $file;
    }

    /**
     * @param list<string> $inputs
     */
    private function runPdfunite(array $inputs, string $output): void
    {
        if (!is_executable($this->pdfuniteBinary)) {
            throw new DocumentCombineException('failed', 'pdfunite is not available', 500);
        }

        $process = new Process(array_merge([$this->pdfuniteBinary], $inputs, [$output]));
        $process->setTimeout(120);
        $process->run();
        if (!$process->isSuccessful() || !is_file($output)) {
            $this->logger->warning('pdfunite failed', [
                'exit' => $process->getExitCode(),
                'error' => $process->getErrorOutput(),
            ]);
            throw new DocumentCombineException('failed', 'Could not combine the PDFs', 500);
        }
    }

    /**
     * @param list<File> $files
     */
    private function manifest(array $files): string
    {
        $lines = ['# Combined PDF', '', 'Combined from:'];
        foreach ($files as $file) {
            $lines[] = '- '.$file->getFileName();
        }

        return implode("\n", $lines);
    }

    /**
     * Product filename for a combined PDF. An explicit title wins; otherwise
     * the first source stem is used, so neither the file chip's Combine action
     * nor a chat merge persists the bare fallback `combined.pdf` (#1694). Both
     * callers reach this through {@see combineToPdf()}; it is public only to be
     * assertable without an office engine.
     *
     * @param list<File> $sources
     */
    public static function resolvedDisplayName(?string $filename, array $sources = []): string
    {
        $base = pathinfo((string) $filename, PATHINFO_FILENAME);
        $base = trim($base);
        if ('' === $base) {
            $first = $sources[0] ?? null;
            $stem = $first instanceof File ? pathinfo($first->getFileName(), PATHINFO_FILENAME) : '';
            $base = trim($stem);
            if ('' !== $base) {
                $base .= '_combined';
            }
        }
        if ('' === $base) {
            $base = 'combined';
        }

        return $base.'.pdf';
    }

    /**
     * @param list<File> $sources
     */
    private function sanitizeFilename(?string $filename, array $sources = []): string
    {
        $base = pathinfo(self::resolvedDisplayName($filename, $sources), PATHINFO_FILENAME);
        $sanitized = preg_replace('/[^a-zA-Z0-9._-]/', '_', $base) ?? 'combined';

        return $sanitized.'_'.time().'.pdf';
    }
}
