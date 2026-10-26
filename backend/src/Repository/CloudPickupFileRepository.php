<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CloudPickupFile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CloudPickupFile>
 */
class CloudPickupFileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CloudPickupFile::class);
    }

    public function findHeld(int $ownerId, string $instanceId, string $remoteId, string $version): ?CloudPickupFile
    {
        $row = $this->findOneBy([
            'ownerId' => $ownerId,
            'instanceId' => $instanceId,
            'remoteId' => $remoteId,
            'version' => $version,
        ]);

        return $row instanceof CloudPickupFile ? $row : null;
    }

    public function deleteForUser(int $userId): void
    {
        $this->createQueryBuilder('f')
            ->delete()
            ->andWhere('f.ownerId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute();
    }

    /**
     * Inserts the mapping unless this person already holds that version.
     * The database decides the race; a lost insert leaves the entity manager open.
     */
    public function insertIgnore(CloudPickupFile $file): bool
    {
        $affected = $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT IGNORE INTO BCLOUDPICKUPFILES (BOWNERID, BINSTANCEID, BREMOTEID, BVERSION, BFILEID) VALUES (?, ?, ?, ?, ?)',
            [$file->getOwnerId(), $file->getInstanceId(), $file->getRemoteId(), $file->getVersion(), $file->getFileId()],
        );

        return $affected > 0;
    }

    public function save(CloudPickupFile $file): void
    {
        $this->getEntityManager()->persist($file);
        $this->getEntityManager()->flush();
    }
}
