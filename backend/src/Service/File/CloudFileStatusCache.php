<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Entity\File;
use App\Service\Infrastructure\RedisService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/**
 * A status answer is cached for 60 seconds. Any change to that person's cloud
 * file drops the cache, so an upload or a deletion is visible on the next call.
 */
#[AsEntityListener(event: Events::postPersist, method: 'bump', entity: File::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'bump', entity: File::class)]
#[AsEntityListener(event: Events::postRemove, method: 'bump', entity: File::class)]
final readonly class CloudFileStatusCache
{
    public function __construct(private RedisService $redis)
    {
    }

    public function generation(int $userId): int
    {
        $value = $this->redis->get($this->key($userId));

        return is_string($value) && ctype_digit($value) ? (int) $value : 0;
    }

    public function bump(File $file): void
    {
        if (!in_array($file->getSource(), ['nextcloud', 'opencloud', 'owncloud'], true)) {
            return;
        }
        $this->redis->increment($this->key((int) $file->getUserId()));
    }

    private function key(int $userId): string
    {
        return 'cloud_status_gen:'.$userId;
    }
}
