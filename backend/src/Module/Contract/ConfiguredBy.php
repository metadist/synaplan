<?php

declare(strict_types=1);

namespace App\Module\Contract;

/**
 * What makes a module "configured": the env keys, BCONFIG rows and runtime
 * provider/plug keys it reads. Documentation, `app:modules:list`,
 * `app:config:doctor` and the ownership architecture test all read this;
 * nothing at request time does.
 */
final readonly class ConfiguredBy
{
    /**
     * @param list<string> $envKeys      environment variable names, e.g. `TIKA_BASE_URL`
     * @param list<string> $bconfigKeys  `GROUP.SETTING` rows in BCONFIG, e.g. `MODULES.GATE_TIKA`
     * @param list<string> $providerKeys provider names in ProviderKeyStore, e.g. `higgsfield`
     * @param list<string> $plugKeys     plug adapter keys in PlugKeyStore, e.g. `searxng`
     */
    public function __construct(
        public array $envKeys = [],
        public array $bconfigKeys = [],
        public array $providerKeys = [],
        public array $plugKeys = [],
    ) {
    }

    public static function env(string ...$envKeys): self
    {
        return new self(envKeys: array_values($envKeys));
    }

    public function isEmpty(): bool
    {
        return [] === $this->envKeys
            && [] === $this->bconfigKeys
            && [] === $this->providerKeys
            && [] === $this->plugKeys;
    }

    /**
     * @return array{env: list<string>, bconfig: list<string>, providers: list<string>, plugs: list<string>}
     */
    public function toArray(): array
    {
        return [
            'env' => $this->envKeys,
            'bconfig' => $this->bconfigKeys,
            'providers' => $this->providerKeys,
            'plugs' => $this->plugKeys,
        ];
    }
}
