<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SavedPrompt;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SavedPrompt>
 */
class SavedPromptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SavedPrompt::class);
    }

    /**
     * @return list<SavedPrompt>
     */
    public function findByUser(int $userId): array
    {
        return $this->findBy(['userId' => $userId], ['name' => 'ASC']);
    }

    public function findOwned(int $id, int $userId): ?SavedPrompt
    {
        $prompt = $this->find($id);
        if (!$prompt instanceof SavedPrompt || $prompt->getUserId() !== $userId) {
            return null;
        }

        return $prompt;
    }

    public function findByCommand(int $userId, string $command): ?SavedPrompt
    {
        $prompt = $this->findOneBy(['userId' => $userId, 'command' => $command]);

        return $prompt instanceof SavedPrompt ? $prompt : null;
    }
}
