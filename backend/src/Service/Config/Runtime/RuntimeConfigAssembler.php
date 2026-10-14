<?php

declare(strict_types=1);

namespace App\Service\Config\Runtime;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds the public runtime payload from tagged contributors.
 *
 * `features` is merged one level down because that object is one response
 * key filled by several areas (chat, modules, account features). Every other
 * key belongs to exactly one contributor.
 */
final readonly class RuntimeConfigAssembler
{
    /**
     * @param iterable<RuntimeConfigContributorInterface> $contributors
     */
    public function __construct(
        #[AutowireIterator('app.runtime_config.contributor')]
        private iterable $contributors,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function assemble(Request $request, ?User $user): array
    {
        $context = new RuntimeConfigContext($request, $user);
        $merged = [];
        /** @var array<string, true> $featureFlags */
        $featureFlags = [];

        foreach ($this->contributors as $contributor) {
            foreach ($contributor->contribute($context) as $key => $value) {
                if ('features' === $key && is_array($value)) {
                    $merged['features'] = $this->mergeFeatures(
                        is_array($merged['features'] ?? null) ? $merged['features'] : [],
                        $value,
                        $featureFlags,
                        $contributor::class,
                    );

                    continue;
                }

                if (array_key_exists($key, $merged)) {
                    throw new \LogicException(sprintf('Runtime config key "%s" is claimed twice (second claimant: %s).', $key, $contributor::class));
                }

                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $incoming
     * @param array<string, true>  $seen
     *
     * @return array<string, mixed>
     */
    private function mergeFeatures(array $current, array $incoming, array &$seen, string $contributor): array
    {
        foreach ($incoming as $flag => $value) {
            $name = (string) $flag;
            if (isset($seen[$name])) {
                throw new \LogicException(sprintf('Runtime config flag "features.%s" is claimed twice (second claimant: %s).', $name, $contributor));
            }
            $seen[$name] = true;
            $current[$name] = $value;
        }

        return $current;
    }
}
