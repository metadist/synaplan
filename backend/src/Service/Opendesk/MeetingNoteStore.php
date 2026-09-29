<?php

declare(strict_types=1);

namespace App\Service\Opendesk;

/**
 * Saved meeting transcripts for one account. Audio is never stored.
 */
final readonly class MeetingNoteStore
{
    private const MAX_TEXT = 200_000;
    private const MAX_META = 300;

    public function __construct(
        private string $directory,
    ) {
    }

    /**
     * @param array{
     *     source?: mixed,
     *     text?: mixed,
     *     room?: mixed,
     *     folder?: mixed,
     *     language?: mixed,
     *     started_by?: mixed,
     *     meeting_id?: mixed
     * } $input
     *
     * @return array<string, mixed>
     */
    public function save(int $userId, array $input): array
    {
        $source = $input['source'] ?? null;
        if (!is_string($source) || !in_array($source, ['jitsi', 'element'], true)) {
            throw new \InvalidArgumentException('source must be jitsi or element');
        }

        $text = $input['text'] ?? null;
        if (!is_string($text) || '' === trim($text)) {
            throw new \InvalidArgumentException('text is required');
        }
        $text = trim($text);
        if (strlen($text) > self::MAX_TEXT) {
            throw new \InvalidArgumentException('text is too long');
        }

        $note = [
            'id' => 'note_'.bin2hex(random_bytes(8)),
            'source' => $source,
            'text' => $text,
            'markdown' => $this->markdown($text, $source),
            'room' => $this->meta($input['room'] ?? null),
            'folder' => $this->meta($input['folder'] ?? null),
            'language' => $this->meta($input['language'] ?? null),
            'started_by' => $this->meta($input['started_by'] ?? null),
            'meeting_id' => $this->meta($input['meeting_id'] ?? null),
            'created_at' => gmdate('c'),
            'audio_retained' => false,
            'owner_id' => $userId,
        ];

        $dir = $this->userDir($userId);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Meeting notes directory could not be created');
        }

        $written = file_put_contents(
            $dir.'/'.$note['id'].'.json',
            json_encode($note, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE),
            \LOCK_EX,
        );
        if (false === $written) {
            throw new \RuntimeException('Meeting note could not be saved');
        }

        return $note;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $userId, int $limit = 50): array
    {
        $notes = $this->readAll($userId);
        usort($notes, static fn (array $left, array $right): int => strcmp((string) $right['created_at'], (string) $left['created_at']));

        $rows = [];
        foreach (array_slice($notes, 0, max(1, min(50, $limit))) as $note) {
            $text = (string) $note['text'];
            $rows[] = [
                'id' => $note['id'],
                'source' => $note['source'],
                'room' => $note['room'],
                'folder' => $note['folder'],
                'language' => $note['language'],
                'started_by' => $note['started_by'],
                'meeting_id' => $note['meeting_id'],
                'created_at' => $note['created_at'],
                'audio_retained' => false,
                'preview' => mb_strlen($text) > 180 ? mb_substr($text, 0, 177).'...' : $text,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $userId, string $id): ?array
    {
        if (1 !== preg_match('/^note_[a-f0-9]{16}$/', $id)) {
            return null;
        }

        $path = $this->userDir($userId).'/'.$id.'.json';
        if (!is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readAll(int $userId): array
    {
        $dir = $this->userDir($userId);
        if (!is_dir($dir)) {
            return [];
        }

        $notes = [];
        foreach (glob($dir.'/note_*.json') ?: [] as $path) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded) && isset($decoded['id'], $decoded['text'], $decoded['created_at'])) {
                $notes[] = $decoded;
            }
        }

        return $notes;
    }

    private function userDir(int $userId): string
    {
        return rtrim($this->directory, '/').'/'.$userId;
    }

    private function meta(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        if ('' === $trimmed) {
            return null;
        }

        return mb_strlen($trimmed) > self::MAX_META ? mb_substr($trimmed, 0, self::MAX_META) : $trimmed;
    }

    private function markdown(string $text, string $source): string
    {
        $where = 'jitsi' === $source ? 'Jitsi' : 'Element';

        return "# Meeting notes\n\nFrom {$where}.\n\nAudio was not kept.\n\n{$text}\n";
    }
}
