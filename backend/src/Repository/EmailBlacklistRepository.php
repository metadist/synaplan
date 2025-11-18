<?php

namespace App\Repository;

use App\Entity\EmailBlacklist;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class EmailBlacklistRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailBlacklist::class);
    }

    public function isBlacklisted(string $email): bool
    {
        $email = strtolower(trim($email));
        
        return $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.email = :email')
            ->setParameter('email', $email)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    public function addToBlacklist(string $email, ?string $reason = null, ?int $blacklistedBy = null): EmailBlacklist
    {
        $blacklist = new EmailBlacklist();
        $blacklist->setEmail($email);
        $blacklist->setReason($reason);
        $blacklist->setBlacklistedBy($blacklistedBy);

        $em = $this->getEntityManager();
        $em->persist($blacklist);
        $em->flush();

        return $blacklist;
    }

    public function removeFromBlacklist(string $email): void
    {
        $email = strtolower(trim($email));
        
        $this->createQueryBuilder('b')
            ->delete()
            ->where('b.email = :email')
            ->setParameter('email', $email)
            ->getQuery()
            ->execute();
    }
}

