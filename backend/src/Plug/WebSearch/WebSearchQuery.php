<?php

declare(strict_types=1);

namespace App\Plug\WebSearch;

/**
 * Provider-neutral search query. Extra Brave-shaped options stay in `options`.
 *
 * @phpstan-type SearchOptions array<string, mixed>
 */
final readonly class WebSearchQuery
{
    /**
     * @param SearchOptions $options
     */
    public function __construct(
        public string $query,
        public array $options = [],
    ) {
    }

    /**
     * @param SearchOptions $options
     */
    public static function fromLegacy(string $query, array $options = []): self
    {
        return new self($query, $options);
    }
}
