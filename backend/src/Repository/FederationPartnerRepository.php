<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FederationPartner;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FederationPartner>
 */
class FederationPartnerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FederationPartner::class);
    }

    public function save(FederationPartner $partner): void
    {
        $this->getEntityManager()->persist($partner);
        $this->getEntityManager()->flush();
    }

    public function findByTokenHash(string $hash): ?FederationPartner
    {
        return $this->findOneBy(['tokenHash' => $hash]);
    }

    public function findLiveByDomain(string $domain): ?FederationPartner
    {
        foreach ($this->findBy(['peerDomain' => $domain]) as $partner) {
            if ($partner->isLive()) {
                return $partner;
            }
        }

        return null;
    }

    public function countInvited(): int
    {
        return $this->count(['status' => FederationPartner::STATUS_INVITED]);
    }

    /**
     * @return list<FederationPartner>
     */
    public function listCurrent(): array
    {
        /** @var list<FederationPartner> $rows */
        $rows = $this->createQueryBuilder('p')
            ->where('p.status IN (:statuses)')
            ->setParameter('statuses', [
                FederationPartner::STATUS_INVITED,
                FederationPartner::STATUS_ACTIVE,
                FederationPartner::STATUS_PAUSED,
            ])
            ->orderBy('p.updated', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @return list<FederationPartner>
     */
    public function listLive(): array
    {
        $out = [];
        foreach ($this->listCurrent() as $partner) {
            if ($partner->isLive()) {
                $out[] = $partner;
            }
        }

        return $out;
    }
}
