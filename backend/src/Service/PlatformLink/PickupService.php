<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use App\Entity\CloudPickup;
use App\Entity\CloudPickupFile;
use App\Entity\ExternalIdentity;
use App\Entity\File;
use App\Entity\PlatformInstance;
use App\Exception\CloudDeliveryException;
use App\Repository\CloudPickupFileRepository;
use App\Repository\CloudPickupRepository;
use App\Repository\ExternalIdentityRepository;
use App\Repository\PlatformInstanceRepository;
use App\Service\Connection\PlannerChannelCatalog;
use App\Service\File\FileStorageService;
use App\Service\Iam\AuditLogWriter;
use App\Service\Infrastructure\RedisService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Asks a linked cloud for files and records what came back.
 */
final readonly class PickupService
{
    private const TICKET_PREFIX = 'pickup:ticket:';
    private const UPLOAD_PREFIX = 'pickup:upload:';
    private const TTL = 600;
    private const HOUR_LIMIT = 20;
    /** Longer than the ticket and the run's wait together. */
    private const SWEEP_AFTER = 1200;
    private const UNCONFIRMED = 'unconfirmed';
    public const MAX_FILES = 50;
    public const MAX_BYTES = 209715200;
    public const MAX_FILE_BYTES = 104857600;

    public function __construct(
        private CloudPickupRepository $pickups,
        private CloudPickupFileRepository $held,
        private ExternalIdentityRepository $identities,
        private PlatformInstanceRepository $platforms,
        private FileStorageService $storage,
        private EntityManagerInterface $em,
        private RedisService $redis,
        private DeliveryNotifier $notifier,
        private AuditLogWriter $audit,
        private PlatformLinksConfig $platformLinks,
    ) {
    }

    /**
     * @param array<string, mixed> $selector
     *
     * @return array{ok: bool, error: string, pickup_id: int, sentence: string}
     */
    public function open(int $ownerId, int $identityId, array $selector, string $mode): array
    {
        if (!$this->platformLinks->isEnabled($ownerId)) {
            return $this->refused('not_linked', 'Synaplan is not linked to this cloud. Nothing was read.');
        }
        $identity = $this->identities->find($identityId);
        if (!$identity instanceof ExternalIdentity || $identity->getUserId() !== $ownerId || null === $identity->getId()) {
            return $this->refused('unauthorized', 'Nothing was read.');
        }
        $instance = $this->platforms->findByInstanceId($identity->getInstanceId());
        $folder = is_string($selector['folder_path'] ?? null) && '' !== $selector['folder_path'] ? $selector['folder_path'] : 'that folder';
        $client = $instance instanceof PlatformInstance ? $this->clientLabel($instance->getClient()) : 'cloud';
        $host = $instance instanceof PlatformInstance ? $instance->getHost() : 'this cloud';
        if (!$this->scopeAllows($identity, $selector)) {
            return $this->refused('outside_scope', 'Synaplan may not read '.$folder.' in your '.$client.'. Nothing was read.');
        }
        if ($this->pickups->countSince($ownerId, time() - 3600) >= self::HOUR_LIMIT) {
            return $this->refused('rate_limited', 'Synaplan already read many files from this cloud this hour. Nothing was read.');
        }
        if (!$instance instanceof PlatformInstance || !$instance->reports('pickups')) {
            return $this->refused('unreachable', 'Synaplan could not reach '.$host.'. Nothing was read.');
        }

        $pickup = new CloudPickup($ownerId, (int) $identity->getId(), $instance->getInstanceId(), $mode, $selector);
        $this->pickups->save($pickup);
        $id = (int) $pickup->getId();
        $this->redis->set(self::TICKET_PREFIX.$id, $instance->getInstanceId(), self::TTL);
        $this->audit->record($ownerId, 'cloud_pickup.opened', 'cloud_pickup', (string) $id, [
            'folder' => (string) ($selector['folder_path'] ?? ''),
            'mode' => $mode,
        ]);
        $this->notifier->pickup($instance, $id);

        return ['ok' => true, 'error' => '', 'pickup_id' => $id, 'sentence' => ''];
    }

    /**
     * @return array<string, mixed>
     */
    public function claim(PlatformInstance $instance, int $id): array
    {
        $pickup = $this->requireOwn($instance, $id);
        if (CloudPickup::STATE_QUEUED !== $pickup->getState()) {
            throw new CloudDeliveryException(Response::HTTP_CONFLICT, 'already_claimed');
        }
        if (time() - $pickup->getCreated() > self::TTL) {
            throw new CloudDeliveryException(Response::HTTP_GONE, 'expired');
        }
        $ticket = $this->redis->getAndDelete(self::TICKET_PREFIX.$id);
        if ($ticket !== $instance->getInstanceId()) {
            throw new CloudDeliveryException(Response::HTTP_CONFLICT, 'already_claimed');
        }
        $pickup->markClaimed();
        $this->pickups->save($pickup);
        $identity = $this->identities->find($pickup->getIdentityId());

        return [
            'id' => (string) $id,
            'external_id' => $identity instanceof ExternalIdentity ? $identity->getExternalId() : '',
            'mode' => $pickup->getMode(),
            'selector' => $pickup->selector(),
            'read_scope' => $identity instanceof ExternalIdentity ? $identity->readScope() : ['mode' => 'watched', 'folders' => []],
            'limits' => [
                'max_files' => self::MAX_FILES,
                'max_bytes' => self::MAX_BYTES,
                'max_file_bytes' => self::MAX_FILE_BYTES,
            ],
            'expires_at' => $pickup->getCreated() + self::TTL,
        ];
    }

    /**
     * @param array<mixed> $items
     *
     * @return array{upload: list<string>, reuse: list<array{id: string, file_id: int}>, skip: list<array{id: string, reason: string}>}
     */
    public function listing(PlatformInstance $instance, int $id, array $items): array
    {
        $pickup = $this->requireClaimed($instance, $id);
        $upload = [];
        $reuse = [];
        $skip = [];
        $names = [];
        $namesOnly = CloudPickup::MODE_LIST === $pickup->getMode();
        foreach (array_slice($items, 0, 500) as $item) {
            if (!is_array($item) || !is_string($item['id'] ?? null)) {
                continue;
            }
            $remoteId = $item['id'];
            $version = is_string($item['version'] ?? null) ? $item['version'] : '';
            $size = is_numeric($item['size'] ?? null) ? (int) $item['size'] : 0;
            $name = is_string($item['name'] ?? null) ? $item['name'] : '';
            if (strlen($remoteId) > 255 || strlen($version) > 255) {
                continue;
            }
            $names[$remoteId] = $name;
            if (!$this->nameMatches($pickup, $name)) {
                $skip[] = ['id' => $remoteId, 'reason' => 'type_not_read'];
                continue;
            }
            if ($namesOnly) {
                $pickup->rememberName($name);
                continue;
            }
            if ($size > self::MAX_FILE_BYTES) {
                $skip[] = ['id' => $remoteId, 'reason' => 'too_large'];
                continue;
            }
            if ($pickup->acceptCount() >= self::MAX_FILES || $pickup->acceptBytes() + $size > self::MAX_BYTES) {
                $skip[] = ['id' => $remoteId, 'reason' => 'over_limit'];
                continue;
            }
            $reuseId = $this->reusable($pickup, $instance, $remoteId, $version);
            if (null !== $reuseId) {
                $reuse[] = ['id' => $remoteId, 'file_id' => $reuseId];
                $pickup->rememberFile($reuseId);
                $pickup->rememberName($name);
                $pickup->addAccepted(1, $size);
                continue;
            }
            $upload[$remoteId] = $size;
            $pickup->rememberName($name);
            $pickup->addAccepted(1, $size);
        }
        $pickup->addListed(count($items));
        $pickup->addSkipped(array_map(
            static fn (array $row): array => ['id' => $row['id'], 'name' => $names[$row['id']] ?? '', 'reason' => $row['reason']],
            $skip,
        ));
        $this->pickups->save($pickup);
        $this->rememberUploads($id, $upload);

        return ['upload' => array_keys($upload), 'reuse' => $reuse, 'skip' => $skip];
    }

    public function storeFile(PlatformInstance $instance, int $id, string $remoteId, string $version, string $bytes, string $name, string $mime): int
    {
        $pickup = $this->requireClaimed($instance, $id);
        if (CloudPickup::MODE_LIST === $pickup->getMode()) {
            throw new CloudDeliveryException(Response::HTTP_CONFLICT, 'not_requested');
        }
        $allowed = json_decode((string) $this->redis->get(self::UPLOAD_PREFIX.$id), true);
        $reserved = is_array($allowed) ? $this->reservedBytes($allowed, $remoteId) : null;
        if (strlen($remoteId) > 255 || strlen($version) > 255 || null === $reserved) {
            throw new CloudDeliveryException(Response::HTTP_CONFLICT, 'not_requested');
        }
        $existing = $this->reusable($pickup, $instance, $remoteId, $version);
        if (null !== $existing) {
            return $existing;
        }
        $actual = strlen($bytes);
        if ($actual > self::MAX_FILE_BYTES) {
            throw new CloudDeliveryException(Response::HTTP_CONFLICT, 'too_large');
        }
        $extra = max(0, $actual - $reserved);
        if ($pickup->acceptBytes() + $extra > self::MAX_BYTES) {
            throw new CloudDeliveryException(Response::HTTP_CONFLICT, 'over_limit');
        }
        if ($extra > 0) {
            $pickup->addAccepted(0, $extra);
        }

        return $this->placeCopy($pickup, $instance, $remoteId, $version, $bytes, $name, $mime);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{state: string}
     */
    public function result(PlatformInstance $instance, int $id, array $body): array
    {
        $pickup = $this->requireOwn($instance, $id);
        if ($this->isSettled($pickup)) {
            return ['state' => $pickup->getState()];
        }
        $status = is_string($body['status'] ?? null) ? $body['status'] : '';
        $state = match ($status) {
            'done' => CloudPickup::STATE_DONE,
            'partial' => CloudPickup::STATE_PARTIAL,
            'failed' => CloudPickup::STATE_FAILED,
            default => null,
        };
        if (null === $state) {
            return ['state' => $pickup->getState()];
        }
        $reported = is_array($body['skipped'] ?? null) ? array_values(array_filter($body['skipped'], 'is_array')) : [];
        $skipped = self::withReported($pickup->skipped(), $reported);
        $this->pickups->finishIfOpen(
            $id,
            $state,
            is_string($body['error_code'] ?? null) ? $body['error_code'] : null,
            is_numeric($body['listed'] ?? null) ? (int) $body['listed'] : null,
            is_numeric($body['sent'] ?? null) ? (int) $body['sent'] : null,
            [] !== $skipped ? $skipped : null,
        );
        $this->em->refresh($pickup);

        return ['state' => $pickup->getState()];
    }

    /**
     * The rows Synaplan recorded while listing carry the names; the app may add
     * files it could not send. One row per file id, the recorded one first.
     *
     * @param list<array<mixed>> $recorded
     * @param list<array<mixed>> $reported
     *
     * @return list<array<mixed>>
     */
    private static function withReported(array $recorded, array $reported): array
    {
        $seen = [];
        foreach ($recorded as $row) {
            if (is_string($row['id'] ?? null)) {
                $seen[$row['id']] = true;
            }
        }
        foreach ($reported as $row) {
            $rowId = is_string($row['id'] ?? null) ? $row['id'] : null;
            if (null === $rowId || !isset($seen[$rowId])) {
                $recorded[] = $row;
            }
        }

        return $recorded;
    }

    /**
     * @param array<mixed> $folders
     */
    public function reportScope(PlatformInstance $instance, string $externalId, string $mode, array $folders): void
    {
        if (!in_array($mode, ['watched', 'allowed', 'all'], true)) {
            throw new CloudDeliveryException(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_scope');
        }
        $identity = $this->identities->findOneBy([
            'instanceId' => $instance->getInstanceId(),
            'externalId' => $externalId,
        ]);
        if (!$identity instanceof ExternalIdentity) {
            throw new CloudDeliveryException(Response::HTTP_NOT_FOUND, 'unknown_identity');
        }
        $clean = [];
        foreach ($folders as $folder) {
            if (!is_array($folder)) {
                continue;
            }
            $clean[] = [
                'id' => is_string($folder['id'] ?? null) ? $folder['id'] : '',
                'path' => is_string($folder['path'] ?? null) ? $folder['path'] : '',
            ];
        }
        $identity->setReadScope(['mode' => $mode, 'folders' => $clean]);
        $this->em->flush();
    }

    public function find(int $id): ?CloudPickup
    {
        $pickup = $this->pickups->find($id);
        if (!$pickup instanceof CloudPickup) {
            return null;
        }
        // The runner saved this row. A later claim arrives in another request,
        // so the copy still in this unit of work is stale until it is re-read.
        if ($this->em->contains($pickup)) {
            $this->em->refresh($pickup);
        }

        return $pickup;
    }

    /**
     * Linked clouds this person may read, named the way the planner names channels.
     *
     * @param list<string> $usedChannels keys already taken by other folder channels
     *
     * @return list<array{identity_id: int, channel: string, host: string, client: string, folders: list<string>}>
     */
    public function readableLinks(int $ownerId, array $usedChannels = []): array
    {
        if (!$this->platformLinks->isEnabled($ownerId)) {
            return [];
        }
        $out = [];
        $used = $usedChannels;
        foreach ($this->identities->findByUserId($ownerId) as $identity) {
            if (null === $identity->getId() || '' === $identity->getInstanceId()) {
                continue;
            }
            $instance = $this->platforms->findByInstanceId($identity->getInstanceId());
            if (!$instance instanceof PlatformInstance || !$instance->reports('pickups')) {
                continue;
            }
            $base = PlannerChannelCatalog::sanitize($instance->getClient());
            $channel = PlannerChannelCatalog::unique('' !== $base ? $base : 'cloud', $used);
            $used[] = $channel;
            $folders = [];
            foreach ($identity->readScope()['folders'] as $folder) {
                if ('' !== $folder['path']) {
                    $folders[] = $folder['path'];
                }
            }
            $out[] = [
                'identity_id' => (int) $identity->getId(),
                'channel' => $channel,
                'host' => $instance->getHost(),
                'client' => $instance->getClient(),
                'folders' => $folders,
            ];
        }

        return $out;
    }

    /**
     * @param list<string> $usedChannels
     *
     * @return array{identity_id: int, channel: string, host: string, client: string, folders: list<string>}|null
     */
    public function linkForChannel(int $ownerId, string $channel, array $usedChannels = []): ?array
    {
        $needle = PlannerChannelCatalog::sanitize($channel);
        if ('' === $needle) {
            return null;
        }
        foreach ($this->readableLinks($ownerId, $usedChannels) as $link) {
            if ($link['channel'] === $needle) {
                return $link;
            }
        }

        return null;
    }

    /**
     * The run stopped waiting. A pickup still open fails, and its ticket goes,
     * so a late claim or upload is refused instead of landing in no run. False
     * when a result landed first; the caller then reads that result.
     */
    public function giveUp(int $id): bool
    {
        if (!$this->pickups->finishIfOpen($id, CloudPickup::STATE_FAILED, self::UNCONFIRMED)) {
            return false;
        }
        $this->redis->delete(self::TICKET_PREFIX.$id);

        return true;
    }

    /**
     * Fails pickups no run is waiting for any more (the runner crashed or the
     * worker restarted), so every ledger row ends in a terminal state.
     */
    public function sweep(): int
    {
        return $this->pickups->failOpenOlderThan(time() - self::SWEEP_AFTER, self::UNCONFIRMED);
    }

    private function isSettled(CloudPickup $pickup): bool
    {
        return in_array($pickup->getState(), [CloudPickup::STATE_DONE, CloudPickup::STATE_PARTIAL, CloudPickup::STATE_FAILED], true);
    }

    /**
     * @return list<array{path: string, type: string, local_path: string, name: string}>
     */
    public function producedFiles(CloudPickup $pickup): array
    {
        $out = [];
        foreach ($pickup->fileIds() as $id) {
            $file = $this->em->find(File::class, $id);
            if (!$file instanceof File || '' === $file->getFilePath()) {
                continue;
            }
            $out[] = [
                'path' => '/api/v1/files/uploads/'.$file->getFilePath(),
                'type' => 'document',
                'local_path' => $file->getFilePath(),
                'name' => $file->getFileName(),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $selector
     */
    private function scopeAllows(ExternalIdentity $identity, array $selector): bool
    {
        $scope = $identity->readScope();
        if ('all' === $scope['mode']) {
            return true;
        }
        $folderId = is_string($selector['folder_id'] ?? null) ? $selector['folder_id'] : '';
        $folderPath = is_string($selector['folder_path'] ?? null) ? $selector['folder_path'] : '';
        foreach ($scope['folders'] as $folder) {
            if ('' !== $folderId && $folder['id'] === $folderId) {
                return true;
            }
            if ('' !== $folderPath && $folder['path'] === $folderPath) {
                return true;
            }
        }

        return false;
    }

    private function nameMatches(CloudPickup $pickup, string $name): bool
    {
        $pattern = $pickup->selector()['pattern'] ?? null;
        if (!is_string($pattern) || '' === $pattern || '*' === $pattern) {
            return true;
        }
        $regex = '/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/i';

        return 1 === preg_match($regex, $name);
    }

    private function requireOwn(PlatformInstance $instance, int $id): CloudPickup
    {
        $pickup = $this->pickups->find($id);
        if (!$pickup instanceof CloudPickup) {
            throw new CloudDeliveryException(Response::HTTP_NOT_FOUND, 'unknown_delivery');
        }
        if (!$instance->isActive() || $pickup->getInstanceId() !== $instance->getInstanceId()) {
            throw new CloudDeliveryException(Response::HTTP_FORBIDDEN, 'wrong_instance');
        }
        $identity = $this->identities->find($pickup->getIdentityId());
        if (!$identity instanceof ExternalIdentity
            || $identity->getUserId() !== $pickup->getOwnerId()
            || $identity->getInstanceId() !== $instance->getInstanceId()) {
            throw new CloudDeliveryException(Response::HTTP_FORBIDDEN, 'wrong_instance');
        }

        return $pickup;
    }

    /**
     * @param array<string, int> $upload remote id => reserved size
     */
    private function rememberUploads(int $id, array $upload): void
    {
        $known = json_decode((string) $this->redis->get(self::UPLOAD_PREFIX.$id), true);
        $map = [];
        if (is_array($known) && !array_is_list($known)) {
            foreach ($known as $remoteId => $size) {
                if (is_string($remoteId) && is_numeric($size)) {
                    $map[$remoteId] = (int) $size;
                }
            }
        }
        foreach ($upload as $remoteId => $size) {
            $map[$remoteId] = $size;
        }
        $this->redis->set(self::UPLOAD_PREFIX.$id, json_encode($map, JSON_THROW_ON_ERROR), self::TTL);
    }

    /**
     * @param array<mixed> $allowed
     */
    private function reservedBytes(array $allowed, string $remoteId): ?int
    {
        if (array_is_list($allowed)) {
            return in_array($remoteId, $allowed, true) ? 0 : null;
        }
        if (!array_key_exists($remoteId, $allowed) || !is_numeric($allowed[$remoteId])) {
            return null;
        }

        return (int) $allowed[$remoteId];
    }

    private function reusable(CloudPickup $pickup, PlatformInstance $instance, string $remoteId, string $version): ?int
    {
        $held = $this->held->findHeld($pickup->getOwnerId(), $instance->getInstanceId(), $remoteId, $version);
        if (!$held instanceof CloudPickupFile) {
            return null;
        }
        if ($this->copyStillHeld($held)) {
            return $held->getFileId();
        }
        $this->forget($held);

        return null;
    }

    private function copyStillHeld(CloudPickupFile $held): bool
    {
        $file = $this->em->find(File::class, $held->getFileId());

        return $file instanceof File
            && '' !== $file->getFilePath()
            && $this->storage->fileExists($file->getFilePath());
    }

    private function forget(CloudPickupFile $held): void
    {
        $file = $this->em->find(File::class, $held->getFileId());
        $this->em->remove($held);
        if ($file instanceof File) {
            $path = $file->getFilePath();
            $this->em->remove($file);
            if ('' !== $path) {
                $this->storage->deleteFile($path);
            }
        }
        $this->em->flush();
    }

    private function placeCopy(CloudPickup $pickup, PlatformInstance $instance, string $remoteId, string $version, string $bytes, string $name, string $mime): int
    {
        $stored = $this->storage->storeRawContent($bytes, $pickup->getOwnerId(), '' !== $name ? $name : 'file', $mime);
        if (true !== $stored['success']) {
            throw new CloudDeliveryException(Response::HTTP_CONFLICT, 'unreachable');
        }
        $file = new File();
        $file->setUserId($pickup->getOwnerId());
        $file->setFilePath($stored['path']);
        $file->setFileName($name);
        $file->setFileSize($stored['size']);
        $file->setFileMime($mime);
        $file->setFileType(pathinfo($name, PATHINFO_EXTENSION) ?: 'bin');
        $file->setStatus('complete');
        $file->setSource($this->sourceFor($instance));
        $file->setSourceId($remoteId);
        $file->setSourceEtag($version);
        $file->setIncoming(false);
        $file->setEphemeral(true);
        $this->em->persist($file);
        $this->em->flush();
        $fileId = (int) $file->getId();
        if (!$this->held->insertIgnore(new CloudPickupFile($pickup->getOwnerId(), $instance->getInstanceId(), $remoteId, $version, $fileId))) {
            $this->discardWorkingCopy($file, $stored['path']);
            $winner = $this->reusable($pickup, $instance, $remoteId, $version);
            if (null === $winner) {
                throw new CloudDeliveryException(Response::HTTP_CONFLICT, 'unreachable');
            }

            return $winner;
        }
        $pickup->rememberFile($fileId);
        $pickup->addSent();
        $this->pickups->save($pickup);

        return $fileId;
    }

    private function discardWorkingCopy(File $file, string $path): void
    {
        $this->em->remove($file);
        $this->em->flush();
        if ('' !== $path) {
            $this->storage->deleteFile($path);
        }
    }

    private function requireClaimed(PlatformInstance $instance, int $id): CloudPickup
    {
        $pickup = $this->requireOwn($instance, $id);
        if (CloudPickup::STATE_CLAIMED !== $pickup->getState()) {
            throw new CloudDeliveryException(Response::HTTP_CONFLICT, 'already_claimed');
        }
        if (time() - $pickup->getCreated() > self::TTL) {
            throw new CloudDeliveryException(Response::HTTP_GONE, 'expired');
        }

        return $pickup;
    }

    private function sourceFor(PlatformInstance $instance): string
    {
        $client = $instance->getClient();

        return in_array($client, File::SOURCES, true) ? $client : 'api';
    }

    private function clientLabel(string $client): string
    {
        return match ($client) {
            PlatformInstance::CLIENT_NEXTCLOUD => 'Nextcloud',
            PlatformInstance::CLIENT_OPENCLOUD => 'OpenCloud',
            PlatformInstance::CLIENT_OWNCLOUD => 'ownCloud',
            default => 'cloud',
        };
    }

    /**
     * @return array{ok: bool, error: string, pickup_id: int, sentence: string}
     */
    private function refused(string $error, string $sentence): array
    {
        return ['ok' => false, 'error' => $error, 'pickup_id' => 0, 'sentence' => $sentence];
    }
}
