<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Entity\File;
use App\Entity\Message;
use App\Repository\FileRepository;

/**
 * The files a conversation has "in hand": what the user attached now, what an
 * upstream node of this turn produced, and everything shared or generated
 * earlier in the thread.
 *
 * Chat used to lose those files. A picture generated three turns ago only
 * hangs off BFILES via BMESSAGEID (never off the message relation), so every
 * consumer that looked at the current message alone saw nothing — which is why
 * "change the colour of that image" produced a brand new picture instead of an
 * edit. This catalog is the single resolver: it walks all three storage
 * channels once, validates every path against the upload root, and hands out
 * entries that {@see ConversationFile} consumers can pass straight to a
 * provider.
 */
final readonly class ConversationFileCatalog
{
    /**
     * Per-category budget, deliberately NOT a global one: a burst of generated
     * documents must never push the picture the user is talking about out of
     * the catalog. Matches DocumentImageCatalog's historical image cap so the
     * document path keeps offering exactly as many images as before.
     */
    public const MAX_FILES_PER_CATEGORY = 8;

    /** Upper bound for the thread lookup before validation and capping. */
    private const THREAD_LOOKUP_LIMIT = 30;

    /** Inventory blocks ride inside an existing prompt budget — keep them short. */
    private const MAX_INVENTORY_ENTRIES = 8;

    public function __construct(
        private FileRepository $fileRepository,
        private string $uploadDir,
    ) {
    }

    /**
     * Build the catalog for one turn.
     *
     * Ordered by how likely the user means them: attachments of the current
     * message, then files produced by an upstream node of the same multitask
     * turn, then the files of the conversation, newest first.
     *
     * @param array<int, Message|array{role: string, content: string}> $thread
     * @param list<string>                                             $extraPaths upload-dir-relative paths produced in this turn (multitask upstream nodes)
     * @param string|null                                              $category   restrict to one CATEGORY_* value
     *
     * @return list<ConversationFile>
     */
    public function build(Message $message, array $thread = [], array $extraPaths = [], ?string $category = null): array
    {
        $userId = (int) $message->getUserId();

        $entries = [];
        $seenIds = [];
        $seenPaths = [];

        foreach ($this->attachments($message) as $index => $file) {
            $this->collect(
                $entries,
                $seenIds,
                $seenPaths,
                $file,
                ConversationFile::ORIGIN_ATTACHED,
                $message->getId(),
                $message->getDirection(),
                $index + 1,
            );
        }

        foreach ($this->filesForPaths($userId, $extraPaths) as $file) {
            $this->collect($entries, $seenIds, $seenPaths, $file, ConversationFile::ORIGIN_GENERATED, $file->getMessageId(), 'OUT');
        }

        foreach ($this->threadFiles($userId, $thread) as $candidate) {
            $this->collect(
                $entries,
                $seenIds,
                $seenPaths,
                $candidate['file'],
                $this->originOf($candidate['file']),
                $candidate['message_id'],
                $candidate['direction'],
            );
        }

        foreach ($this->legacyThreadFiles($thread, $seenPaths) as $legacy) {
            $entries[] = $legacy;
            $seenPaths[$legacy->absolutePath] = true;
        }

        if (null !== $category) {
            $entries = array_values(array_filter(
                $entries,
                static fn (ConversationFile $file): bool => $file->category === $category,
            ));
        }

        return $this->capPerCategory($entries);
    }

    /**
     * @param list<ConversationFile> $catalog
     *
     * @return list<ConversationFile>
     */
    public function imagesOnly(array $catalog): array
    {
        return array_values(array_filter($catalog, static fn (ConversationFile $file): bool => $file->isImage()));
    }

    /**
     * The image the user most likely means: an attachment of the current
     * message when there is one, otherwise the newest image of the thread.
     *
     * @param list<ConversationFile> $catalog
     */
    public function latestImage(array $catalog): ?ConversationFile
    {
        return $this->imagesOnly($catalog)[0] ?? null;
    }

    /**
     * Resolve one entry by the reference the model echoed back. Only references
     * this catalog offered are accepted — an invented one resolves to null
     * instead of reaching a provider.
     *
     * @param list<ConversationFile> $catalog
     */
    public function findByReference(array $catalog, string $reference): ?ConversationFile
    {
        $reference = trim($reference);
        if ('' === $reference) {
            return null;
        }

        foreach ($catalog as $file) {
            if ($file->reference === $reference) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Render the catalog as a compact inventory the sorter/prompt can read.
     * Returns an empty string for an empty catalog: a "no files" instruction
     * only belongs in prompts that offer markers (see DocumentImageCatalog).
     *
     * @param list<ConversationFile> $catalog
     */
    public function renderInventoryBlock(array $catalog): string
    {
        if ([] === $catalog) {
            return '';
        }

        $lines = '';
        foreach (array_slice($catalog, 0, self::MAX_INVENTORY_ENTRIES) as $file) {
            $lines .= '- `'.$file->reference.'` "'.$file->displayName.'" ('
                .$file->category.', '.$this->describeOrigin($file->origin).")\n";
        }

        return "\n\n## Files available in this conversation\n\n".$lines;
    }

    /**
     * Image attachments of a message in marker order — `attached:1` is the
     * first entry. Shared with consumers so offered and accepted markers can
     * never drift apart.
     *
     * @return list<File>
     */
    public function attachments(Message $message): array
    {
        $files = [];
        foreach ($message->getFiles() as $file) {
            $files[] = $file;
        }

        return $files;
    }

    /**
     * Absolute path of a user file inside the upload dir, or null when the file
     * is gone or the stored path escapes the upload root.
     */
    public function absolutePath(File $file): ?string
    {
        return $this->resolvePath($file->getFilePath());
    }

    /**
     * Reduce a stored media path to the upload-dir-relative form BFILES holds:
     * node file descriptors carry the public serve URL, not the raw path.
     */
    public function normalizeRelativePath(string $path): string
    {
        if (1 === preg_match('#^https?://[^/]+(/.*)$#i', $path, $matches)) {
            $path = $matches[1];
        }

        $stripped = preg_replace('#^/?api/v1/files/uploads/#', '', $path);

        return ltrim(null === $stripped ? $path : $stripped, '/');
    }

    /**
     * @param list<ConversationFile> $entries
     * @param array<int, true>       $seenIds
     * @param array<string, true>    $seenPaths
     */
    private function collect(
        array &$entries,
        array &$seenIds,
        array &$seenPaths,
        File $file,
        string $origin,
        ?int $messageId,
        string $direction,
        ?int $attachmentIndex = null,
    ): void {
        $id = $file->getId();
        if (null !== $id && isset($seenIds[$id])) {
            return;
        }

        $path = $this->absolutePath($file);
        if (null === $path || isset($seenPaths[$path])) {
            return;
        }

        if (null === $id && null === $attachmentIndex) {
            return; // no stable reference a consumer could name
        }

        if (null !== $id) {
            $seenIds[$id] = true;
        }
        $seenPaths[$path] = true;

        $relative = ltrim($file->getFilePath(), '/');

        $entries[] = new ConversationFile(
            null !== $id ? 'file:'.$id : 'attached:'.$attachmentIndex,
            $this->displayName($file),
            ConversationFile::categoryForPath($relative),
            $origin,
            $path,
            $relative,
            $id,
            $messageId ?? $file->getMessageId(),
            $direction,
        );
    }

    /**
     * Files of the conversation: attachments carried by the thread messages
     * plus the BFILES rows generated media links to its originating message.
     *
     * @param array<int, Message|array{role: string, content: string}> $thread
     *
     * @return list<array{file: File, message_id: int|null, direction: string}>
     */
    private function threadFiles(int $userId, array $thread): array
    {
        $attached = [];
        $messageIds = [];

        foreach ($thread as $entry) {
            if (!$entry instanceof Message) {
                continue; // {role, content} snapshot inside a media subprocess
            }

            $id = $entry->getId();
            if (null !== $id) {
                $messageIds[] = $id;
            }

            foreach ($entry->getFiles() as $file) {
                $attached[] = ['file' => $file, 'message_id' => $id, 'direction' => $entry->getDirection()];
            }
        }

        $linked = [] === $messageIds
            ? []
            : $this->fileRepository->findFilesByMessageIds($userId, $messageIds, self::THREAD_LOOKUP_LIMIT);

        $all = $attached;
        foreach ($linked as $file) {
            $all[] = ['file' => $file, 'message_id' => $file->getMessageId(), 'direction' => 'OUT'];
        }

        // Newest first: the file the user just talked about wins the budget.
        usort($all, static function (array $a, array $b): int {
            $byMessage = ($b['message_id'] ?? 0) <=> ($a['message_id'] ?? 0);

            return 0 !== $byMessage
                ? $byMessage
                : ($b['file']->getId() ?? 0) <=> ($a['file']->getId() ?? 0);
        });

        return $all;
    }

    /**
     * Generated media of installs that predate GeneratedFileRegistrar: the
     * message carries BFILEPATH but no BFILES row was ever written, so the
     * picture is invisible to every id-based lookup. Synthesised entries have
     * no file id and are therefore referenced by path only.
     *
     * @param array<int, Message|array{role: string, content: string}> $thread
     * @param array<string, true>                                      $seenPaths
     *
     * @return list<ConversationFile>
     */
    private function legacyThreadFiles(array $thread, array $seenPaths): array
    {
        $entries = [];

        $messages = array_values(array_filter($thread, static fn ($entry): bool => $entry instanceof Message));
        for ($i = count($messages) - 1; $i >= 0; --$i) {
            $entry = $messages[$i];
            $storedPath = $entry->getFilePath();
            if ('' === $storedPath) {
                continue;
            }

            $relative = $this->normalizeRelativePath($storedPath);
            $path = $this->resolvePath($relative);
            if (null === $path || isset($seenPaths[$path])) {
                continue;
            }

            $seenPaths[$path] = true;
            $entries[] = new ConversationFile(
                'path:'.$relative,
                basename($relative),
                ConversationFile::categoryForPath($relative),
                'OUT' === $entry->getDirection()
                    ? ConversationFile::ORIGIN_GENERATED
                    : ConversationFile::ORIGIN_UPLOADED,
                $path,
                $relative,
                null,
                $entry->getId(),
                $entry->getDirection(),
            );
        }

        return $entries;
    }

    /**
     * @param list<string> $paths
     *
     * @return list<File>
     */
    private function filesForPaths(int $userId, array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            $relative = $this->normalizeRelativePath($path);
            if ('' === $relative) {
                continue;
            }

            $file = $this->fileRepository->findOneBy(['userId' => $userId, 'filePath' => $relative]);
            if ($file instanceof File) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * @param list<ConversationFile> $entries
     *
     * @return list<ConversationFile>
     */
    private function capPerCategory(array $entries): array
    {
        $counts = [];
        $kept = [];

        foreach ($entries as $entry) {
            $count = $counts[$entry->category] ?? 0;
            if ($count >= self::MAX_FILES_PER_CATEGORY) {
                continue;
            }

            $counts[$entry->category] = $count + 1;
            $kept[] = $entry;
        }

        return $kept;
    }

    private function resolvePath(string $storedPath): ?string
    {
        $uploadRoot = realpath($this->uploadDir);
        $path = realpath($this->uploadDir.'/'.ltrim($storedPath, '/'));
        if (false === $uploadRoot || false === $path || !is_file($path)) {
            return null;
        }

        $rootPrefix = rtrim($uploadRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (!str_starts_with($path, $rootPrefix)) {
            return null;
        }

        return $path;
    }

    private function originOf(File $file): string
    {
        return 'generated' === $file->getSource()
            ? ConversationFile::ORIGIN_GENERATED
            : ConversationFile::ORIGIN_UPLOADED;
    }

    private function describeOrigin(string $origin): string
    {
        return match ($origin) {
            ConversationFile::ORIGIN_ATTACHED => 'attached to the current message',
            ConversationFile::ORIGIN_GENERATED => 'generated earlier in this conversation',
            default => 'shared earlier in this conversation',
        };
    }

    /**
     * Quotes and line breaks would break the single-line list entry a prompt
     * reads, so the name is reduced to a harmless label.
     */
    private function displayName(File $file): string
    {
        $name = $file->getOriginalName() ?? $file->getFileName();
        if ('' === trim($name)) {
            $name = basename($file->getFilePath());
        }

        $clean = preg_replace('/[\p{C}"`]+/u', '', $name) ?? $name;
        $clean = trim($clean);

        return '' === $clean ? 'file' : mb_substr($clean, 0, 80);
    }
}
