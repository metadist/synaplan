<?php

declare(strict_types=1);

namespace CloudFixture;

/**
 * The app side of a folder read (CF3.6): claim the request, check it against
 * what the person lets Synaplan read (the app's own setting, not Synaplan's
 * mirror of it), list the folder, upload what Synaplan asks for, report.
 */
final class Pickups
{
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
        $base = '/api/v1/platform-links/pickups/'.rawurlencode($id);
        $claim = $this->synaplan->json('POST', $base.'/claim');
        if (200 !== $claim['status']) {
            return ['state' => 'failed', 'claim' => $claim['body']['error'] ?? $claim['status']];
        }
        $request = $claim['body'];
        $person = (string) ($request['external_id'] ?? '');
        $selector = is_array($request['selector'] ?? null) ? $request['selector'] : [];
        $profile = (string) $this->store->state()['profile'];
        $folder = $this->folder($person, $profile, $selector);
        if (null === $folder) {
            return $this->finish($base, ['status' => 'failed', 'error_code' => 'not_found', 'listed' => 0, 'sent' => 0]);
        }
        if (!$this->mayRead($person, $folder)) {
            return $this->finish($base, ['status' => 'failed', 'error_code' => 'outside_scope', 'listed' => 0, 'sent' => 0]);
        }

        $since = self::since($selector['changed_since'] ?? null);
        $byId = [];
        $items = [];
        foreach ($this->store->files($person, $folder, true === ($selector['recursive'] ?? false)) as $file) {
            if ($file['modified'] < $since) {
                continue;
            }
            $bytes = (string) $this->store->read($person, $file['path']);
            $remoteId = Profile::remoteId($profile, $person, $file['path']);
            $byId[$remoteId] = ['path' => $file['path'], 'bytes' => $bytes];
            $items[] = [
                'id' => $remoteId,
                'version' => Profile::version($profile, $bytes),
                'name' => basename($file['path']),
                'folder' => dirname($file['path']),
                'size' => $file['size'],
                'mime' => $this->mime($bytes),
            ];
        }
        $listing = $this->synaplan->json('POST', $base.'/listing', ['items' => $items]);
        if (200 !== $listing['status']) {
            return $this->finish($base, ['status' => 'failed', 'error_code' => 'unreachable', 'listed' => count($items), 'sent' => 0]);
        }

        $sent = 0;
        $skipped = [];
        foreach (is_array($listing['body']['skip'] ?? null) ? $listing['body']['skip'] : [] as $skip) {
            $remoteId = is_array($skip) ? (string) ($skip['id'] ?? '') : '';
            if (isset($byId[$remoteId])) {
                $skipped[] = ['id' => $remoteId, 'name' => basename($byId[$remoteId]['path']), 'reason' => (string) ($skip['reason'] ?? '')];
            }
        }
        $upload = is_array($listing['body']['upload'] ?? null) ? $listing['body']['upload'] : [];
        foreach ($upload as $remoteId) {
            $file = is_string($remoteId) ? ($byId[$remoteId] ?? null) : null;
            if (null === $file) {
                continue;
            }
            $stored = $this->synaplan->upload($base.'/files', [
                'id' => $remoteId,
                'version' => Profile::version($profile, $file['bytes']),
            ], $file['bytes'], basename($file['path']), $this->mime($file['bytes']));
            if (200 === $stored['status']) {
                ++$sent;
            } else {
                $skipped[] = ['id' => $remoteId, 'name' => basename($file['path']), 'reason' => (string) ($stored['body']['error'] ?? 'unreadable')];
            }
        }
        // A file the pattern did not match was not asked for; anything else
        // left out makes the read partial.
        $missing = array_filter($skipped, static fn (array $row): bool => 'type_not_read' !== $row['reason']);

        return $this->finish($base, [
            'status' => [] === $missing ? 'done' : 'partial',
            'listed' => count($items),
            'sent' => $sent,
            'skipped' => $skipped,
        ]);
    }

    /**
     * The folder a selector names: by remote id when it carries one (an
     * unknown id is null), else by path.
     *
     * @param array<mixed> $selector
     */
    private function folder(string $person, string $profile, array $selector): ?string
    {
        $id = is_string($selector['folder_id'] ?? null) ? $selector['folder_id'] : '';
        if ('' !== $id) {
            return $this->store->folderById($person, $profile, $id);
        }
        $path = Store::cleanPath((string) ($selector['folder_path'] ?? ''));

        return '' === $path || $this->store->folderExists($person, $path) ? $path : null;
    }

    /**
     * `changed_since` as a cut-off time: "7d", "12h", "2w"; anything else
     * means no cut-off.
     */
    private static function since(mixed $value): int
    {
        if (!is_string($value) || 1 !== preg_match('/^(\d+)([hdw])$/', $value, $m)) {
            return 0;
        }

        return time() - (int) $m[1] * ['h' => 3600, 'd' => 86400, 'w' => 604800][$m[2]];
    }

    /**
     * What the person allowed: everything, or the folders they named (and
     * what is inside them). A person who set nothing allowed nothing.
     */
    private function mayRead(string $person, string $folder): bool
    {
        $scope = $this->store->state()['scopes'][$person] ?? null;
        if (!is_array($scope)) {
            return false;
        }
        if ('all' === ($scope['mode'] ?? null)) {
            return true;
        }
        foreach (is_array($scope['folders'] ?? null) ? $scope['folders'] : [] as $allowed) {
            $allowed = Store::cleanPath((string) $allowed);
            if ($folder === $allowed || str_starts_with($folder.'/', $allowed.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function finish(string $base, array $result): array
    {
        $answer = $this->synaplan->json('POST', $base.'/result', $result);

        return ['state' => $answer['body']['state'] ?? $result['status']] + $result;
    }

    private function mime(string $bytes): string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return is_string($mime) ? $mime : 'application/octet-stream';
    }
}
