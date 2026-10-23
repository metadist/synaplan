<?php

declare(strict_types=1);

namespace CloudFixture;

/**
 * The app side of Copy to cloud (CF3.1): claim the copy, download it, write
 * it into the person's files, report the result. The same result is the
 * answer to the notification, as an app that finished inside the request.
 */
final class Deliveries
{
    /**
     * `kind_folder`: the app picks the folder by the kind of file, the folders
     * Synaplan's copy menu names (`frontend/src/services/cloudFolderTargets.ts`).
     */
    private const KIND_FOLDERS = [
        'Synaplan/Images' => ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'],
        'Synaplan/Audio' => ['mp3', 'wav', 'ogg', 'm4a', 'flac'],
        'Synaplan/Video' => ['mp4', 'webm', 'mov'],
        'Synaplan/Calendar' => ['ics'],
    ];
    private const DOCUMENTS = 'Synaplan/Documents';

    public function __construct(
        private readonly Store $store,
        private readonly Synaplan $synaplan,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(string $id): array
    {
        $base = '/api/v1/platform-links/deliveries/'.rawurlencode($id);
        $claim = $this->synaplan->json('POST', $base.'/claim');
        if (200 !== $claim['status']) {
            // Not ours to finish: another worker has it, or it expired.
            return ['status' => 'failed', 'error_code' => 'unreachable', 'claim' => $claim['body']['error'] ?? $claim['status']];
        }
        $copy = $claim['body'];
        $person = (string) ($copy['external_id'] ?? '');
        $bytes = $this->synaplan->download((string) ($copy['download_path'] ?? ''));
        if (null === $bytes) {
            return $this->finish($base, ['status' => 'failed', 'error_code' => 'unreachable']);
        }
        $state = $this->store->state();
        $profile = (string) $state['profile'];
        $name = (string) ($copy['name'] ?? 'file');
        $folder = $this->folder($person, $profile, is_array($copy['target'] ?? null) ? $copy['target'] : [], $name);
        if (null === $folder) {
            return $this->finish($base, ['status' => 'failed', 'error_code' => 'not_found']);
        }
        // Choosing the name and writing the file are one step: two copies of
        // "report.pdf" at once must end as two files.
        $path = $this->store->locked('files-'.$person, function () use ($person, $folder, $name, $copy, $bytes, $id): string {
            $path = $this->place($person, $folder, $name, (string) ($copy['on_conflict'] ?? 'rename'));
            $this->store->put($person, $path, $bytes);
            $this->store->recordWritten(['person' => $person, 'path' => $path, 'delivery' => $id, 'size' => strlen($bytes)]);

            return $path;
        });

        return $this->finish($base, [
            'status' => 'delivered',
            'remote_path' => '/'.$path,
            'remote_id' => Profile::remoteId($profile, $person, $path),
            'open_url' => $state['fixture_url'].'/files/'.rawurlencode($person).'/'.implode('/', array_map('rawurlencode', explode('/', $path))),
        ]);
    }

    /**
     * Where a copy goes: the folder a remote id names (`folder_id`,
     * `next_to`), the person's own root (`personal_folder`), or the kind
     * folder for the file (`kind_folder`, the default). Null for an unknown id.
     *
     * @param array<mixed> $target
     */
    private function folder(string $person, string $profile, array $target, string $name): ?string
    {
        $ref = is_string($target['ref'] ?? null) ? $target['ref'] : '';

        return match ($target['kind'] ?? 'kind_folder') {
            'folder_id', 'next_to' => '' !== $ref ? $this->store->folderById($person, $profile, $ref) : null,
            'personal_folder' => '',
            default => self::kindFolder($name),
        };
    }

    private static function kindFolder(string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        foreach (self::KIND_FOLDERS as $folder => $extensions) {
            if (in_array($ext, $extensions, true)) {
                return $folder;
            }
        }

        return self::DOCUMENTS;
    }

    /**
     * A free name next to the existing files: "report (2).pdf" when the name
     * is taken, or the same file when Synaplan wrote it and may replace it.
     */
    private function place(string $person, string $folder, string $name, string $onConflict): string
    {
        $name = '' !== Store::cleanPath(basename($name)) ? basename($name) : 'file';
        $prefix = '' !== $folder ? $folder.'/' : '';
        $path = $prefix.$name;
        if (!$this->store->exists($person, $path) || ('replace_own' === $onConflict && $this->store->wroteThis($person, $path))) {
            return $path;
        }
        $stem = pathinfo($name, PATHINFO_FILENAME);
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        for ($n = 2; ; ++$n) {
            $candidate = $prefix.$stem.' ('.$n.')'.('' !== $ext ? '.'.$ext : '');
            if (!$this->store->exists($person, $candidate)) {
                return $candidate;
            }
        }
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function finish(string $base, array $result): array
    {
        $this->synaplan->json('POST', $base.'/result', $result);

        return $result;
    }
}
