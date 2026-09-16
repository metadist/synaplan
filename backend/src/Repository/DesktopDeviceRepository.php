<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DesktopDevice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DesktopDevice>
 */
class DesktopDeviceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DesktopDevice::class);
    }

    /**
     * All devices owned by a user, newest first.
     *
     * @return list<DesktopDevice>
     */
    public function findByOwner(int $ownerId): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.ownerId = :ownerId')
            ->setParameter('ownerId', $ownerId)
            ->orderBy('d.created', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * A device by id, only if it belongs to the given owner (404-not-403 for a
     * foreign id, same as Saved Tasks).
     */
    public function findOwnedById(int $id, int $ownerId): ?DesktopDevice
    {
        return $this->findOneBy(['id' => $id, 'ownerId' => $ownerId]);
    }

    /**
     * Resolve the device backing an API key, if any. Used by the job/check-in
     * loop to update BLASTSEEN and to bind a check-in to its device.
     */
    public function findByApiKeyId(int $apiKeyId): ?DesktopDevice
    {
        return $this->findOneBy(['apiKeyId' => $apiKeyId]);
    }

    /**
     * @return list<DesktopDevice>
     */
    public function findActiveByOwner(int $ownerId): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.ownerId = :ownerId')
            ->andWhere('d.status = :status')
            ->setParameter('ownerId', $ownerId)
            ->setParameter('status', DesktopDevice::STATUS_ACTIVE)
            ->orderBy('d.created', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function save(DesktopDevice $device, bool $flush = true): void
    {
        $this->getEntityManager()->persist($device);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(DesktopDevice $device, bool $flush = true): void
    {
        $this->getEntityManager()->remove($device);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
