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
use App\Service\File\UnreadSourceGuard;
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
    /** The answer a channel stores when a document could not be created. */
    private const FILE_GENERATION_FAILED = '__FILE_GENERATION_FAILED__';

    public function __construct(
        private ModelRepository $modelRepository,
        private EntityManagerInterface $em,
        private GeneratedFileWriter $fileWriter,
        private LoggerInterface $logger,
        private ?GeneratedDocumentStore $generatedDocumentStore = null,
        private ?DocumentEditCoordinator $documentEditCoordinator = null,
        private ?UnreadSourceGuard $unreadSourceGuard = null,
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
     * What a buffered channel delivers for a reply: a stored file for a
     * document envelope, an honest refusal when the document would rest on
     * a named source URL that went unread, and the text for everything
     * else. Like the web stream, it never lets a malformed envelope reach
     * the person as a raw blob.
     *
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $classification
     *
     * @return array{0: mixed, 1: array<string, mixed>} the reply and its metadata
     */
    public function deliverBufferedReply(mixed $content, array $metadata, Message $message, string $topic, array $classification, bool $ephemeral): array
    {
        $fileData = is_string($content) ? $this->fileGenerationData($content) : null;
        $refusal = null !== $fileData && null !== $this->unreadSourceGuard
            ? $this->unreadSourceGuard->refusalFor(
                (string) $message->getText(),
                $classification['url_pages_read'] ?? null,
                $classification['language'] ?? 'en',
            )
            : null;

        if (null !== $refusal) {
            // Same guard as DocumentGenerationRunner, which single-node plans
            // bypass via the legacy router (#2050).
            $this->logger->info('ChatHandler: refusing file generation, unread source URL');
            $metadata['document_generation'] = ['created' => false, 'reason' => 'source_unread'];

            return [$refusal, $metadata];
        }

        if (null !== $fileData) {
            $this->logger->info('ChatHandler: Detected AI file generation');

            return $this->attachGeneratedDocument($fileData, $message, $metadata, $ephemeral);
        }

        if ('officemaker' === $topic && is_string($content) && FileGenerationEnvelope::hasSignature($content)) {
            $this->logger->warning('ChatHandler: officemaker reply carried an unparseable file envelope; suppressing raw blob', [
                'text_length' => strlen($content),
            ]);

            return [self::FILE_GENERATION_FAILED, $metadata];
        }

        if (is_string($content) && str_starts_with(trim($content), '{')) {
            return $this->decodeLegacyReply($content, $metadata);
        }

        return [$content, $metadata];
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

    /**
     * Stores the file (ephemeral in incognito mode, so it is cleaned up after
     * the session) and attaches it to the message. A transient incognito
     * message keeps its files in memory only: flush() never touches
     * unmanaged entities.
     *
     * @param array{filename: string, content: string, extension: string, export?: string} $fileData
     * @param array<string, mixed>                                                         $metadata
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function attachGeneratedDocument(array $fileData, Message $message, array $metadata, bool $ephemeral): array
    {
        $bundle = $this->storeDocument($fileData, $message, $ephemeral);
        if (null === $bundle) {
            $this->logger->error('ChatHandler: File generation failed');

            return [self::FILE_GENERATION_FAILED, $metadata];
        }

        foreach ($bundle->files() as $generatedFile) {
            $message->addFile($generatedFile);
        }
        $this->em->flush();

        $primary = $bundle->primary();
        $metadata['generated_file'] = [
            'id' => $primary->getId(),
            'filename' => $primary->getFileName(),
            'path' => $primary->getFilePath(),
            'size' => $primary->getFileSize(),
            'type' => $primary->getFileType(),
        ];

        $this->logger->info('ChatHandler: File generation successful', [
            'file_id' => $primary->getId(),
            'filename' => $primary->getFileName(),
        ]);

        return ["__FILE_GENERATED__:{$primary->getFileName()}", $metadata];
    }

    /**
     * Older answers came as a JSON object: BTEXT is the text, BFILE with
     * BFILETEXT a file, BLINKS the web links. Text that is not valid JSON
     * stays as it is.
     *
     * @param array<string, mixed> $metadata
     *
     * @return array{0: mixed, 1: array<string, mixed>}
     */
    private function decodeLegacyReply(string $content, array $metadata): array
    {
        try {
            $jsonData = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->debug('ChatHandler: Response not JSON', [
                'error' => $e->getMessage(),
            ]);

            return [$content, $metadata];
        }

        if (!is_array($jsonData)) {
            return [$content, $metadata];
        }

        $reply = $content;
        if (isset($jsonData['BTEXT'])) {
            $reply = $jsonData['BTEXT'];
            $this->logger->info('ChatHandler: Extracted BTEXT from legacy JSON response');
        }

        if (!empty($jsonData['BFILE']) && !empty($jsonData['BFILETEXT']) && is_scalar($jsonData['BFILETEXT'])) {
            $metadata['file'] = [
                'path' => $jsonData['BFILETEXT'],
                'type' => $this->fileTypeOf((string) $jsonData['BFILETEXT']),
            ];
            $this->logger->info('ChatHandler: Extracted file data from legacy format', $metadata['file']);
        }

        if (!empty($jsonData['BLINKS'])) {
            $metadata['links'] = $jsonData['BLINKS'];
            $this->logger->info('ChatHandler: Extracted links');
        }

        return [$reply, $metadata];
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
