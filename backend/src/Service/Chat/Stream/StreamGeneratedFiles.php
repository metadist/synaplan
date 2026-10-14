<?php

declare(strict_types=1);

namespace App\Service\Chat\Stream;

use App\Entity\File;
use App\Entity\Message;
use App\Service\File\DocumentGeneratorService;
use App\Service\File\DocumentImageReferenceResolver;
use App\Service\File\GeneratedDocumentBundle;
use App\Service\File\GeneratedDocumentStore;
use App\Service\File\Office\DocumentThumbnailDispatcher;
use App\Service\File\Presentation\PptxRequestDirectiveResolver;
use App\Service\File\UserUploadPathBuilder;
use App\Service\Media\GeneratedFileRegistrar;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The files a streamed turn produces: the document a model wrote as a
 * {"BFILEPATH", "BFILETEXT"} envelope, and the output files of a multi-task
 * plan. Incognito turns store them ephemeral, so the session cleanup
 * removes them.
 */
final readonly class StreamGeneratedFiles
{
    public function __construct(
        private EntityManagerInterface $em,
        private DocumentGeneratorService $documentGenerator,
        private DocumentImageReferenceResolver $documentImageReferenceResolver,
        private UserUploadPathBuilder $userUploadPathBuilder,
        private GeneratedFileRegistrar $generatedFileRegistrar,
        private LoggerInterface $logger,
        #[Autowire(param: 'app.upload_dir')] private string $uploadDir,
        private ?DocumentThumbnailDispatcher $documentThumbnailDispatcher = null,
        private ?GeneratedDocumentStore $generatedDocumentStore = null,
    ) {
    }

    /**
     * @param array{filename: string, content: string, extension: string, export?: string} $fileData
     */
    public function storeDocument(array $fileData, Message $message, bool $ephemeral = false): ?GeneratedDocumentBundle
    {
        if (null !== $this->generatedDocumentStore) {
            return $this->generatedDocumentStore->store($fileData, $message, $ephemeral);
        }

        $file = $this->storeFile($fileData, $message, $ephemeral);

        return null !== $file ? new GeneratedDocumentBundle($file) : null;
    }

    /**
     * The `generating_file` metadata for the conversion step: the export
     * target and the converter, when the document leaves as a PDF.
     *
     * @param array{filename: string, content: string, extension: string, export?: string} $fileEnvelope
     *
     * @return array<string, string>
     */
    public function pdfExportMeta(array $fileEnvelope, Message $incoming): array
    {
        if (null === $this->generatedDocumentStore || !$this->generatedDocumentStore->willExportPdf($fileEnvelope, $incoming)) {
            return [];
        }

        return ['export' => 'pdf', 'tool' => GeneratedDocumentStore::CONVERTER_LABEL];
    }

    /**
     * The output files of a multi-task plan as File rows, so they survive a
     * reload — history serializes only the Message<->File relation, never the
     * legacy BFILE/BFILEPATH columns.
     *
     * The primary file (index 0) also rides the legacy single-file channel,
     * which renders image, video and audio inline on reload; registering
     * those again would duplicate the media. Index 0 is therefore attached
     * only when the legacy channel cannot render it (#1055); extra files
     * always are.
     *
     * @param array<int|string, mixed> $taskFiles metadata['files'] descriptors from the task-plan executor
     *
     * @return list<File>
     */
    public function persistTaskPlanFiles(array $taskFiles, int $userId, bool $ephemeral = false): array
    {
        $entities = [];

        foreach (array_values($taskFiles) as $idx => $taskFile) {
            if (!is_array($taskFile) || empty($taskFile['path'])) {
                continue;
            }

            $type = is_string($taskFile['type'] ?? null) ? $taskFile['type'] : '';
            $sourceText = is_string($taskFile['source_text'] ?? null) ? $taskFile['source_text'] : null;
            $relativePath = is_string($taskFile['local_path'] ?? null) ? $taskFile['local_path'] : null;

            // Still registered for the Generated gallery and the source text
            // (#1251), just not attached to the message.
            $attachToMessage = !(0 === $idx && in_array($type, ['image', 'video', 'audio'], true));

            $entity = $this->generatedFileRegistrar->register(
                $userId,
                $relativePath,
                $type,
                null,
                null,
                ephemeral: $ephemeral,
                fileText: $sourceText,
            );
            if ($entity instanceof File && $attachToMessage) {
                $entities[] = $entity;
            }
        }

        return $entities;
    }

    /**
     * BMESSAGES.BFILETYPE is varchar(8): a short token like "csv" or
     * "audio". A compute artefact carries a MIME instead ("application/json",
     * 16 chars), which overflowed the column and aborted the whole turn —
     * the reply was never saved and the chat hung on "processing". This
     * always fits: the short token as-is, else the file name's extension,
     * else a coarse kind from the MIME.
     *
     * @param array{path?: string, type?: string, name?: string}|null $file
     */
    public static function safeMessageFileType(?array $file): string
    {
        if (null === $file) {
            return '';
        }
        $type = is_string($file['type'] ?? null) ? strtolower(trim($file['type'])) : '';
        if ('' !== $type && !str_contains($type, '/') && mb_strlen($type) <= 8) {
            return $type;
        }
        $name = is_string($file['name'] ?? null) ? $file['name'] : '';
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ('' !== $ext && mb_strlen($ext) <= 8) {
            return $ext;
        }

        return match (true) {
            str_starts_with($type, 'image/') => 'image',
            str_starts_with($type, 'video/') => 'video',
            str_starts_with($type, 'audio/') => 'audio',
            default => 'document',
        };
    }

    /**
     * Same steps as {@see \App\Service\File\GeneratedFileWriter}, which the
     * buffered chat uses.
     *
     * @param array{filename: string, content: string, extension: string, export?: string} $fileData
     */
    private function storeFile(array $fileData, Message $message, bool $ephemeral): ?File
    {
        $filename = $fileData['filename'];
        $extension = $fileData['extension'];

        // #1196: whitespace-only content would leave a DB row and a blank
        // file with no usable body. The caller surfaces a real failure.
        if ('' === trim((string) $fileData['content'])) {
            $this->logger->warning('StreamController: refusing to store generated file with empty content', [
                'filename' => $filename,
                'extension' => $extension,
            ]);

            return null;
        }

        try {
            $written = $this->writeFile($fileData, $message);
            if (null === $written) {
                return null;
            }

            $file = $this->fileEntity($message->getUserId(), $filename, $extension, $written, $ephemeral);

            $this->em->persist($file);
            $this->em->flush();
            $this->documentThumbnailDispatcher?->dispatchIfNeeded($file);

            $this->logger->info('StreamController: File generated and stored successfully', [
                'file_id' => $file->getId(),
                'filename' => $filename,
                'path' => $written['relativePath'],
                'size' => $file->getFileSize(),
            ]);

            return $file;
        } catch (\Throwable $e) {
            $this->logger->error('StreamController: Failed to store generated file', [
                'filename' => $filename,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Writes the document under the person's upload folder: real OOXML for
     * docx/xlsx/pptx, text otherwise.
     *
     * @param array{filename: string, content: string, extension: string, export?: string} $fileData
     *
     * @return array{relativePath: string, size: int, content: string}|null
     */
    private function writeFile(array $fileData, Message $message): ?array
    {
        $content = $fileData['content'];
        $extension = $fileData['extension'];
        if ('pptx' === strtolower($extension)) {
            $content = PptxRequestDirectiveResolver::apply($content, (string) $message->getText());
        }

        $resolvedDocument = $this->documentImageReferenceResolver->resolve($content, $message);
        $content = $resolvedDocument['content'];

        // A timestamp keeps names from colliding.
        $sanitized = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileData['filename']);
        $sanitized = preg_replace('/_+/', '_', (string) $sanitized);
        $finalFilename = pathinfo((string) $sanitized, PATHINFO_FILENAME).'_'.time().'.'.$extension;

        $userBase = $this->userUploadPathBuilder->buildUserBaseRelativePath($message->getUserId());
        $relativePath = $userBase.'/'.date('Y').'/'.date('m').'/'.$finalFilename;
        $absolutePath = $this->uploadDir.'/'.$relativePath;

        $dir = dirname($absolutePath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            $this->logger->error('StreamController: Failed to create directory', ['dir' => $dir]);

            return null;
        }

        try {
            $this->documentGenerator->write($content, $extension, $absolutePath, $resolvedDocument['images']);
        } catch (\Throwable $e) {
            $this->logger->error('StreamController: Failed to write file', [
                'path' => $absolutePath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $fileSize = filesize($absolutePath);
        if (false === $fileSize) {
            $this->logger->error('StreamController: Failed to read generated file size', ['path' => $absolutePath]);

            return null;
        }

        return ['relativePath' => $relativePath, 'size' => $fileSize, 'content' => $content];
    }

    /**
     * @param array{relativePath: string, size: int, content: string} $written
     */
    private function fileEntity(int $userId, string $filename, string $extension, array $written, bool $ephemeral): File
    {
        $file = new File();
        $file->setUserId($userId);
        $file->setFilePath($written['relativePath']);
        $file->setFileType($extension);
        $file->setFileName($filename);
        $file->setFileSize($written['size']);
        $file->setFileMime(self::mimeTypeFor($extension));
        // The source (Markdown/CSV/text) the document was built from, even
        // for binary office formats: it is the text for search, and a later
        // edit transforms exactly this instead of re-deriving it.
        $file->setFileText($written['content']);
        $file->setStatus('generated');
        // #1190: provenance tells generated files from uploads and lets a
        // missing binary be regenerated from BFILETEXT on download.
        $file->setSource('generated');
        // Powers the Generated gallery's type filter (BORIGINKIND).
        $file->setOriginKind('document');
        $file->setEphemeral($ephemeral);

        return $file;
    }

    private static function mimeTypeFor(string $extension): string
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
