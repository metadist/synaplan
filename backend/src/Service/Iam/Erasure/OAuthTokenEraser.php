<?php

declare(strict_types=1);

namespace App\Service\Iam\Erasure;

use App\Entity\OAuthToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The OAuth2 grants clouds hold for the user: access tokens, refresh tokens
 * and codes issued for them. Deleting the rows ends every grant at once.
 */
#[AsTaggedItem(priority: 194)]
final readonly class OAuthTokenEraser implements UserDataEraserInterface
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function erase(UserErasure $erasure): void
    {
        $this->em->createQueryBuilder()
            ->delete(OAuthToken::class, 't')
            ->where('t.userId = :user')
            ->setParameter('user', $erasure->userId)
            ->getQuery()
            ->execute();
    }

    public static function covers(): array
    {
        return [OAuthToken::class];
    }
}
