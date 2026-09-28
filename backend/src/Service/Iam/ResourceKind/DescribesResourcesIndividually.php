<?php

declare(strict_types=1);

namespace App\Service\Iam\ResourceKind;

/**
 * Fallback for kinds that have no bulk loader. Each distinct id is described
 * once, so a page that repeats the same resource does not repeat the query.
 */
trait DescribesResourcesIndividually
{
    /**
     * @param list<string> $resourceIds
     *
     * @return array<string, ResourceCard>
     */
    public function describeMany(array $resourceIds): array
    {
        $out = [];
        foreach (array_values(array_unique($resourceIds)) as $id) {
            $out[$id] = $this->describe($id);
        }

        return $out;
    }
}
