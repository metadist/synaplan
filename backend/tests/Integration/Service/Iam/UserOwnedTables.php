<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Iam;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * The tables an account owns: every mapped entity except User itself that
 * holds the user id in a `userId` / `ownerId` field or in a to-one
 * association to User. The coverage test and the no-row test read the same
 * list, so a table added later is checked by both without editing either.
 */
final class UserOwnedTables
{
    private const OWNER_FIELDS = ['userId', 'ownerId'];

    /**
     * @return array<class-string, string> entity class => column that holds the user id, sorted by class
     */
    public static function in(EntityManagerInterface $em): array
    {
        $owned = [];
        foreach ($em->getMetadataFactory()->getAllMetadata() as $metadata) {
            $column = self::ownerColumn($metadata);
            if (null !== $column && User::class !== $metadata->getName()) {
                $owned[$metadata->getName()] = $column;
            }
        }
        ksort($owned);

        return $owned;
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private static function ownerColumn(ClassMetadata $metadata): ?string
    {
        foreach (self::OWNER_FIELDS as $field) {
            if ($metadata->hasField($field)) {
                return $metadata->getColumnName($field);
            }
        }
        foreach ($metadata->getAssociationMappings() as $association) {
            if (User::class === $association->targetEntity && $metadata->isAssociationWithSingleJoinColumn($association->fieldName)) {
                return $metadata->getSingleAssociationJoinColumnName($association->fieldName);
            }
        }

        return null;
    }
}
