<?php

declare(strict_types=1);

namespace App\Service\Message\FileAnalysis;

use App\Entity\File;
use App\Entity\Message;
use App\Service\File\ConversationFile;
use App\Service\File\ConversationFileCatalog;
use App\Service\File\FileTypeResolver;
use App\Service\Message\MessagePreProcessor;

/**
 * The files a file-analysis turn works on, as normalized info rows: the
 * attachments on the current message first, otherwise the conversation
 * catalog, and as a last resort File entities still hanging off history
 * messages.
 *
 * A follow-up like "check the whole contract" has no new upload; looking at
 * the current message only used to answer "No file was provided" although
 * the PDF was still in the chat. Every attachment is returned, not only the
 * first (#978).
 */
final readonly class AnalysisFileResolver
{
    public function __construct(
        private ?ConversationFileCatalog $conversationFileCatalog = null,
    ) {
    }

    /**
     * @param array<int, Message|array{role: string, content: string}> $thread
     *
     * @return array{0: list<array<string, mixed>>, 1: bool} the files, and whether they came from the conversation
     */
    public function resolve(Message $message, array $thread): array
    {
        $fromMessage = $this->filesFromMessage($message);
        if ([] !== $fromMessage) {
            return [$fromMessage, false];
        }

        $fromConversation = $this->filesFromConversation($message, $thread);

        return [$fromConversation, [] !== $fromConversation];
    }

    /**
     * Short, log/metadata-friendly summary of a file collection.
     *
     * @param list<array<string, mixed>> $files
     */
    public static function describe(array $files): string
    {
        return implode(', ', array_map(static fn (array $f): string => (string) ($f['name'] ?? 'unnamed'), $files));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function filesFromMessage(Message $message): array
    {
        $files = $message->getFiles();
        if ($files->count() > 0) {
            $infos = [];
            foreach ($files as $file) {
                $infos[] = $this->fromEntity($file);
            }

            return $infos;
        }

        // Legacy single-file fallback (BMESSAGES.BFILEPATH / BFILETEXT).
        $filePath = $message->getFilePath();
        if ('' === $filePath) {
            return [];
        }

        return [$this->info(null, basename($filePath), strtolower(pathinfo($filePath, PATHINFO_EXTENSION)), $filePath, $message->getFileText(), null)];
    }

    /**
     * @param array<int, Message|array{role: string, content: string}> $thread
     *
     * @return list<array<string, mixed>>
     */
    private function filesFromConversation(Message $message, array $thread): array
    {
        if (null !== $this->conversationFileCatalog) {
            $catalog = $this->conversationFileCatalog->build($message, $thread, [], null, false);
            $picks = $this->conversationFileCatalog->analyzableForFollowUp($catalog);
            if ([] !== $picks) {
                return array_map($this->fromConversationFile(...), $picks);
            }
        }

        return $this->filesFromThreadEntities($thread);
    }

    /**
     * Belt-and-suspenders when the catalog is missing or dropped a file (no
     * on-disk path, no file id). History Message entities still carry the
     * upload via the message relation.
     *
     * @param array<int, Message|array{role: string, content: string}> $thread
     *
     * @return list<array<string, mixed>>
     */
    private function filesFromThreadEntities(array $thread): array
    {
        $infos = [];
        $seen = [];

        foreach ($thread as $entry) {
            if (!$entry instanceof Message) {
                continue;
            }

            foreach ($entry->getFiles() as $file) {
                $id = $file->getId();
                if (null !== $id) {
                    if (isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                }
                $infos[] = $this->fromEntity($file);
            }

            if (!$entry->getFiles()->isEmpty()) {
                continue;
            }

            $legacyPath = $entry->getFilePath();
            if ('' !== $legacyPath) {
                $infos[] = $this->info(null, basename($legacyPath), strtolower(pathinfo($legacyPath, PATHINFO_EXTENSION)), $legacyPath, $entry->getFileText(), null);
            }
        }

        return $infos;
    }

    /**
     * @return array<string, mixed>
     */
    private function fromConversationFile(ConversationFile $file): array
    {
        $extension = strtolower(pathinfo($file->relativePath, PATHINFO_EXTENSION));

        return $this->info(
            $file->fileId,
            $file->displayName,
            '' !== $extension ? $extension : $file->category,
            $file->relativePath,
            $file->extractedText,
            '' !== trim($file->extractedText) ? 'processed' : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fromEntity(File $file): array
    {
        return $this->info($file->getId(), $file->getFileName(), $file->getFileType(), $file->getFilePath(), $file->getFileText(), $file->getStatus());
    }

    /**
     * One normalized row, whichever path it came from.
     *
     * Freshly uploaded files store a concrete extension in BFILETYPE ("png",
     * "pdf", …), the generated-media pipelines a generic kind ("image",
     * "audio", …). The routing flags come from the one canonical resolver
     * (#1236, #1300), so a generated PNG is not mis-routed to "unsupported".
     *
     * @return array<string, mixed>
     */
    private function info(?int $id, ?string $name, ?string $type, ?string $rawPath, ?string $text, ?string $status): array
    {
        $path = $this->normalizeRelativeUploadPath((string) $rawPath);
        $resolvedName = (string) ($name ?? basename($path));
        $resolvedType = FileTypeResolver::resolveExtension((string) $type, $resolvedName, $path);

        return [
            'id' => $id,
            'name' => $resolvedName,
            'type' => (string) $type,
            'path' => $path,
            'text' => (string) ($text ?? ''),
            'status' => $status,
            'is_image' => in_array($resolvedType, MessagePreProcessor::IMAGE_EXTENSIONS, true),
            'is_audio' => in_array($resolvedType, MessagePreProcessor::AUDIO_EXTENSIONS, true),
            'is_document' => in_array($resolvedType, MessagePreProcessor::DOCUMENT_EXTENSIONS, true),
            'is_video' => in_array($resolvedType, MessagePreProcessor::VIDEO_EXTENSIONS, true),
        ];
    }

    /**
     * Any stored or received file path, relative to var/uploads:
     * "uploads/13/…", "/var/www/backend/var/uploads/13/…",
     * "/api/v1/files/uploads/13/…" and "/up/13/…" all become "13/…".
     */
    private function normalizeRelativeUploadPath(string $path): string
    {
        $path = trim($path);
        $path = preg_replace('#^https?://[^/]+/#', '', $path) ?? $path;
        $path = ltrim($path, '/');

        if (str_starts_with($path, 'api/v1/files/uploads/')) {
            $path = substr($path, strlen('api/v1/files/uploads/'));
        }
        if (str_starts_with($path, 'up/')) {
            $path = substr($path, strlen('up/'));
        }
        if (str_contains($path, '/uploads/')) {
            $path = substr($path, strpos($path, '/uploads/') + strlen('/uploads/'));
        }
        if (str_starts_with($path, 'uploads/')) {
            $path = substr($path, strlen('uploads/'));
        }

        return ltrim($path, '/');
    }
}
