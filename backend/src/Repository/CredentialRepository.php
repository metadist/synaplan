<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Credential;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Credential>
 */
class CredentialRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Credential::class);
    }

    public function findByIdAndOwner(int $id, int $ownerId): ?Credential
    {
        return $this->findOneBy(['id' => $id, 'ownerId' => $ownerId]);
    }

    public function save(Credential $credential, bool $flush = true): void
    {
        $this->getEntityManager()->persist($credential);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Credential $credential, bool $flush = true): void
    {
        $this->getEntityManager()->remove($credential);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
