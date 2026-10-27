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
 * Queues a copy, hands the cloud a single-use claim, and records the result.
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
                'state' => $delivery->getState(),
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
            return DestinationResult::pending([
                'delivery_id' => (string) $id,
                'state' => 'pending',
            ]);
        }
        $status = is_string($answer['status'] ?? null) ? $answer['status'] : '';
        if (CloudDelivery::STATE_DELIVERED === $status) {
            $this->record($delivery, $answer);

            return DestinationResult::success((string) ($delivery->getRemotePath() ?? ''), [
                'delivery_id' => (string) $id,
                'state' => CloudDelivery::STATE_DELIVERED,
                'open_url' => (string) ($delivery->getOpenUrl() ?? ''),
            ]);
        }
        if (CloudDelivery::STATE_FAILED === $status) {
            $this->record($delivery, $answer);
            $code = DestinationFailureCode::tryFrom((string) ($answer['error_code'] ?? ''))
                ?? DestinationFailureCode::Unreachable;

            return DestinationResult::failure($code, ['delivery_id' => (string) $id]);
        }

        return DestinationResult::pending([
            'delivery_id' => (string) $id,
            'state' => 'pending',
        ]);
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
            'name' => $file->getDisplayName(),
            'mime' => $file->getFileMime(),
            'size' => $file->getFileSize(),
            'target' => ['kind' => $delivery->getTargetKind(), 'ref' => $delivery->getTargetRef() ?? ''],
            'on_conflict' => $delivery->getOnConflict(),
            'download_path' => '/api/v1/platform-links/deliveries/'.$id.'/file?ticket='.$download,
            'expires_at' => time() + $this->ttl(),
        ];
    }

    /**
     * @return array{path: string, name: string, mime: string}
     */
    public function download(PlatformInstance $instance, int $id, string $ticket): array
    {
        $this->requireOwn($instance, $id);
        $stored = $this->redis->get(self::DOWNLOAD_PREFIX.$id);
        if (!is_string($stored) || !hash_equals($stored, $ticket)) {
            throw new CloudDeliveryException(Response::HTTP_GONE, 'expired');
        }
        $taken = $this->redis->getAndDelete(self::DOWNLOAD_PREFIX.$id);
        if ($taken !== $ticket) {
            throw new CloudDeliveryException(Response::HTTP_GONE, 'expired');
        }
        $delivery = $this->deliveries->find($id);
        $file = $delivery instanceof CloudDelivery ? $this->files->find($delivery->getFileId()) : null;
        if (!$file instanceof File) {
            throw new CloudDeliveryException(Response::HTTP_NOT_FOUND, 'unknown_delivery');
        }
        $path = $this->storage->getAbsolutePath($file->getFilePath());
        if (!is_file($path)) {
            throw new CloudDeliveryException(Response::HTTP_NOT_FOUND, 'unknown_delivery');
        }

        return ['path' => $path, 'name' => $file->getDisplayName(), 'mime' => $file->getFileMime()];
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
        if (!in_array($status, [CloudDelivery::STATE_DELIVERED, CloudDelivery::STATE_FAILED], true)) {
            return ['state' => $delivery->getState()];
        }
        if (CloudDelivery::STATE_FAILED === $delivery->getState()
            && DestinationFailureCode::Unconfirmed->value === $delivery->getErrorCode()
            && CloudDelivery::STATE_DELIVERED === $status) {
            $this->record($delivery, $body);

            return ['state' => $delivery->getState()];
        }
        if (CloudDelivery::STATE_QUEUED === $delivery->getState()) {
            $this->record($delivery, $body);
        }

        return ['state' => $delivery->getState()];
    }

    public function sweep(): int
    {
        return $this->deliveries->failQueuedOlderThan(
            time() - $this->unconfirmedAfter(),
            DestinationFailureCode::Unconfirmed->value,
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function record(CloudDelivery $delivery, array $body): void
    {
        $status = is_string($body['status'] ?? null) ? $body['status'] : '';
        if (CloudDelivery::STATE_DELIVERED === $status) {
            $delivery->markDelivered(
                is_string($body['remote_path'] ?? null) ? $body['remote_path'] : null,
                is_string($body['remote_id'] ?? null) ? $body['remote_id'] : null,
                is_string($body['open_url'] ?? null) ? $body['open_url'] : null,
            );
        } else {
            $code = is_string($body['error_code'] ?? null) && '' !== $body['error_code']
                ? $body['error_code']
                : DestinationFailureCode::Unreachable->value;
            $delivery->markFailed($code);
        }
        $this->deliveries->save($delivery);
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
