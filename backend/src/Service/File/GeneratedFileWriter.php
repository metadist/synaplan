<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Entity\File;
use App\Entity\Message;
use App\Service\File\Office\DocumentThumbnailDispatcher;
use App\Service\File\Presentation\PptxRequestDirectiveResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Writes one generated document to the uploads folder and records it.
 *
 * The fallback for callers without {@see GeneratedDocumentStore}, which
 * is the production path (office conversion, PDF export, bundles).
 */
final readonly class GeneratedFileWriter
{
    public function __construct(
        private DocumentGeneratorService $documentGenerator,
        private DocumentImageReferenceResolver $documentImageReferenceResolver,
        private UserUploadPathBuilder $userUploadPathBuilder,
        #[Autowire(param: 'app.upload_dir')] private string $uploadDir,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private ?DocumentThumbnailDispatcher $documentThumbnailDispatcher = null,
    ) {
    }

    /**
     * Store AI-generated file in the file system and create File entity.
     *
     * @param array   $fileData ['filename' => string, 'content' => string, 'extension' => string]
     * @param Message $message  The message that triggered the generation
     *
     * @return File|null The created File entity or null on error
     */
    public function store(array $fileData, Message $message, bool $ephemeral = false): ?File
    {
        $userId = $message->getUserId();
        $filename = $fileData['filename'];
        $content = $fileData['content'];
        $extension = $fileData['extension'];

        try {
            if ('pptx' === strtolower($extension)) {
                $content = PptxRequestDirectiveResolver::apply($content, (string) $message->getText());
            }

            $resolvedDocument = $this->documentImageReferenceResolver->resolve($content, $message);
            $content = $resolvedDocument['content'];

            $relativePath = $this->relativePath($userId, $filename, $extension);
            $absolutePath = $this->uploadDir.'/'.$relativePath;

            // Create directory if not exists
            $dir = dirname($absolutePath);
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0755, true)) {
                    $this->logger->error('ChatHandler: Failed to create directory', ['dir' => $dir]);

                    return null;
                }
            }

            // Write file content (real OOXML for docx/xlsx/pptx, text otherwise)
            try {
                $this->documentGenerator->write($content, $extension, $absolutePath, $resolvedDocument['images']);
            } catch (\Throwable $e) {
                $this->logger->error('ChatHandler: Failed to write file', [
                    'path' => $absolutePath,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }

            $fileSize = filesize($absolutePath);
            if (false === $fileSize) {
                $this->logger->error('ChatHandler: Failed to read generated file size', ['path' => $absolutePath]);

                return null;
            }

            // Detect MIME type
            $mimeType = $this->getMimeTypeForExtension($extension);

            // Create File entity
            $file = new File();
            $file->setUserId($userId);
            $file->setFilePath($relativePath);
            $file->setFileType($extension);
            $file->setFileName($filename);
            $file->setFileSize($fileSize);
            $file->setFileMime($mimeType);
            // Persist the source content (Markdown/CSV/text) the document was
            // built from — even for binary office formats. It is the document's
            // text for search and, crucially, lets a later edit transform the
            // exact current content instead of re-deriving it.
            $file->setFileText($content);
            $file->setStatus('generated');
            // Issue #1190 parity with StreamController::storeGeneratedFile:
            // mark provenance so the artefact shows in the Generated gallery
            // (/files/generated filters BSOURCE) instead of posing as an upload.
            $file->setSource('generated');
            $file->setOriginKind('document');
            $file->setEphemeral($ephemeral);

            $this->em->persist($file);
            $this->em->flush();
            $this->documentThumbnailDispatcher?->dispatchIfNeeded($file);

            $this->logger->info('ChatHandler: File generated and stored successfully', [
                'file_id' => $file->getId(),
                'filename' => $filename,
                'path' => $relativePath,
                'size' => $file->getFileSize(),
            ]);

            return $file;
        } catch (\Throwable $e) {
            $this->logger->error('ChatHandler: Failed to store generated file', [
                'filename' => $filename,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Get MIME type for file extension.
     */
    private function getMimeTypeForExtension(string $extension): string
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

    /**
     * `<user base>/<year>/<month>/<sanitized name>_<timestamp>.<extension>`,
     * the layout FileStorageService uses for uploads. The timestamp keeps two
     * documents with the same name apart.
     */
    private function relativePath(int $userId, string $filename, string $extension): string
    {
        $year = date('Y');
        $month = date('m');
        $timestamp = time();

        $sanitized = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        $sanitized = preg_replace('/_+/', '_', $sanitized);

        $basename = pathinfo($sanitized, PATHINFO_FILENAME);
        $finalFilename = $basename.'_'.$timestamp.'.'.$extension;

        $userBase = $this->userUploadPathBuilder->buildUserBaseRelativePath($userId);

        return $userBase.'/'.$year.'/'.$month.'/'.$finalFilename;
    }
}
