<?php

declare(strict_types=1);

namespace Plugin\SynaScriber\Service;

use App\Service\PluginDataService;

/**
 * Sessions, room pointers and segments in plugin_data, all under the
 * installation (user id 0, like the settings in BCONFIG). The person who
 * started a session is a field of the session, not the row owner.
 *
 * plugin_data stores type and key reduced to [a-z0-9_] but looks them up as
 * given, so every type and key built here is already in that alphabet.
 */
final readonly class SessionStore
{
    public const STORE_OWNER = 0;

    private const PLUGIN = 'synascriber';
    private const TYPE_SESSION = 'session';
    private const TYPE_ROOM = 'room';
    private const SEGMENT_PREFIX = 'seg_';

    public function __construct(
        private PluginDataService $data,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $ref): ?array
    {
        $session = $this->data->get(self::STORE_OWNER, self::PLUGIN, self::TYPE_SESSION, self::sessionKey($ref));
        if (null === $session && 1 === preg_match('/^[a-f0-9]{16}$/', $ref)) {
            $session = $this->data->get(self::STORE_OWNER, self::PLUGIN, self::TYPE_SESSION, $ref);
        }

        return $session;
    }

    /**
     * @param array<string, mixed> $session
     */
    public function save(array $session): void
    {
        $ref = (string) $session['ref'];
        $key = 1 === preg_match('/^[a-f0-9]{16}$/', $ref) ? $ref : self::sessionKey($ref);
        $this->data->set(self::STORE_OWNER, self::PLUGIN, self::TYPE_SESSION, $key, $session);
    }

    public function activeRefForRoom(string $room): ?string
    {
        $pointer = $this->data->get(self::STORE_OWNER, self::PLUGIN, self::TYPE_ROOM, self::roomKey($room));

        return is_array($pointer) && isset($pointer['ref']) ? (string) $pointer['ref'] : null;
    }

    public function markRoom(string $room, string $ref): void
    {
        $this->data->set(self::STORE_OWNER, self::PLUGIN, self::TYPE_ROOM, self::roomKey($room), ['ref' => $ref, 'room' => $room]);
    }

    public function clearRoom(string $room, string $ref): void
    {
        if ($this->activeRefForRoom($room) === $ref) {
            $this->data->delete(self::STORE_OWNER, self::PLUGIN, self::TYPE_ROOM, self::roomKey($room));
        }
    }

    /**
     * One recognised window. The key is fixed by window start, speaker and
     * window number, so a retried window replaces itself.
     *
     * @param array{at: int, speaker: string, text: string, seq: int} $segment
     */
    public function addSegment(string $ref, array $segment): void
    {
        $this->data->set(self::STORE_OWNER, self::PLUGIN, self::segmentType($ref), self::segmentKey($segment), $segment);
    }

    /**
     * @param array{at: int, speaker: string, seq: int} $segment
     *
     * @return array<string, mixed>|null
     */
    public function findSegment(string $ref, array $segment): ?array
    {
        return $this->data->get(self::STORE_OWNER, self::PLUGIN, self::segmentType($ref), self::segmentKey($segment));
    }

    /**
     * @return list<array{at: int, speaker: string, text: string}>
     */
    public function segments(string $ref): array
    {
        $rows = $this->data->listWithKeys(self::STORE_OWNER, self::PLUGIN, self::segmentType($ref));
        usort($rows, static fn (array $a, array $b): int => strcmp($a['key'], $b['key']));

        $segments = [];
        foreach ($rows as $row) {
            $data = $row['data'];
            if (isset($data['at'], $data['speaker'], $data['text'])) {
                $segments[] = ['at' => (int) $data['at'], 'speaker' => (string) $data['speaker'], 'text' => (string) $data['text']];
            }
        }

        return $segments;
    }

    public function deleteSegments(string $ref): void
    {
        $this->data->deleteAllByType(self::STORE_OWNER, self::PLUGIN, self::segmentType($ref));
    }

    /**
     * Sessions the person started or received a copy of.
     *
     * @return list<array<string, mixed>>
     */
    public function forUser(int $userId, int $limit): array
    {
        $sessions = array_values(array_filter(
            $this->data->list(self::STORE_OWNER, self::PLUGIN, self::TYPE_SESSION),
            static fn (array $s): bool => (int) ($s['starterId'] ?? 0) === $userId
                || (is_array($s['files'] ?? null) && array_key_exists($userId, $s['files'])),
        ));
        usort($sessions, static fn (array $a, array $b): int => ($b['startedAt'] ?? 0) <=> ($a['startedAt'] ?? 0));

        return array_slice($sessions, 0, $limit);
    }

    private static function sessionKey(string $ref): string
    {
        return 's_'.substr(hash('sha256', $ref), 0, 40);
    }

    private static function segmentType(string $ref): string
    {
        return self::SEGMENT_PREFIX.(1 === preg_match('/^[a-f0-9]{16}$/', $ref) ? $ref : substr(hash('sha256', $ref), 0, 40));
    }

    /**
     * @param array{at: int, speaker: string, seq?: int} $segment
     */
    private static function segmentKey(array $segment): string
    {
        return sprintf('%013d_%s_%06d', $segment['at'], preg_replace('/[^a-z0-9]/', '_', strtolower($segment['speaker'])), $segment['seq'] ?? 0);
    }

    private static function roomKey(string $room): string
    {
        return 'r_'.substr(hash('sha256', $room), 0, 40);
    }
}
