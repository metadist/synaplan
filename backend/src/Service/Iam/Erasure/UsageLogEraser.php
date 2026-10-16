<?php

declare(strict_types=1);

namespace App\Service\Iam\Erasure;

use App\Entity\UseLog;
use App\Repository\UseLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: 180)]
final readonly class UsageLogEraser implements UserDataEraserInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private UseLogRepository $useLogs,
    ) {
    }

    public function erase(UserErasure $erasure): void
    {
        foreach ($this->useLogs->findBy(['userId' => $erasure->userId]) as $useLog) {
            $this->em->remove($useLog);
        }
    }

    public static function covers(): array
    {
        return [UseLog::class];
    }
}
