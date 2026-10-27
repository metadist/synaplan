<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Entity\ExternalIdentity;
use App\Entity\File;
use App\Entity\PlatformInstance;
use App\Entity\User;
use App\Repository\ExternalIdentityRepository;
use App\Repository\FileRepository;
use App\Repository\UserRepository;
use App\Service\CloudWatch\WatchTargetFollow;
use App\Service\Iam\AuditLogWriter;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One cloud telling Synaplan that its files changed, moved, disappeared or came back.
 */
final readonly class CloudSourceEvents
{
    public const GRACE_SECONDS = 604800;

    public function __construct(
        private FileRepository $files,
        private ExternalIdentityRepository $identities,
        private UserRepository $users,
        private FileUploadService $uploads,
        private VectorStorageFacade $vectors,
        private CloudFileIndexGuard $indexGuard,
        private AuditLogWriter $audit,
        private EntityManagerInterface $entityManager,
        private WatchTargetFollow $targets,
    ) {
    }

    /**
     * @param array<mixed> $body
     *
     * @return array{applied: int, ignored: int, reupload: list<array{external_id: string, id: string}>}
     */
    public function apply(PlatformInstance $instance, array $body, string $ip): array
    {
        $events = $this->events($body);
        $applied = 0;
        $ignored = 0;
        $reupload = [];
        $this->withoutDeletedFilter(function () use ($instance, $events, $ip, &$applied, &$ignored, &$reupload): void {
            foreach ($events as $event) {
                $copies = array_values(array_filter(
                    $this->files->findCopiesOfRemotes($instance->getClient(), [$event['id']], $instance->getInstanceId()),
                    fn (File $file): bool => $this->belongsTo($file, $instance),
                ));
                $followed = $this->targets->apply($instance, $event['event'], $event['id'], time());
                $copiesChanged = [] !== $copies && $this->applyOne($instance, $event, $copies, $reupload);
                if (!$followed && !$copiesChanged) {
                    ++$ignored;
                    continue;
                }
                ++$applied;
                $this->audit->record(0, 'platform_link.source_event', 'platform_instance', $instance->getInstanceId(), [
                    'event' => $event['event'],
                    'id' => $event['id'],
                ], $ip);
            }
            $this->entityManager->flush();
        });

        return ['applied' => $applied, 'ignored' => $ignored, 'reupload' => $reupload];
    }

    /**
     * @param list<File>                                                      $copies
     * @param list<array{external_id: string, id: string}>                    $reupload
     * @param array{id: string, event: string, version: string, path: string} $event
     */
    private function applyOne(PlatformInstance $instance, array $event, array $copies, array &$reupload): bool
    {
        $changed = false;
        foreach ($copies as $file) {
            $changed = match ($event['event']) {
                'changed' => $this->changed($instance, $file, $event, $reupload) || $changed,
                'moved' => $this->moved($file, $event['path']) || $changed,
                'deleted' => $this->deleted($file) || $changed,
                'restored' => $this->restored($file) || $changed,
                default => $changed,
            };
        }

        return $changed;
    }

    /**
     * @param list<array{external_id: string, id: string}>                    $reupload
     * @param array{id: string, event: string, version: string, path: string} $event
     */
    private function changed(PlatformInstance $instance, File $file, array $event, array &$reupload): bool
    {
        if (null !== $file->getSourceDeletedAt()) {
            return false;
        }
        $file->setStale(true);
        $file->setVectorState(File::VECTOR_STATE_STALE);
        if (!$file->isKeepSynced()) {
            return true;
        }
        $externalId = $this->externalId($file, $instance);
        if (null === $externalId) {
            return true;
        }
        $entry = ['external_id' => $externalId, 'id' => $event['id']];
        if (!in_array($entry, $reupload, true)) {
            $reupload[] = $entry;
        }

        return true;
    }

    private function moved(File $file, string $path): bool
    {
        if ('' === $path || null !== $file->getSourceDeletedAt()) {
            return false;
        }
        $name = basename($path);
        if (strlen($path) > 255 || strlen($name) > 255 || '' === $name || '.' === $name || '..' === $name) {
            return false;
        }
        $file->setFileName($name);
        $file->setOriginalName($path);

        return true;
    }

    private function deleted(File $file): bool
    {
        if (null !== $file->getSourceDeletedAt()) {
            return false;
        }
        $this->indexGuard->exclusive((int) $file->getId(), function () use ($file): void {
            $file->setSourceDeletedAt(time());
            $file->setStale(false);
            $file->setVectorState(File::VECTOR_STATE_NONE);
            $this->entityManager->flush();
            $this->vectors->deleteByFile($file->getUserId(), (int) $file->getId());
        });

        return true;
    }

    private function restored(File $file): bool
    {
        $deletedAt = $file->getSourceDeletedAt();
        if (null === $deletedAt || $deletedAt < time() - self::GRACE_SECONDS) {
            return false;
        }
        $file->setSourceDeletedAt(null);
        $file->setStale(false);
        $user = $this->users->find($file->getUserId());
        $result = $user instanceof User
            ? $this->uploads->reVectorize($file, $user, (string) ($file->getGroupKey() ?? ''))
            : ['success' => false];
        if (true !== $result['success']) {
            $file->setStatus('error');
            $file->setVectorState(File::VECTOR_STATE_FAILED);
        }

        return true;
    }

    private function externalId(File $file, PlatformInstance $instance): ?string
    {
        foreach ($this->identities->findByUserId((int) $file->getUserId()) as $identity) {
            if ($identity->getSource() === $instance->getClient() && $identity->getInstanceId() === $instance->getInstanceId()) {
                $externalId = $identity->getExternalId();

                return '' === $externalId ? null : $externalId;
            }
        }

        return null;
    }

    private function belongsTo(File $file, PlatformInstance $instance): bool
    {
        if ($file->getSourceInstance() === $instance->getInstanceId()) {
            return true;
        }
        if (null !== $file->getSourceInstance()) {
            return false;
        }
        $rows = array_values(array_filter(
            $this->identities->findByUserId((int) $file->getUserId()),
            static fn (ExternalIdentity $identity): bool => $identity->getSource() === $instance->getClient(),
        ));

        return 1 === count($rows) && ('' === $rows[0]->getInstanceId() || $rows[0]->getInstanceId() === $instance->getInstanceId());
    }

    /**
     * @param array<mixed> $body
     *
     * @return list<array{id: string, event: string, version: string, path: string}>
     */
    private function events(array $body): array
    {
        $raw = $body['events'] ?? null;
        if (!is_array($raw) || [] === $raw || count($raw) > 500) {
            throw new CloudFileRequestException('Send between 1 and 500 events.');
        }
        $events = [];
        foreach ($raw as $event) {
            if (!is_array($event) || !is_string($event['id'] ?? null) || !is_string($event['event'] ?? null)) {
                throw new CloudFileRequestException('Each event needs a string id and event.');
            }
            if (!in_array($event['event'], ['changed', 'moved', 'deleted', 'restored'], true) || strlen($event['id']) > 255) {
                throw new CloudFileRequestException('An event is not changed, moved, deleted or restored, or its id is too long.');
            }
            $version = $event['version'] ?? '';
            $path = $event['path'] ?? '';
            if (!is_string($version) || !is_string($path) || strlen($version) > 255 || strlen($path) > 255) {
                throw new CloudFileRequestException('Version and path must fit in 255 characters.');
            }
            $events[] = ['id' => $event['id'], 'event' => $event['event'], 'version' => $version, 'path' => $path];
        }

        return $events;
    }

    private function withoutDeletedFilter(callable $read): void
    {
        $filters = $this->entityManager->getFilters();
        $wasOn = $filters->isEnabled('source_deleted');
        if ($wasOn) {
            $filters->disable('source_deleted');
        }
        try {
            $read();
        } finally {
            if ($wasOn) {
                $filters->enable('source_deleted');
            }
        }
    }
}
