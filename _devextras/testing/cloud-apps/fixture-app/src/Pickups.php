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
        $folder = Store::cleanPath((string) ($selector['folder_path'] ?? ''));
        if (!$this->mayRead($person, $folder)) {
            return $this->finish($base, ['status' => 'failed', 'error_code' => 'outside_scope', 'listed' => 0, 'sent' => 0]);
        }
        if ('' !== $folder && !$this->store->folderExists($person, $folder)) {
            return $this->finish($base, ['status' => 'failed', 'error_code' => 'not_found', 'listed' => 0, 'sent' => 0]);
        }

        $profile = (string) $this->store->state()['profile'];
        $byId = [];
        $items = [];
        foreach ($this->store->files($person, $folder, true === ($selector['recursive'] ?? false)) as $file) {
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
                $skipped[] = ['id' => $remoteId, 'reason' => (string) ($stored['body']['error'] ?? 'unreadable')];
            }
        }

        return $this->finish($base, [
            'status' => [] === $skipped ? 'done' : 'partial',
            'listed' => count($items),
            'sent' => $sent,
            'skipped' => $skipped,
        ]);
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
