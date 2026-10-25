<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Lead;

use App\Entity\Model;
use App\Repository\ModelRepository;

/** Reads the catalog key from the model row. A missing row has no key. */
final readonly class ModelLeadCatalog implements LeadCatalog
{
    public function __construct(private ModelRepository $models)
    {
    }

    public function key(int $modelId): ?string
    {
        $model = $this->models->find($modelId);
        if (!$model instanceof Model) {
            return null;
        }
        $service = $model->getService();
        $provider = $model->getProviderId();
        $tag = $model->getTag();
        if ('' === $service || '' === $provider || '' === $tag) {
            return null;
        }

        return $service.':'.$provider.':'.$tag;
    }
}
