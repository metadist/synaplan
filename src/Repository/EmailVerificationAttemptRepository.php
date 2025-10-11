<?php

namespace App\Repository;

use App\Entity\EmailVerificationAttempt;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class EmailVerificationAttemptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailVerificationAttempt::class);
    }

    public function findByEmail(string $email): ?EmailVerificationAttempt
    {
        return $this->findOneBy(['email' => $email]);
    }

    public function cleanupOldAttempts(int $daysOld = 30): int
    {
        $date = new \DateTime();
        $date->modify("-{$daysOld} days");

        return $this->createQueryBuilder('e')
            ->delete()
            ->where('e.createdAt < :date')
            ->setParameter('date', $date)
            ->getQuery()
            ->execute();
    }
}

