<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CloudPickupFileRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A file this person already holds from one cloud, in one version.
 * Another cloud's file with the same id is not this row.
 */
#[ORM\Entity(repositoryClass: CloudPickupFileRepository::class)]
#[ORM\Table(name: 'BCLOUDPICKUPFILES')]
#[ORM\UniqueConstraint(name: 'uniq_pickup_file_version', columns: ['BOWNERID', 'BINSTANCEID', 'BREMOTEID', 'BVERSION'])]
class CloudPickupFile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'BID', type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'BOWNERID', type: 'bigint')]
    private int $ownerId;

    #[ORM\Column(name: 'BINSTANCEID', length: 191)]
    private string $instanceId;

    #[ORM\Column(name: 'BREMOTEID', length: 255)]
    private string $remoteId;

    #[ORM\Column(name: 'BVERSION', length: 255)]
    private string $version;

    #[ORM\Column(name: 'BFILEID', type: 'bigint')]
    private int $fileId;

    public function __construct(int $ownerId, string $instanceId, string $remoteId, string $version, int $fileId)
    {
        $this->ownerId = $ownerId;
        $this->instanceId = $instanceId;
        $this->remoteId = $remoteId;
        $this->version = $version;
        $this->fileId = $fileId;
    }

    public function getOwnerId(): int
    {
        return $this->ownerId;
    }

    public function getInstanceId(): string
    {
        return $this->instanceId;
    }

    public function getRemoteId(): string
    {
        return $this->remoteId;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getFileId(): int
    {
        return $this->fileId;
    }
}
