<?php

namespace App\Repository;

use App\Entity\Chat;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ChatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Chat::class);
    }

    public function findByUser(int $userId): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.userId = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('c.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByShareToken(string $token): ?Chat
    {
        return $this->findOneBy(['shareToken' => $token]);
    }

    public function findPublicByShareToken(string $token): ?Chat
    {
        return $this->findOneBy([
            'shareToken' => $token,
            'isPublic' => true
        ]);
    }
}

