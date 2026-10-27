<?php

declare(strict_types=1);

namespace App\Service\CloudWatch;

use App\Entity\CloudWatch;
use App\Entity\CloudWatchItem;
use App\Entity\File;
use App\Entity\PlatformInstance;
use App\Entity\User;
use App\Repository\CloudWatchItemRepository;
use App\Repository\CloudWatchRepository;
use App\Service\File\FileStorageService;
use App\Service\Usage\StorageQuotaService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * One file version arriving for a watch. The same version again is already handled.
 */
final readonly class WatchUpload
{
    private const GRACE_SECONDS = 604800;

    public function __construct(
        private CloudWatchItemRepository $items,
        private LoopGuard $loops,
        private WatchLimits $limits,
        private QuietPeriod $quiet,
        private FileStorageService $storage,
        private EntityManagerInterface $entityManager,
        private StorageQuotaService $quota,
        private CloudWatchRepository $watches,
    ) {
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<int>            $instanceWatchIds
     *
     * @return array{item_id?: int, state: string, reason?: string|null}
     */
    public function receive(CloudWatch $watch, User $user, PlatformInstance $instance, array $fields, ?UploadedFile $upload, array $instanceWatchIds, int $now): array
    {
        $sourceId = $this->text($fields, 'id');
        $version = $this->text($fields, 'version');
        if ('' === $sourceId || '' === $version || strlen($sourceId) > 255 || strlen($version) > 255) {
            throw new CloudWatchException(400, 'A file id and version are required, each at most 255 characters.');
        }
        if (CloudWatch::KIND_FILE === $watch->getTargetKind() && $sourceId !== $watch->getTargetId()) {
            throw new CloudWatchException(422, 'This watch follows one file.');
        }
        $event = $this->text($fields, 'event');
        if ('deleted' === $event && $sourceId === $watch->getTargetId() && CloudWatch::STATE_ACTIVE === $watch->getState()) {
            $watch->mark(CloudWatch::STATE_ATTENTION, CloudWatch::REASON_TARGET_GONE);
            $watch->setLastEventAt($now);
            $this->watches->stamp($watch, $instance->getInstanceId());
            $this->entityManager->flush();

            return ['state' => CloudWatch::STATE_ATTENTION, 'reason' => CloudWatch::REASON_TARGET_GONE];
        }
        if ('restored' === $event && CloudWatch::STATE_ATTENTION === $watch->getState() && CloudWatch::REASON_TARGET_GONE === $watch->getReason()) {
            $goneAt = $watch->getLastEventAt() ?? 0;
            if ($now - $goneAt <= self::GRACE_SECONDS) {
                $watch->mark(CloudWatch::STATE_ACTIVE, null);
                $this->watches->stamp($watch, $instance->getInstanceId());
                $this->entityManager->flush();
            }

            return ['state' => $watch->getState(), 'reason' => $watch->getReason()];
        }
        if (CloudWatch::STATE_ACTIVE !== $watch->getState()) {
            throw new CloudWatchException(409, 'This watch is not running.');
        }
        $existing = $this->items->findVersion((int) $watch->getId(), $sourceId, $version);
        if ($existing instanceof CloudWatchItem) {
            return ['item_id' => (int) $existing->getId(), 'state' => 'already_handled'];
        }

        $name = $this->text($fields, 'name');
        if ('' === $name) {
            $name = $sourceId;
        }
        $loop = $this->loops->reason($watch, $sourceId, $name, '1' === $this->text($fields, 'own_write'), $now);
        if (null !== $loop) {
            return $this->skip($watch, $sourceId, $version, CloudWatchItem::REASON_OWN_WRITE === $loop || 'own_write' === $loop ? CloudWatchItem::REASON_OWN_WRITE : CloudWatchItem::REASON_LANGUAGE_SUFFIX);
        }
        if (!$this->limits->readable($name, true === ($watch->getFilter()['images'] ?? false))) {
            return $this->skip($watch, $sourceId, $version, CloudWatchItem::REASON_TYPE_NOT_READ);
        }
        $size = $upload instanceof UploadedFile ? (int) $upload->getSize() : 0;
        if ($size > WatchLimits::MAX_BYTES) {
            return $this->skip($watch, $sourceId, $version, CloudWatchItem::REASON_TOO_LARGE);
        }
        if (!$upload instanceof UploadedFile) {
            throw new CloudWatchException(400, 'The file itself is missing.');
        }
        try {
            $this->quota->checkStorageLimit($user, $size);
        } catch (\RuntimeException $e) {
            throw new CloudWatchException(409, $e->getMessage(), 'storage_quota');
        }
        foreach ($this->items->waitingForSource((int) $watch->getId(), $sourceId) as $previous) {
            if (!$this->quiet->due($previous->getUpdated(), $now)) {
                $previous->mark(CloudWatchItem::STATE_SKIPPED, CloudWatchItem::REASON_GONE);
            }
        }
        $claimed = $this->claim((int) $watch->getId(), $sourceId, $version, $now);
        if (!$claimed instanceof CloudWatchItem) {
            return ['state' => 'already_handled'];
        }

        $waiting = $this->overLimit($watch, $instanceWatchIds, $now);
        try {
            $stored = $this->store($watch, $user, $instance, $upload, $sourceId, $version, $name);
        } catch (\Throwable $e) {
            $this->entityManager->remove($claimed);
            $this->entityManager->flush();
            throw $e;
        }
        $item = $claimed;
        $item->setFileId($stored);
        if ($waiting) {
            $item->mark(CloudWatchItem::STATE_WAITING, CloudWatchItem::REASON_LIMIT_WAITING);
            $watch->addCount('waiting');
        }
        $this->entityManager->persist($item);
        $this->entityManager->flush();

        return [
            'item_id' => (int) $item->getId(),
            'state' => $item->getState(),
            'reason' => $item->getReason(),
        ];
    }

    /**
     * @param list<array{id: string, version: string}> $rows
     *
     * @return array{process: list<string>, forget: list<string>}
     */
    public function reconcile(CloudWatch $watch, array $rows): array
    {
        $process = [];
        $forget = [];
        foreach ($rows as $row) {
            $id = $row['id'];
            $known = $this->items->findVersion((int) $watch->getId(), $id, $row['version']);
            if ($known instanceof CloudWatchItem) {
                $forget[] = $id;
            } else {
                $process[] = $id;
            }
        }

        return ['process' => $process, 'forget' => $forget];
    }

    private function claim(int $watchId, string $sourceId, string $version, int $now): CloudWatchItem|string
    {
        try {
            $this->entityManager->getConnection()->executeStatement(
                'INSERT INTO BCLOUDWATCHITEMS (BWATCHID, BSOURCEID, BVERSION, BSTATE, BCREATED, BUPDATED) VALUES (?, ?, ?, ?, ?, ?)',
                [$watchId, $sourceId, $version, CloudWatchItem::STATE_WAITING, $now, $now],
            );
        } catch (UniqueConstraintViolationException) {
            return 'already_handled';
        }
        $item = $this->items->findVersion($watchId, $sourceId, $version);

        return $item instanceof CloudWatchItem ? $item : 'already_handled';
    }

    /** @param list<int> $instanceWatchIds */
    private function overLimit(CloudWatch $watch, array $instanceWatchIds, int $now): bool
    {
        $since = $now - 3600;
        $watchCount = $this->items->countSince((int) $watch->getId(), $since);
        if ($watchCount >= $this->limits->perHour($watch->isSearchable())) {
            return true;
        }

        return $this->items->countForWatchesSince($instanceWatchIds, $since) >= WatchLimits::INSTANCE_PER_HOUR;
    }

    /** @return array{item_id: int, state: string, reason: string} */
    private function skip(CloudWatch $watch, string $sourceId, string $version, string $reason): array
    {
        $item = new CloudWatchItem((int) $watch->getId(), $sourceId, $version, CloudWatchItem::STATE_SKIPPED);
        $item->mark(CloudWatchItem::STATE_SKIPPED, $reason);
        $this->entityManager->persist($item);
        $this->entityManager->flush();

        return ['item_id' => (int) $item->getId(), 'state' => CloudWatchItem::STATE_SKIPPED, 'reason' => $reason];
    }

    private function store(CloudWatch $watch, User $user, PlatformInstance $instance, UploadedFile $upload, string $sourceId, string $version, string $name): int
    {
        $stored = $this->storage->storeUploadedFile($upload, (int) $user->getId());
        if (true !== $stored['success']) {
            throw new CloudWatchException(400, is_string($stored['error']) ? $stored['error'] : 'The file could not be stored.');
        }
        $file = new File();
        $file->setUserId((int) $user->getId());
        $file->setFilePath($stored['path']);
        $file->setFileName($name);
        $file->setFileSize($stored['size']);
        $file->setFileMime($stored['mime']);
        $file->setFileType((string) ($stored['extension'] ?? pathinfo($name, PATHINFO_EXTENSION)));
        $file->setStatus('uploaded');
        $file->setSource($instance->getClient());
        $file->setSourceId($sourceId);
        $file->setSourceInstance($instance->getInstanceId());
        $file->setSourceEtag($version);
        if ($watch->isSearchable()) {
            $file->setKeepSynced(true);
            $file->setGroupKey($watch->getGroupKey());
            $file->setVectorState(File::VECTOR_STATE_PENDING);
        } else {
            $file->setEphemeral(true);
            $file->setExpiresAt(time() + self::GRACE_SECONDS);
            $file->setVectorState(File::VECTOR_STATE_NOT_APPLICABLE);
        }
        $this->entityManager->persist($file);
        $this->entityManager->flush();

        return (int) $file->getId();
    }

    /** @param array<string, mixed> $fields */
    private function text(array $fields, string $key): string
    {
        $value = $fields[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }
}
