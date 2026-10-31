<?php

declare(strict_types=1);

namespace App\Service\Egress;

use App\AI\Catalog\ProviderCatalog;

/**
 * Decides one destination. Built-in services, the hosts of every described
 * provider, the administrator's list, and the no-proxy hosts are allowed. A URL a person
 * typed is allowed only when the guard accepts it, and is not stored.
 */
final readonly class EgressPolicy
{
    public function __construct(
        private EgressConfig $config,
        private EgressTypedUrlGuard $typedUrls,
        private ProviderCatalog $providers,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function decide(string $url, array $options = []): EgressDecision
    {
        $destination = EgressDestination::fromUrl($url);
        if (null === $destination) {
            return EgressDecision::refuse('unparseable');
        }
        if (!\in_array($destination->scheme, ['http', 'https'], true)) {
            return EgressDecision::refuse('scheme');
        }

        // A URL a person typed is checked before the allowlist. Otherwise a
        // typed redirect to localhost would pass because localhost is a
        // built-in name, and the guard would never see it.
        if (EgressCall::isTyped($options) && !$this->typedUrls->allows($url)) {
            return EgressDecision::refuse('typed_refused');
        }

        foreach ($this->rules() as $rule) {
            if ($rule->matches($destination)) {
                return EgressDecision::allow($rule->label(), 'allowlist');
            }
        }

        if (EgressCall::isTyped($options)) {
            return EgressDecision::allow('typed', 'typed');
        }

        return EgressDecision::refuse('not_allowed');
    }

    /**
     * The metric label is the matched rule (`api.openai.com`, `*.example.org`,
     * `api.example.com:443`). A typed or refused call is `other`, so a new
     * host cannot grow the label set.
     *
     * @param array<string, mixed> $options
     */
    public function metricDestination(string $url, array $options = []): string
    {
        $decision = $this->decide($url, $options);
        if ($decision->allowed && null !== $decision->rule && 'typed' !== $decision->rule) {
            // The rule id is bounded: `api.openai.com`, `api.example.com:443`
            // or `*.example.org`. The concrete host of a wildcard is not a label.
            return $decision->rule;
        }

        return 'other';
    }

    /**
     * Rule strings the CONNECT gateway stores. Same set {@see rules()} uses.
     *
     * @return list<string>
     */
    public function publishedRules(): array
    {
        $rules = [];
        foreach ([
            ...EgressBuiltinDestinations::rules($this->providers),
            ...$this->config->allowRules(),
            ...$this->config->noProxyHosts(),
        ] as $item) {
            $item = trim($item);
            if ('' !== $item && null !== EgressRule::parse($item)) {
                $rules[] = $item;
            }
        }

        return $rules;
    }

    /**
     * @return list<EgressRule>
     */
    private function rules(): array
    {
        $rules = [];
        foreach ($this->publishedRules() as $item) {
            $rule = EgressRule::parse($item);
            if (null !== $rule) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }
}
