<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Entity\File;
use App\Entity\Message;
use App\Entity\Model;
use App\Prompt\PromptCatalog;
use App\Repository\ModelRepository;
use App\Service\File\FileGenerationEnvelope;
use App\Service\File\GeneratedDocumentBundle;
use App\Service\File\GeneratedDocumentStore;
use App\Service\File\GeneratedFileWriter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The document side of a chat turn (the officemaker topic): the hints
 * the system prompt gets, editing an existing office file with the
 * document tools, and storing the file a model wrote as a
 * {"BFILEPATH", "BFILETEXT"} envelope.
 */
final readonly class ChatDocumentOutput
{
    public function __construct(
        private ModelRepository $modelRepository,
        private EntityManagerInterface $em,
        private GeneratedFileWriter $fileWriter,
        private LoggerInterface $logger,
        private ?GeneratedDocumentStore $generatedDocumentStore = null,
        private ?DocumentEditCoordinator $documentEditCoordinator = null,
    ) {
    }

    public function withPdfExportHint(string $systemPrompt, string $topic): string
    {
        if ('officemaker' !== $topic) {
            return $systemPrompt;
        }
        if (null === $this->generatedDocumentStore || !$this->generatedDocumentStore->pdfExportEnabled()) {
            return $systemPrompt;
        }

        return $systemPrompt.PromptCatalog::officeMakerPdfExportAppendix();
    }

    public function withToolsHint(string $systemPrompt, string $topic, ?int $modelId, Message $message): string
    {
        if ('officemaker' !== $topic || null === $this->documentEditCoordinator || null === $modelId) {
            return $systemPrompt;
        }
        $model = $this->modelRepository->find($modelId);
        if (!$this->documentEditCoordinator->shouldRun($topic, $model, $message)) {
            return $systemPrompt;
        }

        return $systemPrompt.PromptCatalog::officeMakerToolsAppendix();
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $aiOptions
     */
    public function tryToolsEdit(
        string $topic,
        ?int $modelId,
        array $messages,
        Message $message,
        array $aiOptions,
        ?callable $progressCallback,
        bool $ephemeral,
    ): ?DocumentEditResult {
        if (null === $this->documentEditCoordinator || null === $modelId) {
            return null;
        }
        $model = $this->modelRepository->find($modelId);
        if (!$this->documentEditCoordinator->shouldRun($topic, $model, $message)) {
            return null;
        }
        $progress = null;
        if (null !== $progressCallback) {
            $progress = static function (string $status, string $label, array $meta = []) use ($progressCallback): void {
                $progressCallback([
                    'status' => $status,
                    'message' => $label,
                    'metadata' => $meta,
                    'timestamp' => time(),
                ]);
            };
        }
        $result = $this->documentEditCoordinator->run($messages, $message, $aiOptions, $progress, $ephemeral);
        if (null === $result) {
            return null;
        }
        if ('' === trim($result->content) && null === $result->file) {
            return null;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function applyEditResult(DocumentEditResult $result, Message $message, array &$metadata): string
    {
        if (null !== $result->file) {
            $message->addFile($result->file);
            $this->em->flush();
            $metadata['generated_file'] = [
                'id' => $result->file->getId(),
                'filename' => $result->file->getFileName(),
                'path' => $result->file->getFilePath(),
                'size' => $result->file->getFileSize(),
                'type' => $result->file->getFileType(),
                'mime' => $result->file->getFileMime(),
            ];
        }
        $metadata['documentChanges'] = array_map(
            static fn ($step): array => [
                'labelKey' => $step->labelKey,
                'labelParams' => $step->labelParams,
                'ok' => $step->ok,
            ],
            $result->steps,
        );
        if (null !== $result->version) {
            $metadata['documentVersion'] = $result->version;
        }
        $metadata['documentFidelityLossy'] = $result->fidelityLossy;

        return $result->content;
    }

    /**
     * Parse AI response and extract file generation data if present
     * Format: { "BFILEPATH": "filename.ext", "BFILETEXT": "file content" }
     * Also handles JSON wrapped in markdown code blocks: ```json ... ```.
     *
     * @return array|null ['filename' => string, 'content' => string, 'extension' => string] or null
     */
    public function fileGenerationData(string $content): ?array
    {
        // Tolerate a prose preamble or markdown fence around the envelope: the
        // model sometimes prepends a sentence before the {"BFILEPATH",…} object,
        // which previously skipped file generation and leaked the raw blob into
        // the chat (#1406).
        $fileData = FileGenerationEnvelope::extract($content);
        if (null === $fileData) {
            return null;
        }

        $this->logger->info('ChatHandler: Detected file generation', [
            'filename' => $fileData['filename'],
            'extension' => $fileData['extension'],
            'content_length' => strlen($fileData['content']),
        ]);

        return $fileData;
    }

    /**
     * @param array{filename: string, content: string, extension: string, export?: string} $fileData
     */
    public function storeDocument(array $fileData, Message $message, bool $ephemeral = false): ?GeneratedDocumentBundle
    {
        if (null !== $this->generatedDocumentStore) {
            return $this->generatedDocumentStore->store($fileData, $message, $ephemeral);
        }

        $file = $this->fileWriter->store($fileData, $message, $ephemeral);

        return null !== $file ? new GeneratedDocumentBundle($file) : null;
    }

    public function fileTypeOf(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
        $videoExtensions = ['mp4', 'webm', 'ogg', 'mov', 'avi'];
        $audioExtensions = ['mp3', 'wav', 'ogg', 'flac'];
        $docExtensions = ['pdf', 'doc', 'docx', 'txt', 'xlsx', 'pptx'];

        if (in_array($extension, $imageExtensions)) {
            return 'image';
        }
        if (in_array($extension, $videoExtensions)) {
            return 'video';
        }
        if (in_array($extension, $audioExtensions)) {
            return 'audio';
        }
        if (in_array($extension, $docExtensions)) {
            return 'document';
        }

        return 'file';
    }
}
