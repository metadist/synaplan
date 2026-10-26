<?php

declare(strict_types=1);

namespace App\Service\Iam\Erasure;

use App\Entity\SignupCodeUse;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The redemption rows of the person being erased. The administrator keeps
 * what matters for the code itself — its use counter — so a code does not
 * reopen a slot when an account that used it is deleted; the row that named
 * the person goes with the person.
 */
#[AsTaggedItem(priority: 75)]
final readonly class SignupCodeUseEraser implements UserDataEraserInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function erase(UserErasure $erasure): void
    {
        $this->em->getConnection()->executeStatement(
            'DELETE FROM BSIGNUP_CODE_USES WHERE BUSERID = :userId',
            ['userId' => $erasure->userId],
        );
    }

    public static function covers(): array
    {
        return [SignupCodeUse::class];
    }
}
