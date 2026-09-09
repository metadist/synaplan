<?php

declare(strict_types=1);

namespace App\AI\Import;

/**
 * Outcome of one discovery call against an import source.
 *
 * `ok` distinguishes "the endpoint answered with a (possibly empty) list" from
 * "the endpoint was unreachable". That difference is load-bearing for the
 * scheduled re-check (S5 `PL35`): a model missing from a successful listing is
 * soft-disabled, but an unreachable endpoint must mark nothing.
 */
final readonly class DiscoveryResult
{
    /**
     * @param list<DiscoveredModel> $models
     */
    public function __construct(
        public bool $ok,
        public array $models,
        public ?string $error = null,
    ) {
    }

    public static function unreachable(string $error): self
    {
        return new self(false, [], $error);
    }

    /**
     * @param list<DiscoveredModel> $models
     */
    public static function listed(array $models): self
    {
        return new self(true, $models, null);
    }
}
