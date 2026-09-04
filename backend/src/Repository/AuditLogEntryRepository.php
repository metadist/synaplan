<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuditLogEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditLogEntry>
 */
class AuditLogEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLogEntry::class);
    }

    public function save(AuditLogEntry $entry, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entry);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
