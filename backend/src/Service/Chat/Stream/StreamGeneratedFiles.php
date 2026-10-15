<?php

declare(strict_types=1);

namespace App\Service\Chat\Stream;

use App\Entity\File;
use App\Entity\Message;
use App\Service\File\GeneratedDocumentBundle;
use App\Service\File\GeneratedDocumentStore;
use App\Service\File\GeneratedFileWriter;
use App\Service\Media\GeneratedFileRegistrar;
use Psr\Log\LoggerInterface;

/**
 * The files a streamed turn produces: the document a model wrote as a
 * {"BFILEPATH", "BFILETEXT"} envelope, and the output files of a multi-task
 * plan. Incognito turns store them ephemeral, so the session cleanup
 * removes them.
 *
 * Without {@see GeneratedDocumentStore} a document is written by the same
 * {@see GeneratedFileWriter} the buffered chat uses.
 */
final readonly class StreamGeneratedFiles
{
    public function __construct(
        private GeneratedFileWriter $fileWriter,
        private GeneratedFileRegistrar $generatedFileRegistrar,
        private LoggerInterface $logger,
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

        // #1196: whitespace-only content would leave a DB row and a blank
        // file with no usable body. The caller surfaces a real failure.
        if ('' === trim((string) $fileData['content'])) {
            $this->logger->warning('StreamController: refusing to store generated file with empty content', [
                'filename' => $fileData['filename'],
                'extension' => $fileData['extension'],
            ]);

            return null;
        }

        $file = $this->fileWriter->store($fileData, $message, $ephemeral);

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
}
