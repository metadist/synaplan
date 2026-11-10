<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\UserLevelGrant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserLevelGrant>
 */
class UserLevelGrantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserLevelGrant::class);
    }

    /**
     * @return list<UserLevelGrant>
     */
    public function forUser(int $userId): array
    {
        return $this->findBy(['userId' => $userId], ['source' => 'ASC']);
    }

    /**
     * @param list<int> $userIds
     *
     * @return array<int, list<UserLevelGrant>>
     */
    public function forUsers(array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }

        $grouped = [];
        foreach ($this->findBy(['userId' => $userIds], ['source' => 'ASC']) as $grant) {
            $grouped[$grant->getUserId()][] = $grant;
        }

        return $grouped;
    }

    public function findOne(int $userId, string $source): ?UserLevelGrant
    {
        return $this->findOneBy(['userId' => $userId, 'source' => $source]);
    }

    public function countFor(int $userId): int
    {
        return $this->count(['userId' => $userId]);
    }
}
