<?php

declare(strict_types=1);

namespace App\Service\Destination;

final class DestinationRegistry
{
    /** @var array<string, DestinationProvider> */
    private array $byId = [];

    /**
     * @param iterable<DestinationProvider> $providers
     */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $this->byId[$provider->id()] = $provider;
        }
    }

    public function get(string $id): DestinationProvider
    {
        if (!isset($this->byId[$id])) {
            throw new UnknownDestinationException($id);
        }

        return $this->byId[$id];
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->byId);
    }
}
