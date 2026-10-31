<?php

declare(strict_types=1);

namespace App\AI\Catalog;

/**
 * Every provider descriptor of this build, read once from the classes the
 * {@see DependencyInjection\ProviderCatalogPass} collected.
 * Never instantiates a provider: `descriptor()` is static.
 *
 * Order: keyed providers in their Admin → AI keys order, then every other
 * provider by name ({@see ProviderDescriptor::rank()}).
 */
final class ProviderCatalog
{
    /** @var array<string, ProviderDescriptor>|null name => descriptor, in display order */
    private ?array $byName = null;

    /**
     * @param list<class-string> $providerClasses checked against {@see DescribesProviderInterface} on first read
     */
    public function __construct(
        private readonly array $providerClasses,
    ) {
    }

    /**
     * @return list<ProviderDescriptor>
     */
    public function all(): array
    {
        return array_values($this->load());
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->load());
    }

    public function has(string $name): bool
    {
        return isset($this->load()[strtolower(trim($name))]);
    }

    public function get(string $name): ProviderDescriptor
    {
        $key = strtolower(trim($name));
        $descriptor = $this->load()[$key] ?? null;
        if (null === $descriptor) {
            throw UnknownProviderException::forName($name, $this->names());
        }

        return $descriptor;
    }

    public function find(string $name): ?ProviderDescriptor
    {
        return $this->load()[strtolower(trim($name))] ?? null;
    }

    /**
     * @return list<ProviderDescriptor>
     */
    public function withCapability(ProviderCapability $capability): array
    {
        return array_values(array_filter($this->all(), static fn (ProviderDescriptor $d): bool => $d->hasCapability($capability)));
    }

    /**
     * Providers whose platform key lives in the key store, in Admin → AI keys order.
     *
     * @return list<ProviderDescriptor>
     */
    public function withKey(): array
    {
        return array_values(array_filter($this->all(), static fn (ProviderDescriptor $d): bool => $d->hasPlatformKey()));
    }

    /**
     * Providers a user may also bring their own key for.
     *
     * @return list<ProviderDescriptor>
     */
    public function withUserKey(): array
    {
        return array_values(array_filter($this->all(), static fn (ProviderDescriptor $d): bool => $d->hasUserKey()));
    }

    /**
     * Every distinct host any provider calls, including key-validation hosts.
     *
     * @return list<string>
     */
    public function endpoints(): array
    {
        $hosts = [];
        foreach ($this->all() as $descriptor) {
            foreach ($descriptor->hosts() as $host) {
                $hosts[$host] = true;
            }
        }

        return array_keys($hosts);
    }

    /**
     * @return array<string, ProviderDescriptor>
     */
    private function load(): array
    {
        if (null !== $this->byName) {
            return $this->byName;
        }

        $descriptors = [];
        foreach ($this->providerClasses as $class) {
            if (!is_a($class, DescribesProviderInterface::class, true)) {
                throw new \LogicException(sprintf('%s is registered as an AI provider but does not implement %s.', $class, DescribesProviderInterface::class));
            }
            $descriptor = $class::descriptor();
            if (isset($descriptors[$descriptor->name])) {
                throw new \LogicException(sprintf('Two provider classes describe "%s": %s and %s.', $descriptor->name, $descriptors[$descriptor->name]['class'], $class));
            }
            $descriptors[$descriptor->name] = ['class' => $class, 'descriptor' => $descriptor];
        }

        uasort($descriptors, static function (array $a, array $b): int {
            $rank = $a['descriptor']->rank() <=> $b['descriptor']->rank();

            return 0 !== $rank ? $rank : strcmp($a['descriptor']->name, $b['descriptor']->name);
        });

        return $this->byName = array_map(static fn (array $entry): ProviderDescriptor => $entry['descriptor'], $descriptors);
    }
}
