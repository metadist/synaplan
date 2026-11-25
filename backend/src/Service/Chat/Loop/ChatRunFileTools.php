<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\AI\Messages\Tools\AnalyzeImageTool;
use App\Entity\File;
use App\Repository\ChatFileRepository;
use App\Service\File\ConversationFile;
use App\Service\File\ConversationFileCatalog;

/**
 * Files of the chat by ID, for the whole chat (03 §3, §0 #28): `list_files`
 * lists every file of the chat and its project's sources, `read_document`
 * reads one's text, `analyze_image` has the vision model look at one
 * picture.
 *
 * A file ID resolves only when the file is in this chat or in the project
 * the chat is in now, and belongs to the person. Image data never goes into
 * the transcript, only text about it.
 */
final readonly class ChatRunFileTools
{
    public const LIST_FILES = 'list_files';
    public const READ_DOCUMENT = 'read_document';
    public const ANALYZE_IMAGE = 'analyze_image';
    public const NAMES = [self::LIST_FILES, self::READ_DOCUMENT, self::ANALYZE_IMAGE];

    /** Pictures the vision model may look at in one run; each call also counts against the run's cost limit. */
    public const MAX_PICTURES = 6;

    public const NOT_IN_CHAT = 'There is no file with this file_id in this chat. Use list_files to find it.';
    public const NO_TEXT = 'This file has no readable text. For a picture, use analyze_image.';
    public const NOT_A_PICTURE = 'This file is not a picture. Use read_document for its text.';
    public const PICTURES_USED = 'The pictures for this answer are used up. Work with what you already know about them.';
    public const MISSING = 'The file is no longer stored, so it cannot be looked at.';

    private const PAGE = 20;
    private const SLICE = 8_000;
    private const DESCRIPTION_CHARS = 120;
    /** Text files a run reads from disk when no text was extracted (a generated report, say). */
    private const PLAIN = ['md', 'txt', 'csv', 'json'];
    private const PLAIN_BYTES = 1_000_000;

    public function __construct(
        private ChatFileRepository $files,
        private ConversationFileCatalog $catalog,
        private AnalyzeImageTool $vision,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function declarations(ChatRunScope $scope): array
    {
        $out = [
            ChatLoopTools::fn(self::LIST_FILES, 'List the files of this chat and of its project: file_id, name, kind, where it came from, date and a short description. Newest first, 20 per page.', [
                'page' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Page to show, from 1.'],
            ], []),
            ChatLoopTools::fn(self::READ_DOCUMENT, 'Read the text of one file of this chat by its file_id from list_files. Long files come in parts; pass offset to read on.', [
                'file_id' => ['type' => 'integer', 'description' => 'file_id from list_files.'],
                'offset' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Character to start at. Omit to read from the start.'],
            ], ['file_id']),
        ];
        if ($this->vision->isAvailable((int) $scope->user->getId())) {
            $out[] = ChatLoopTools::fn(self::ANALYZE_IMAGE, 'Have the vision model look at one picture of this chat by its file_id: describe it or read its text.', [
                'file_id' => ['type' => 'integer', 'description' => 'file_id of a picture from list_files.'],
                'prompt' => ['type' => 'string', 'description' => 'What to look for. Omit for a full description.'],
            ], ['file_id']);
        }

        return $out;
    }

    /** @param array<string, mixed> $args */
    public function run(ChatRunScope $scope, ChatRunState $state, string $name, array $args): ChatToolResult
    {
        return match ($name) {
            self::LIST_FILES => $this->list($scope, $args),
            self::READ_DOCUMENT => $this->read($scope, $args),
            default => $this->look($scope, $state, $args),
        };
    }

    /** @param array<string, mixed> $args */
    private function list(ChatRunScope $scope, array $args): ChatToolResult
    {
        [$userId, $chatId, $groupKey] = $this->where($scope);
        $total = $this->files->total($userId, $chatId, $groupKey);
        if (0 === $total) {
            return ChatToolResult::text('This chat has no files.');
        }
        $pages = (int) ceil($total / self::PAGE);
        $page = min($pages, max(1, self::int($args['page'] ?? null) ?? 1));
        $lines = ["Files of this chat (page {$page} of {$pages}, {$total} in all):"];
        foreach ($this->files->page($userId, $chatId, $groupKey, ($page - 1) * self::PAGE, self::PAGE) as $file) {
            $lines[] = $this->line($file);
        }

        return ChatToolResult::text(implode("\n", $lines));
    }

    /** @param array<string, mixed> $args */
    private function read(ChatRunScope $scope, array $args): ChatToolResult
    {
        $file = $this->resolve($scope, $args);
        if (null === $file) {
            return ChatToolResult::failed(self::NOT_IN_CHAT);
        }
        $text = str_replace("\r\n", "\n", $this->text($file));
        $length = mb_strlen($text);
        if ('' === trim($text)) {
            return ChatToolResult::text(self::NO_TEXT);
        }
        $offset = min(max(0, self::int($args['offset'] ?? null) ?? 0), $length);
        $end = min($length, $offset + self::SLICE);
        $head = 'File: '.$this->name($file)."\nfile_id: ".$file->getId()."\nCharacters {$offset}–{$end} of {$length}.";
        if ($end < $length) {
            $head .= " Pass offset {$end} to read on.";
        }

        return ChatToolResult::text($head."\n\n".mb_substr($text, $offset, $end - $offset));
    }

    /** @param array<string, mixed> $args */
    private function look(ChatRunScope $scope, ChatRunState $state, array $args): ChatToolResult
    {
        $file = $this->resolve($scope, $args);
        if (null === $file) {
            return ChatToolResult::failed(self::NOT_IN_CHAT);
        }
        $relative = $this->catalog->normalizeRelativePath($file->getFilePath());
        if (ConversationFile::CATEGORY_IMAGE !== ConversationFile::categoryForPath($relative)) {
            return ChatToolResult::failed(self::NOT_A_PICTURE);
        }
        $id = (int) $file->getId();
        if (!\in_array($id, $state->analyzed, true) && \count($state->analyzed) >= self::MAX_PICTURES) {
            return ChatToolResult::failed(self::PICTURES_USED);
        }
        if (null === $this->catalog->absolutePath($file)) {
            return ChatToolResult::failed(self::MISSING);
        }
        if (!\in_array($id, $state->analyzed, true)) {
            $state->analyzed[] = $id;
        }
        $result = $this->vision->analyseStored($relative, $args['prompt'] ?? null, $state->userId, $state->locale);
        $state->costMicro += FacadeChatRunModel::micro($result['chargedCost'] ?? null);

        return $result['isError']
            ? ChatToolResult::failed($result['text'])
            : ChatToolResult::text('Picture: '.$this->name($file)."\nfile_id: {$id}\n\n".$result['text']);
    }

    /** The extracted text, or for a plain text file without one, its first megabyte from disk. */
    private function text(File $file): string
    {
        $text = $file->getFileText();
        if ('' !== trim($text) || !\in_array(strtolower(pathinfo($file->getFilePath(), \PATHINFO_EXTENSION)), self::PLAIN, true)) {
            return $text;
        }
        $path = $this->catalog->absolutePath($file);
        $read = null !== $path ? @file_get_contents($path, false, null, 0, self::PLAIN_BYTES) : false;

        return false === $read ? '' : mb_scrub($read, 'UTF-8');
    }

    /** @param array<string, mixed> $args */
    private function resolve(ChatRunScope $scope, array $args): ?File
    {
        $fileId = self::int($args['file_id'] ?? null);
        if (null === $fileId || $fileId < 1) {
            return null;
        }
        [$userId, $chatId, $groupKey] = $this->where($scope);

        return $this->files->findInChat($userId, $chatId, $groupKey, $fileId);
    }

    /** A model may send a number as a string; anything else that is not a whole number is null. */
    private static function int(mixed $value): ?int
    {
        if (\is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return \is_int($value) ? $value : null;
    }

    /** @return array{int, int, ?string} */
    private function where(ChatRunScope $scope): array
    {
        return [(int) $scope->user->getId(), (int) $scope->chat->getId(), $scope->project?->groupKey];
    }

    private function line(File $file): string
    {
        $kind = ConversationFile::categoryForPath($this->catalog->normalizeRelativePath($file->getFilePath()));
        $from = match (true) {
            'generated' === $file->getSource() => 'made in this chat',
            str_starts_with($file->getSource(), 'project_') => 'project source',
            default => 'uploaded',
        };
        $line = '- file_id '.$file->getId().' · '.$this->name($file).' · '.$kind.' · '.$from;
        if ($file->getCreatedAt() > 0) {
            $line .= ' · '.date('Y-m-d', $file->getCreatedAt());
        }
        $about = trim((string) preg_replace('/\s+/u', ' ', $file->getFileText()));
        if ('' !== $about) {
            $line .= ' · '.(mb_strlen($about) > self::DESCRIPTION_CHARS ? mb_substr($about, 0, self::DESCRIPTION_CHARS).'…' : $about);
        }

        return $line;
    }

    private function name(File $file): string
    {
        $name = trim($file->getFileName());

        return '' !== $name ? $name : basename($file->getFilePath());
    }
}
