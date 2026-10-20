<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CloudDeliveryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One copy of a Synaplan file into a linked cloud. The row stays until the
 * cloud confirms it, or until the sweep gives up.
 */
#[ORM\Entity(repositoryClass: CloudDeliveryRepository::class)]
#[ORM\Table(name: 'BCLOUDDELIVERIES')]
#[ORM\Index(columns: ['BOWNERID', 'BFILEID'], name: 'idx_cloud_delivery_owner_file')]
#[ORM\Index(columns: ['BSTATE', 'BCREATED'], name: 'idx_cloud_delivery_state_created')]
class CloudDelivery
{
    public const STATE_QUEUED = 'queued';
    public const STATE_DELIVERED = 'delivered';
    public const STATE_FAILED = 'failed';

    public const TARGET_KIND_FOLDER = 'kind_folder';
    public const CONFLICT_RENAME = 'rename';
    public const CONFLICT_REPLACE_OWN = 'replace_own';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BOWNERID', type: 'bigint')]
    private int $ownerId;

    #[ORM\Column(name: 'BFILEID', type: 'bigint')]
    private int $fileId;

    #[ORM\Column(name: 'BIDENTITYID', type: 'bigint')]
    private int $identityId;

    #[ORM\Column(name: 'BTARGETKIND', length: 32)]
    private string $targetKind;

    #[ORM\Column(name: 'BTARGETREF', length: 255, nullable: true)]
    private ?string $targetRef;

    #[ORM\Column(name: 'BONCONFLICT', length: 16)]
    private string $onConflict;

    #[ORM\Column(name: 'BSTATE', length: 16)]
    private string $state = self::STATE_QUEUED;

    #[ORM\Column(name: 'BREMOTEPATH', length: 1024, nullable: true)]
    private ?string $remotePath = null;

    #[ORM\Column(name: 'BREMOTEID', length: 255, nullable: true)]
    private ?string $remoteId = null;

    #[ORM\Column(name: 'BOPENURL', length: 1024, nullable: true)]
    private ?string $openUrl = null;

    #[ORM\Column(name: 'BERRORCODE', length: 64, nullable: true)]
    private ?string $errorCode = null;

    #[ORM\Column(name: 'BRUNID', type: 'bigint', nullable: true)]
    private ?int $runId = null;

    #[ORM\Column(name: 'BWATCHID', type: 'bigint', nullable: true)]
    private ?int $watchId = null;

    #[ORM\Column(name: 'BCREATED', type: 'bigint')]
    private int $created;

    #[ORM\Column(name: 'BFINISHED', type: 'bigint', nullable: true)]
    private ?int $finished = null;

    /** App and host at the time of the copy. They stay after the link is removed. */
    #[ORM\Column(name: 'BCLIENT', length: 32, nullable: true)]
    private ?string $destinationClient = null;

    #[ORM\Column(name: 'BHOST', length: 255, nullable: true)]
    private ?string $destinationHost = null;

    public function __construct(
        int $ownerId,
        int $fileId,
        int $identityId,
        string $targetKind,
        ?string $targetRef,
        string $onConflict,
    ) {
        $this->ownerId = $ownerId;
        $this->fileId = $fileId;
        $this->identityId = $identityId;
        $this->targetKind = $targetKind;
        $this->targetRef = $targetRef;
        $this->onConflict = $onConflict;
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

    public function getFileId(): int
    {
        return $this->fileId;
    }

    public function getIdentityId(): int
    {
        return $this->identityId;
    }

    public function getTargetKind(): string
    {
        return $this->targetKind;
    }

    public function getTargetRef(): ?string
    {
        return $this->targetRef;
    }

    public function getOnConflict(): string
    {
        return $this->onConflict;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getRemotePath(): ?string
    {
        return $this->remotePath;
    }

    public function getRemoteId(): ?string
    {
        return $this->remoteId;
    }

    public function getOpenUrl(): ?string
    {
        return $this->openUrl;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getCreated(): int
    {
        return $this->created;
    }

    public function markDelivered(?string $remotePath, ?string $remoteId, ?string $openUrl): void
    {
        $this->state = self::STATE_DELIVERED;
        $this->remotePath = $remotePath;
        $this->remoteId = $remoteId;
        $this->openUrl = $openUrl;
        $this->errorCode = null;
        $this->finished = time();
    }

    public function markFailed(string $errorCode): void
    {
        $this->state = self::STATE_FAILED;
        $this->errorCode = $errorCode;
        $this->finished = time();
    }

    public function rememberDestination(string $client, string $host): void
    {
        $this->destinationClient = $client;
        $this->destinationHost = $host;
    }

    public function getDestinationClient(): string
    {
        return $this->destinationClient ?? '';
    }

    public function getDestinationHost(): string
    {
        return $this->destinationHost ?? '';
    }
}
