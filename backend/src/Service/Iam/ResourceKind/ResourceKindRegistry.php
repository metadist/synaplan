<?php

declare(strict_types=1);

namespace App\Service\Iam\ResourceKind;

use App\Service\Iam\Exception\UnknownResourceKindException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class ResourceKindRegistry
{
    /** @var array<string, ShareableResourceKindInterface> */
    private array $kinds;

    /**
     * @param iterable<ShareableResourceKindInterface> $kinds
     */
    public function __construct(
        #[AutowireIterator('app.iam.resource_kind')]
        iterable $kinds,
        private ?PluginResourceKindCatalog $pluginKinds = null,
    ) {
        $indexed = [];
        foreach ($kinds as $kind) {
            $indexed[$kind->key()] = $kind;
        }
        $this->kinds = $indexed;
    }

    public function get(string $key): ShareableResourceKindInterface
    {
        if (isset($this->kinds[$key])) {
            return $this->kinds[$key];
        }
        $plugin = $this->pluginKinds?->get($key);
        if (null !== $plugin) {
            return $plugin;
        }

        throw new UnknownResourceKindException($key);
    }

    public function has(string $key): bool
    {
        return isset($this->kinds[$key]) || (null !== $this->pluginKinds && $this->pluginKinds->has($key));
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->kinds);
    }
}
