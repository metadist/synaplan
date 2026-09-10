<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Entity\Model;
use App\Model\ModelCatalog;
use App\Repository\ModelRepository;
use App\Service\Model\CapabilityCatalog;

/**
 * Optional per-file catalog-key overrides for vectorize / analyze.
 *
 * Omitted keys keep the account DEFAULTMODEL. Unknown or wrong-capability
 * keys fail closed (400) — never a silent fallback.
 */
final readonly class ProcessModelHints
{
    public function __construct(
        public ?int $vectorizeModelId = null,
        public ?int $analyzeModelId = null,
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    /**
     * @throws InvalidProcessModelHintException
     */
    public static function fromRaw(
        mixed $vectorizeKey,
        mixed $analyzeKey,
        ModelRepository $models,
    ): self {
        return new self(
            self::resolve($vectorizeKey, 'VECTORIZE', $models),
            self::resolve($analyzeKey, 'ANALYZE', $models),
        );
    }

    /**
     * @throws InvalidProcessModelHintException
     */
    private static function resolve(mixed $raw, string $capability, ModelRepository $models): ?int
    {
        if (null === $raw || '' === $raw) {
            return null;
        }
        if (!is_string($raw)) {
            throw new InvalidProcessModelHintException(sprintf('%s_model must be a catalog key string.', strtolower($capability)));
        }

        $key = trim($raw);
        if ('' === $key) {
            return null;
        }

        $bid = ModelCatalog::findBidByKey($key);
        if (null === $bid) {
            throw new InvalidProcessModelHintException(sprintf('Unknown %s model `%s`.', strtolower($capability), $key));
        }

        $model = $models->find($bid);
        if (!$model instanceof Model || 1 !== $model->getActive() || 1 !== $model->getSelectable()) {
            throw new InvalidProcessModelHintException(sprintf('Model `%s` is not selectable for %s.', $key, $capability));
        }

        $groups = CapabilityCatalog::groupsForTag($model->getTag(), $model->getFeatures());
        if (!in_array($capability, $groups, true)) {
            throw new InvalidProcessModelHintException(sprintf('Model `%s` is not a %s model.', $key, $capability));
        }

        return $bid;
    }
}
