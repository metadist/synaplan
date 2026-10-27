<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Entity\ExternalIdentity;
use App\Entity\File;
use App\Entity\PlatformInstance;
use App\Entity\User;
use App\Repository\ExternalIdentityRepository;
use App\Repository\FileRepository;
use App\Repository\PlatformInstanceRepository;
use App\Service\Infrastructure\RedisService;
use App\Service\PlatformLink\SourceInstanceResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Batched bird state for one folder view, cached for 60 seconds.
 */
final readonly class CloudFileStatus
{
    private const MAX_ITEMS = 500;
    private const MAX_FIELD = 255;
    private const CACHE_SECONDS = 60;

    public function __construct(
        private FileRepository $files,
        private SourceInstanceResolver $instances,
        private PlatformInstanceRepository $platforms,
        private ExternalIdentityRepository $identities,
        private RedisService $redis,
        private CloudFileStatusCache $cache,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<mixed> $body
     *
     * @return array{items: list<array<string, mixed>>, watches: list<array<string, mixed>>, cache: string}
     */
    public function status(User $user, Request $request, array $body): array
    {
        $items = $this->items($body);
        $instanceId = $this->instances->fromRequest($request) ?? '';
        $key = 'cloud_status:'.(int) $user->getId().':'.$instanceId.':'.$this->cache->generation((int) $user->getId()).':'.hash('sha256', (string) json_encode($items));
        $cached = $this->redis->get($key);
        if (is_string($cached)) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded) && isset($decoded['items'], $decoded['watches'])) {
                /* @var array{items: list<array<string, mixed>>, watches: list<array<string, mixed>>} $decoded */
                return $decoded + ['cache' => 'hit'];
            }
        }

        $platform = '' === $instanceId ? null : $this->platforms->findByInstanceId($instanceId);
        $answer = [
            'items' => $this->fresh($user, $platform, $items),
            'watches' => [],
        ];
        $this->redis->set($key, (string) json_encode($answer), self::CACHE_SECONDS);

        return $answer + ['cache' => 'miss'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function detail(User $user, int $fileId, Request $request): ?array
    {
        $file = $this->withoutDeletedFilter(fn (): ?File => $this->files->find($fileId));
        if (!$file instanceof File || $file->getUserId() !== (int) $user->getId()) {
            return null;
        }
        $instanceId = $this->instances->fromRequest($request) ?? '';
        $platform = '' === $instanceId ? null : $this->platforms->findByInstanceId($instanceId);
        if ($platform instanceof PlatformInstance && !$this->belongsTo($file, $platform)) {
            return null;
        }

        return $this->row($file->getSourceId() ?? (string) $fileId, $file, $file->getSourceEtag() ?? '');
    }

    /**
     * @param list<array{id: string, version: string}> $items
     *
     * @return list<array<string, mixed>>
     */
    private function fresh(User $user, ?PlatformInstance $platform, array $items): array
    {
        $ids = array_values(array_unique(array_map(static fn (array $item): string => $item['id'], $items)));
        $byId = [];
        if ($platform instanceof PlatformInstance) {
            $copies = $this->withoutDeletedFilter(
                fn (): array => $this->files->findCopiesOfRemotes($platform->getClient(), $ids, $platform->getInstanceId(), (int) $user->getId()),
            );
            foreach ($copies as $file) {
                if ($file->getUserId() !== (int) $user->getId() || !$this->belongsTo($file, $platform)) {
                    continue;
                }
                $sourceId = $file->getSourceId();
                if (null !== $sourceId && !isset($byId[$sourceId])) {
                    $byId[$sourceId] = $file;
                }
            }
        }

        $rows = [];
        foreach ($items as $item) {
            $rows[] = $this->row($item['id'], $byId[$item['id']] ?? null, $item['version']);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $remoteId, ?File $file, string $version): array
    {
        $key = $file?->getGroupKey();
        $folder = null !== $key && '' !== $key && 'DEFAULT' !== $key ? ['key' => $key, 'name' => $key] : null;

        return [
            'id' => $remoteId,
            'state' => CloudFileState::of($file, $version),
            'knowledge_folder' => $folder,
            'file_id' => $file?->getId(),
            'kept_up_to_date' => $file instanceof File && $file->isKeepSynced() && null === $file->getSourceDeletedAt(),
        ];
    }

    private function belongsTo(File $file, PlatformInstance $platform): bool
    {
        if ($file->getSource() !== $platform->getClient()) {
            return false;
        }
        if ($file->getSourceInstance() === $platform->getInstanceId()) {
            return true;
        }
        if (null !== $file->getSourceInstance()) {
            return false;
        }
        $rows = array_values(array_filter(
            $this->identities->findByUserId((int) $file->getUserId()),
            static fn (ExternalIdentity $identity): bool => $identity->getSource() === $platform->getClient(),
        ));

        return 1 === count($rows) && ('' === $rows[0]->getInstanceId() || $rows[0]->getInstanceId() === $platform->getInstanceId());
    }

    /**
     * @return list<array{id: string, version: string}>
     */
    private function items(array $body): array
    {
        $raw = $body['items'] ?? null;
        if (!is_array($raw) || [] === $raw || count($raw) > self::MAX_ITEMS) {
            throw new CloudFileRequestException('Send between 1 and 500 items.');
        }
        $items = [];
        foreach ($raw as $item) {
            if (!is_array($item) || !is_string($item['id'] ?? null) || !is_string($item['version'] ?? null)) {
                throw new CloudFileRequestException('Each item needs a string id and version.');
            }
            if (strlen($item['id']) > self::MAX_FIELD || strlen($item['version']) > self::MAX_FIELD) {
                throw new CloudFileRequestException('An id or version is longer than 255 characters.');
            }
            $items[] = ['id' => $item['id'], 'version' => $item['version']];
        }

        return $items;
    }

    /**
     * @template T
     *
     * @param callable(): T $read
     *
     * @return T
     */
    private function withoutDeletedFilter(callable $read): mixed
    {
        $filters = $this->entityManager->getFilters();
        $wasOn = $filters->isEnabled('source_deleted');
        if ($wasOn) {
            $filters->disable('source_deleted');
        }
        try {
            return $read();
        } finally {
            if ($wasOn) {
                $filters->enable('source_deleted');
            }
        }
    }
}
