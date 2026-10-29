<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use App\Entity\CloudDelivery;
use App\Entity\ExternalIdentity;
use App\Entity\File;
use App\Entity\PlatformInstance;
use App\Exception\CloudDeliveryException;
use App\Repository\CloudDeliveryRepository;
use App\Repository\ExternalIdentityRepository;
use App\Repository\FileRepository;
use App\Repository\PlatformInstanceRepository;
use App\Service\Destination\DestinationFailureCode;
use App\Service\Destination\DestinationResult;
use App\Service\Destination\ShareableFile;
use App\Service\File\FileStorageService;
use App\Service\Iam\AuditLogWriter;
use App\Service\Infrastructure\RedisService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Queues a copy, hands the cloud a single-use claim, and records sent only
 * after the file bytes were downloaded.
 */
final readonly class CloudDeliveryService
{
    private const TICKET_PREFIX = 'cloud_delivery:ticket:';
    private const DOWNLOAD_PREFIX = 'cloud_delivery:download:';
    private const FAIL_PREFIX = 'cloud_delivery:fail:';
    private const DEFAULT_TTL = 300;
    private const UNCONFIRMED_AFTER = 600;
    private const FAIL_LIMIT = 120;

    public function __construct(
        private CloudDeliveryRepository $deliveries,
        private ExternalIdentityRepository $identities,
        private PlatformInstanceRepository $platforms,
        private FileRepository $files,
        private FileStorageService $storage,
        private RedisService $redis,
        private DeliveryNotifier $notifier,
        private AuditLogWriter $audit,
        private PlatformLinksConfig $platformLinks,
    ) {
    }

    /**
     * @param list<int> $fileIds
     *
     * @return list<array{id: int, file_id: int, state: string, remote_path: string, open_url: string, error_code: string, created: int, client: string, host: string}>
     */
    public function listForOwner(int $ownerId, array $fileIds): array
    {
        $rows = [];
        foreach ($this->deliveries->findForOwnerFiles($ownerId, array_slice($fileIds, 0, 100)) as $delivery) {
            $rows[] = [
                'id' => (int) $delivery->getId(),
                'file_id' => $delivery->getFileId(),
                'state' => $this->listedState($delivery),
                'remote_path' => $delivery->getRemotePath() ?? '',
                'open_url' => $delivery->getOpenUrl() ?? '',
                'error_code' => $delivery->getErrorCode() ?? '',
                'created' => $delivery->getCreated(),
                'client' => $delivery->getDestinationClient(),
                'host' => $delivery->getDestinationHost(),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function copy(ShareableFile $file, array $params): DestinationResult
    {
        if (!$this->platformLinks->isEnabled($file->ownerId)) {
            return DestinationResult::failure(DestinationFailureCode::Unauthorized);
        }
        $identity = $this->identities->find((int) ($params['identity_id'] ?? 0));
        if (
            !$identity instanceof ExternalIdentity
            || $identity->getUserId() !== $file->ownerId
            || null === $identity->getId()
            || ExternalIdentity::STATUS_ACTIVE !== $identity->getStatus()
        ) {
            return DestinationResult::failure(DestinationFailureCode::Unauthorized);
        }
        $target = is_array($params['target'] ?? null) ? $params['target'] : [];
        $kind = is_string($target['kind'] ?? null) && '' !== $target['kind']
            ? $target['kind']
            : CloudDelivery::TARGET_KIND_FOLDER;
        $ref = is_string($target['ref'] ?? null) ? $target['ref'] : null;
        $conflict = ($params['on_conflict'] ?? null) === CloudDelivery::CONFLICT_REPLACE_OWN
            ? CloudDelivery::CONFLICT_REPLACE_OWN
            : CloudDelivery::CONFLICT_RENAME;

        $delivery = new CloudDelivery($file->ownerId, $file->fileId, (int) $identity->getId(), $kind, $ref, $conflict);
        $instance = $this->platforms->findByInstanceId($identity->getInstanceId());
        if ($instance instanceof PlatformInstance) {
            $delivery->rememberDestination($instance->getClient(), $instance->getHost());
        }
        $this->deliveries->save($delivery);
        $id = (int) $delivery->getId();
        $this->redis->set(self::TICKET_PREFIX.$id, $identity->getInstanceId(), $this->ttl());
        $this->audit->record($file->ownerId, 'cloud_delivery.queued', 'cloud_delivery', (string) $id, [
            'file_id' => $file->fileId,
            'identity_id' => (int) $identity->getId(),
        ]);

        $answer = $instance instanceof PlatformInstance ? $this->notifier->delivery($instance, $id) : null;
        if (!is_array($answer)) {
            return $this->pending($delivery);
        }

        return $this->applyNotify($delivery, $answer);
    }

    /**
     * @return array<string, mixed>
     */
    public function claim(PlatformInstance $instance, int $id): array
    {
        $this->guardRate($instance);
        $delivery = $this->requireOwn($instance, $id);
        if (CloudDelivery::STATE_QUEUED !== $delivery->getState()) {
            $this->fail($instance);
            throw new CloudDeliveryException(Response::HTTP_CONFLICT, 'already_claimed');
        }
        if (time() - $delivery->getCreated() > $this->ttl()) {
            $this->redis->getAndDelete(self::TICKET_PREFIX.$id);
            $this->fail($instance);
            throw new CloudDeliveryException(Response::HTTP_GONE, 'expired');
        }
        if (!$this->deliveries->claimIfQueued($id)) {
            $this->fail($instance);
            throw new CloudDeliveryException(Response::HTTP_CONFLICT, 'already_claimed');
        }
        $delivery->markClaimed();
        $ticket = $this->redis->getAndDelete(self::TICKET_PREFIX.$id);
        if ($ticket !== $instance->getInstanceId()) {
            $this->fail($instance);
            throw new CloudDeliveryException(Response::HTTP_CONFLICT, 'already_claimed');
        }
        $file = $this->files->find($delivery->getFileId());
        if (!$file instanceof File) {
            throw new CloudDeliveryException(Response::HTTP_NOT_FOUND, 'unknown_delivery');
        }
        $identity = $this->identities->find($delivery->getIdentityId());
        $download = bin2hex(random_bytes(16));
        $this->redis->set(self::DOWNLOAD_PREFIX.$id, $download, $this->ttl());

        return [
            'id' => (string) $id,
            'external_id' => $identity instanceof ExternalIdentity ? $identity->getExternalId() : '',
            'name' => self::fileName($file),
            'mime' => $file->getFileMime(),
            'size' => $file->getFileSize(),
            'target' => ['kind' => $delivery->getTargetKind(), 'ref' => $delivery->getTargetRef() ?? ''],
            'on_conflict' => $delivery->getOnConflict(),
            'download_path' => '/api/v1/platform-links/deliveries/'.$id.'/file?ticket='.$download,
            'expires_at' => time() + $this->ttl(),
        ];
    }

    /**
     * `$withBody` is false for a header-only request. That request must not
     * take the ticket or the download mark, because no bytes are sent.
     *
     * @return array{path: string, name: string, mime: string}
     */
    public function download(PlatformInstance $instance, int $id, string $ticket, bool $withBody = true): array
    {
        if (!$withBody) {
            throw new CloudDeliveryException(Response::HTTP_METHOD_NOT_ALLOWED, 'method_not_allowed');
        }
        $delivery = $this->requireOwn($instance, $id);
        $stored = $this->redis->get(self::DOWNLOAD_PREFIX.$id);
        if (!is_string($stored) || !hash_equals($stored, $ticket)) {
            throw new CloudDeliveryException(Response::HTTP_GONE, 'expired');
        }
        $file = $this->files->find($delivery->getFileId());
        if (!$file instanceof File) {
            throw new CloudDeliveryException(Response::HTTP_NOT_FOUND, 'unknown_delivery');
        }
        $path = $this->storage->getAbsolutePath($file->getFilePath());
        $this->openForReading($path);
        $taken = $this->redis->getAndDelete(self::DOWNLOAD_PREFIX.$id);
        if (!is_string($taken) || !hash_equals($taken, $ticket)) {
            throw new CloudDeliveryException(Response::HTTP_GONE, 'expired');
        }
        if (!$this->deliveries->markDownloaded($id)) {
            throw new CloudDeliveryException(Response::HTTP_GONE, 'expired');
        }
        $delivery->markDownloaded();

        return ['path' => $path, 'name' => self::fileName($file), 'mime' => $file->getFileMime()];
    }

    /**
     * A cloud file's display name is its path in that cloud ("/Invoices/a.pdf").
     * The cloud writes the copy under a plain name, and a Content-Disposition
     * filename may not contain a slash.
     */
    private static function fileName(File $file): string
    {
        $name = basename(str_replace('\\', '/', $file->getDisplayName()));

        return '' !== $name ? $name : 'file';
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{state: string}
     */
    public function result(PlatformInstance $instance, int $id, array $body): array
    {
        $delivery = $this->requireOwn($instance, $id);
        $status = is_string($body['status'] ?? null) ? $body['status'] : '';
        if (CloudDelivery::STATE_DELIVERED === $status) {
            $this->rememberDelivered($delivery, $body);
        } elseif (CloudDelivery::STATE_FAILED === $status) {
            $this->rememberFailure($delivery, $body, false);
        }

        return ['state' => $delivery->getState()];
    }

    public function sweep(): int
    {
        return $this->deliveries->failOpenOlderThan(
            time() - $this->unconfirmedAfter(),
            DestinationFailureCode::Unconfirmed->value,
        );
    }

    /**
     * A claimed copy is still being copied. The copies list keeps the
     * sentence it already uses for a queued row.
     */
    private function listedState(CloudDelivery $delivery): string
    {
        return CloudDelivery::STATE_CLAIMED === $delivery->getState()
            ? CloudDelivery::STATE_QUEUED
            : $delivery->getState();
    }

    /**
     * @param array<string, mixed> $answer
     */
    private function applyNotify(CloudDelivery $delivery, array $answer): DestinationResult
    {
        $status = is_string($answer['status'] ?? null) ? $answer['status'] : '';
        if (CloudDelivery::STATE_DELIVERED === $status) {
            $this->rememberDelivered($delivery, $answer);

            return $this->outcome($delivery);
        }
        if (CloudDelivery::STATE_FAILED === $status) {
            $this->rememberFailure($delivery, $answer, true);

            return $this->outcome($delivery);
        }

        return $this->pending($delivery);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function rememberDelivered(CloudDelivery $delivery, array $body): void
    {
        $path = $this->stringField($body, 'remote_path');
        $remoteId = $this->stringField($body, 'remote_id');
        $url = $this->stringField($body, 'open_url');
        $applied = $this->deliveries->recordDelivered(
            (int) $delivery->getId(),
            $path,
            $remoteId,
            $url,
            DestinationFailureCode::Unconfirmed->value,
        );
        if ($applied) {
            $delivery->markDelivered($path, $remoteId, $url);

            return;
        }
        $this->deliveries->refreshDelivery($delivery);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function rememberFailure(CloudDelivery $delivery, array $body, bool $includeQueued): void
    {
        $stored = $this->errorCode($body);
        if ($this->deliveries->recordFailed((int) $delivery->getId(), $stored, $includeQueued)) {
            $delivery->markFailed($stored);

            return;
        }
        $this->deliveries->refreshDelivery($delivery);
    }

    /**
     * Sent, the failure that is actually stored, or still pending. A notify
     * that lost the race reports the row, not the body that arrived too late.
     */
    private function outcome(CloudDelivery $delivery): DestinationResult
    {
        if (CloudDelivery::STATE_DELIVERED === $delivery->getState()) {
            return $this->success($delivery);
        }
        if (CloudDelivery::STATE_FAILED === $delivery->getState()) {
            $code = DestinationFailureCode::tryFrom((string) ($delivery->getErrorCode() ?? ''))
                ?? DestinationFailureCode::Unreachable;

            return DestinationResult::failure($code, ['delivery_id' => (string) $delivery->getId()]);
        }

        return $this->pending($delivery);
    }

    private function openForReading(string $path): void
    {
        if (!is_file($path)) {
            throw new CloudDeliveryException(Response::HTTP_NOT_FOUND, 'unknown_delivery');
        }
        try {
            $opened = new \SplFileObject($path, 'rb');
        } catch (\RuntimeException) {
            throw new CloudDeliveryException(Response::HTTP_NOT_FOUND, 'unknown_delivery');
        }
        unset($opened);
    }

    private function success(CloudDelivery $delivery): DestinationResult
    {
        return DestinationResult::success((string) ($delivery->getRemotePath() ?? ''), [
            'delivery_id' => (string) $delivery->getId(),
            'state' => CloudDelivery::STATE_DELIVERED,
            'open_url' => (string) ($delivery->getOpenUrl() ?? ''),
        ]);
    }

    private function pending(CloudDelivery $delivery): DestinationResult
    {
        return DestinationResult::pending([
            'delivery_id' => (string) $delivery->getId(),
            'state' => 'pending',
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function stringField(array $body, string $key): ?string
    {
        $value = $body[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function errorCode(array $body): string
    {
        $value = $body['error_code'] ?? null;

        return is_string($value) && '' !== $value ? $value : DestinationFailureCode::Unreachable->value;
    }

    private function requireOwn(PlatformInstance $instance, int $id): CloudDelivery
    {
        $delivery = $this->deliveries->find($id);
        if (!$delivery instanceof CloudDelivery) {
            $this->fail($instance);
            throw new CloudDeliveryException(Response::HTTP_NOT_FOUND, 'unknown_delivery');
        }
        $identity = $this->identities->find($delivery->getIdentityId());
        if (!$identity instanceof ExternalIdentity
            || ExternalIdentity::STATUS_ACTIVE !== $identity->getStatus()
            || $identity->getInstanceId() !== $instance->getInstanceId()) {
            $this->fail($instance);
            throw new CloudDeliveryException(Response::HTTP_FORBIDDEN, 'wrong_instance');
        }

        return $delivery;
    }

    private function guardRate(PlatformInstance $instance): void
    {
        $current = $this->redis->get(self::FAIL_PREFIX.$instance->getInstanceId());
        if (is_string($current) && (int) $current >= self::FAIL_LIMIT) {
            throw new CloudDeliveryException(Response::HTTP_TOO_MANY_REQUESTS, 'rate_limited');
        }
    }

    private function fail(PlatformInstance $instance): void
    {
        $count = $this->redis->increment(self::FAIL_PREFIX.$instance->getInstanceId());
        if (1 === $count) {
            $this->redis->expire(self::FAIL_PREFIX.$instance->getInstanceId(), 60);
        }
    }

    private function ttl(): int
    {
        $value = getenv('CLOUD_DELIVERY_TTL_SEC');

        return is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : self::DEFAULT_TTL;
    }

    private function unconfirmedAfter(): int
    {
        $value = getenv('CLOUD_DELIVERY_UNCONFIRMED_SEC');

        return is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : self::UNCONFIRMED_AFTER;
    }
}
