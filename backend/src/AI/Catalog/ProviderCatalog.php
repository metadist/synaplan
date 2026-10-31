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
    private const BUILT_IN_NAMESPACE = 'App\\AI\\Provider\\';

    private static ?self $builtIn = null;

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
     * The providers shipped in this source tree: every class under
     * `src/AI/Provider` that carries `#[AsAiProvider]`, found by scanning the
     * directory once per process. For code that runs without a container —
     * a provider built with `new` in a test, a static helper an adapter
     * calls about itself. The container's catalog is the canonical one: it
     * also holds providers a plugin registers.
     */
    public static function builtIn(): self
    {
        return self::$builtIn ??= new self(self::discoverBuiltIn());
    }

    /**
     * @return list<class-string>
     */
    private static function discoverBuiltIn(): array
    {
        $base = \dirname(__DIR__).'/Provider';
        $classes = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $relative = substr($file->getPathname(), \strlen($base) + 1, -4);
            $class = self::BUILT_IN_NAMESPACE.str_replace('/', '\\', $relative);
            if (!class_exists($class)) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract() || [] === $reflection->getAttributes(Attribute\AsAiProvider::class)) {
                continue;
            }
            $classes[] = $class;
        }
        sort($classes);

        return $classes;
    }

    /**
     * @return list<ProviderDescriptor>
     */
    public function all(): array
    {
        return array_values($this->load());
    }

    /**
     * The classes this catalog reads, as given.
     *
     * @return list<class-string>
     */
    public function providerClasses(): array
    {
        return $this->providerClasses;
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
