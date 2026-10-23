<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CloudPickupRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One request for a linked cloud to list or send files into a run.
 */
#[ORM\Entity(repositoryClass: CloudPickupRepository::class)]
#[ORM\Table(name: 'BCLOUDPICKUPS')]
#[ORM\Index(columns: ['BOWNERID', 'BCREATED'], name: 'idx_pickup_owner_created')]
class CloudPickup
{
    public const STATE_QUEUED = 'queued';
    public const STATE_CLAIMED = 'claimed';
    public const STATE_DONE = 'done';
    public const STATE_PARTIAL = 'partial';
    public const STATE_FAILED = 'failed';

    public const MODE_LIST = 'list';
    public const MODE_FETCH = 'fetch';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BOWNERID', type: 'bigint')]
    private int $ownerId;

    #[ORM\Column(name: 'BIDENTITYID', type: 'bigint')]
    private int $identityId;

    #[ORM\Column(name: 'BINSTANCEID', length: 191)]
    private string $instanceId;

    #[ORM\Column(name: 'BMODE', length: 16)]
    private string $mode;

    #[ORM\Column(name: 'BSELECTOR', type: 'text')]
    private string $selector;

    #[ORM\Column(name: 'BSTATE', length: 16)]
    private string $state = self::STATE_QUEUED;

    #[ORM\Column(name: 'BLISTED', type: 'integer', options: ['default' => 0])]
    private int $listed = 0;

    #[ORM\Column(name: 'BSENT', type: 'integer', options: ['default' => 0])]
    private int $sent = 0;

    #[ORM\Column(name: 'BSKIPPED', type: 'text', nullable: true)]
    private ?string $skipped = null;

    #[ORM\Column(name: 'BFILEIDS', type: 'text', nullable: true)]
    private ?string $fileIds = null;

    #[ORM\Column(name: 'BNAMES', type: 'text', nullable: true)]
    private ?string $names = null;

    #[ORM\Column(name: 'BACCEPTCOUNT', type: 'integer', options: ['default' => 0])]
    private int $acceptCount = 0;

    #[ORM\Column(name: 'BACCEPTBYTES', type: 'integer', options: ['default' => 0])]
    private int $acceptBytes = 0;

    #[ORM\Column(name: 'BERRORCODE', length: 64, nullable: true)]
    private ?string $errorCode = null;

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    #[ORM\Column(name: 'BFINISHED', type: 'bigint', nullable: true)]
    private ?int $finished = null;

    /**
     * @param array<string, mixed> $selector
     */
    public function __construct(int $ownerId, int $identityId, string $instanceId, string $mode, array $selector)
    {
        $this->ownerId = $ownerId;
        $this->identityId = $identityId;
        $this->instanceId = $instanceId;
        $this->mode = $mode;
        $this->selector = json_encode($selector, JSON_THROW_ON_ERROR);
        $this->created = time();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwnerId(): int
    {
        return $this->ownerId;
    }

    public function getIdentityId(): int
    {
        return $this->identityId;
    }

    public function getInstanceId(): string
    {
        return $this->instanceId;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    /**
     * @return array<string, mixed>
     */
    public function selector(): array
    {
        $decoded = json_decode($this->selector, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getListed(): int
    {
        return $this->listed;
    }

    public function getSent(): int
    {
        return $this->sent;
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    public function markClaimed(): void
    {
        $this->state = self::STATE_CLAIMED;
    }

    public function addListed(int $count): void
    {
        $this->listed += $count;
    }

    public function addSent(): void
    {
        ++$this->sent;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * @return list<int>
     */
    public function fileIds(): array
    {
        return $this->ints($this->fileIds);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        $decoded = null !== $this->names ? json_decode($this->names, true) : [];
        if (!is_array($decoded)) {
            return [];
        }
        $names = [];
        foreach ($decoded as $name) {
            if (is_string($name) && '' !== $name) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function skipped(): array
    {
        $decoded = null !== $this->skipped ? json_decode($this->skipped, true) : [];
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_array'));
    }

    public function rememberFile(int $fileId): void
    {
        $ids = $this->fileIds();
        $ids[] = $fileId;
        $this->fileIds = json_encode(array_values(array_unique($ids)), JSON_THROW_ON_ERROR);
    }

    public function acceptCount(): int
    {
        return $this->acceptCount;
    }

    public function acceptBytes(): int
    {
        return $this->acceptBytes;
    }

    public function addAccepted(int $files, int $bytes): void
    {
        $this->acceptCount += $files;
        $this->acceptBytes += $bytes;
    }

    public function rememberName(string $name): void
    {
        if ('' === $name) {
            return;
        }
        $names = $this->names();
        $names[] = $name;
        $this->names = json_encode($names, JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<int>
     */
    private function ints(?string $json): array
    {
        $decoded = null !== $json ? json_decode($json, true) : [];
        if (!is_array($decoded)) {
            return [];
        }
        $ids = [];
        foreach ($decoded as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * @param list<array<string, mixed>> $skipped
     */
    public function finish(string $state, int $listed, int $sent, array $skipped, ?string $errorCode): void
    {
        $this->state = $state;
        $this->listed = $listed;
        $this->sent = $sent;
        $this->skipped = json_encode($skipped, JSON_THROW_ON_ERROR);
        $this->errorCode = $errorCode;
        $this->finished = time();
    }
}
