<?php

declare(strict_types=1);

namespace App\Plug\Rerank;

use App\Entity\Model;
use App\Model\ModelCatalog;

/**
 * Catalog keys for rerank rows: service:providerId:tag with the same
 * normalisation {@see ModelCatalog::find()} uses (colons in providerId → dash).
 */
final class RerankCatalog
{
    public static function keyForModel(Model $model): string
    {
        return self::key($model->getService(), $model->getProviderId(), $model->getTag());
    }

    public static function key(string $service, string $providerId, string $tag): string
    {
        return strtolower($service).':'.strtolower(str_replace(':', '-', $providerId)).':'.strtolower($tag);
    }

    public static function bidByKey(string $key): ?int
    {
        return ModelCatalog::findBidByKey($key);
    }
}
