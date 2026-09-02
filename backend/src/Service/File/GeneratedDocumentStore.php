<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Entity\File;
use App\Entity\Message;
use App\Service\File\Office\DocumentThumbnailDispatcher;
use App\Service\File\Office\OfficeConverterClient;
use App\Service\File\Presentation\PptxRequestDirectiveResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Persist an officemaker envelope as a generated {@see File} (and optional PDF).
 *
 * Shared by {@see \App\Service\Message\Handler\ChatHandler},
 * {@see \App\Controller\StreamController} and
 * {@see \App\Service\Multitask\Execution\Runner\DocumentGenerationRunner}.
 */
final readonly class GeneratedDocumentStore
{
    public function __construct(
        private DocumentGeneratorService $documentGenerator,
        private DocumentImageReferenceResolver $documentImageReferenceResolver,
        private UserUploadPathBuilder $userUploadPathBuilder,
        private EntityManagerInterface $em,
        private OfficeConverterClient $converter,
        private LoggerInterface $logger,
        private string $uploadDir,
        private ?DocumentThumbnailDispatcher $documentThumbnailDispatcher = null,
    ) {
    }

    public function pdfExportEnabled(): bool
    {
        return $this->converter->isEnabled();
    }

    /**
     * @param array{filename: string, content: string, extension: string, export?: string} $fileData
     */
    public function store(array $fileData, Message $message, bool $ephemeral = false): ?GeneratedDocumentBundle
    {
        $filename = $fileData['filename'];
        $content = $fileData['content'];
        $extension = $fileData['extension'];

        if ('' === trim((string) $content)) {
            $this->logger->warning('GeneratedDocumentStore: refusing empty content', [
                'filename' => $filename,
                'extension' => $extension,
            ]);

            return null;
        }

        try {
            if ('pptx' === strtolower($extension)) {
                $content = PptxRequestDirectiveResolver::apply($content, (string) $message->getText());
            }

            $resolvedDocument = $this->documentImageReferenceResolver->resolve($content, $message);
            $content = $resolvedDocument['content'];

            $source = $this->persistBinary(
                $message->getUserId(),
                $filename,
                $content,
                $extension,
                $resolvedDocument['images'],
                $ephemeral,
            );
            if (null === $source) {
                return null;
            }

            $export = $this->maybeExportPdf($source, $fileData, $content, $ephemeral);

            return new GeneratedDocumentBundle($source, $export);
        } catch (\Throwable $e) {
            $this->logger->error('GeneratedDocumentStore: failed to store generated file', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param array<string, string> $images
     */
    private function persistBinary(
        int $userId,
        string $filename,
        string $content,
        string $extension,
        array $images,
        bool $ephemeral,
    ): ?File {
        $paths = $this->allocatePath($userId, $filename, $extension);
        if (null === $paths) {
            return null;
        }

        try {
            $this->documentGenerator->write($content, $extension, $paths['absolute'], $images);
        } catch (\Throwable $e) {
            $this->logger->error('GeneratedDocumentStore: failed to write file', [
                'path' => $paths['absolute'],
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $fileSize = filesize($paths['absolute']);
        if (false === $fileSize) {
            $this->logger->error('GeneratedDocumentStore: failed to read generated file size', [
                'path' => $paths['absolute'],
            ]);

            return null;
        }

        $file = $this->newGeneratedFile(
            $userId,
            $paths['relative'],
            $extension,
            $filename,
            $fileSize,
            self::mimeTypeForExtension($extension),
            $content,
            $ephemeral,
        );
        $this->em->persist($file);
        $this->em->flush();
        $this->documentThumbnailDispatcher?->dispatchIfNeeded($file);

        $this->logger->info('GeneratedDocumentStore: file stored', [
            'file_id' => $file->getId(),
            'filename' => $filename,
            'path' => $paths['relative'],
            'size' => $fileSize,
        ]);

        return $file;
    }

    /**
     * @param array{filename: string, content: string, extension: string, export?: string} $fileData
     */
    private function maybeExportPdf(File $source, array $fileData, string $content, bool $ephemeral): ?File
    {
        $wantsPdf = isset($fileData['export']) && 'pdf' === strtolower((string) $fileData['export']);
        if (!$wantsPdf || !$this->converter->isEnabled()) {
            return null;
        }

        $sourceAbsolute = $this->uploadDir.'/'.ltrim($source->getFilePath(), '/');
        $converted = $this->converter->convert($sourceAbsolute, 'pdf');
        if (null === $converted || !is_file($converted)) {
            $this->logger->warning('GeneratedDocumentStore: PDF export failed, keeping source only', [
                'file_id' => $source->getId(),
            ]);

            return null;
        }

        $pdfName = pathinfo($source->getFileName(), PATHINFO_FILENAME);
        $pdfName = ('' !== $pdfName ? $pdfName : 'document').'.pdf';
        $paths = $this->allocatePath($source->getUserId(), $pdfName, 'pdf');
        if (null === $paths) {
            @unlink($converted);

            return null;
        }

        if (!@rename($converted, $paths['absolute']) && !@copy($converted, $paths['absolute'])) {
            $this->logger->warning('GeneratedDocumentStore: failed to move PDF export', [
                'from' => $converted,
                'to' => $paths['absolute'],
            ]);
            @unlink($converted);

            return null;
        }
        if ($converted !== $paths['absolute']) {
            @unlink($converted);
        }
        FileHelper::setFilePermissions($paths['absolute']);

        $fileSize = filesize($paths['absolute']);
        if (false === $fileSize) {
            return null;
        }

        $pdf = $this->newGeneratedFile(
            $source->getUserId(),
            $paths['relative'],
            'pdf',
            $pdfName,
            $fileSize,
            self::mimeTypeForExtension('pdf'),
            $content,
            $ephemeral,
        );
        $this->em->persist($pdf);
        $this->em->flush();
        $this->documentThumbnailDispatcher?->dispatchIfNeeded($pdf);

        $this->logger->info('GeneratedDocumentStore: PDF export stored', [
            'source_id' => $source->getId(),
            'pdf_id' => $pdf->getId(),
            'path' => $paths['relative'],
        ]);

        return $pdf;
    }

    /**
     * @return array{relative: string, absolute: string}|null
     */
    private function allocatePath(int $userId, string $filename, string $extension): ?array
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename) ?? $filename;
        $sanitized = preg_replace('/_+/', '_', $sanitized) ?? $sanitized;
        $basename = pathinfo($sanitized, PATHINFO_FILENAME);
        $finalFilename = $basename.'_'.time().'.'.$extension;

        $userBase = $this->userUploadPathBuilder->buildUserBaseRelativePath($userId);
        $relativePath = $userBase.'/'.date('Y').'/'.date('m').'/'.$finalFilename;
        $absolutePath = $this->uploadDir.'/'.$relativePath;

        if (!FileHelper::ensureParentDirectory($absolutePath)) {
            $this->logger->error('GeneratedDocumentStore: failed to create directory', [
                'dir' => dirname($absolutePath),
            ]);

            return null;
        }

        return ['relative' => $relativePath, 'absolute' => $absolutePath];
    }

    private function newGeneratedFile(
        int $userId,
        string $relativePath,
        string $extension,
        string $filename,
        int $fileSize,
        string $mimeType,
        string $content,
        bool $ephemeral,
    ): File {
        $file = new File();
        $file->setUserId($userId);
        $file->setFilePath($relativePath);
        $file->setFileType($extension);
        $file->setFileName($filename);
        $file->setFileSize($fileSize);
        $file->setFileMime($mimeType);
        $file->setFileText($content);
        $file->setStatus('generated');
        $file->setSource('generated');
        $file->setOriginKind('document');
        $file->setEphemeral($ephemeral);

        return $file;
    }

    public static function mimeTypeForExtension(string $extension): string
    {
        return match (strtolower($extension)) {
            'csv' => 'text/csv',
            'txt' => 'text/plain',
            'md' => 'text/markdown',
            'html' => 'text/html',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'pdf' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }
}
