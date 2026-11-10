<?php

declare(strict_types=1);

namespace App\Service\Iam\Erasure;

use App\Entity\UserLevelGrant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The reasons an account had a level. They name the person, so they go
 * with the account. Billing history in the payment record is a different
 * eraser's concern.
 */
#[AsTaggedItem(priority: 74)]
final readonly class LevelGrantEraser implements UserDataEraserInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function erase(UserErasure $erasure): void
    {
        $this->em->getConnection()->executeStatement(
            'DELETE FROM BUSER_LEVEL_GRANTS WHERE BUSERID = :userId',
            ['userId' => $erasure->userId],
        );
    }

    public static function covers(): array
    {
        return [UserLevelGrant::class];
    }
}
