<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\File;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * A cloud file whose source was deleted stays in the table for 7 days and
 * stays out of the Library while it does.
 */
final class SourceDeletedFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if (File::class !== $targetEntity->getName()) {
            return '';
        }

        return $targetTableAlias.'.BSOURCEDELETEDAT IS NULL';
    }
}
