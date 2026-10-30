<?php

declare(strict_types=1);

namespace App\Service\Egress;

/**
 * Decides one destination. Built-in services and provider hosts, the
 * administrator's list, and the no-proxy hosts are allowed. A URL a person
 * typed is allowed only when the guard accepts it, and is not stored.
 */
final readonly class EgressPolicy
{
    public function __construct(
        private EgressConfig $config,
        private EgressTypedUrlGuard $typedUrls,
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

        foreach ($this->rules() as $rule) {
            if ($rule->matches($destination)) {
                return EgressDecision::allow($rule->label(), 'allowlist');
            }
        }

        if (EgressCall::isTyped($options)) {
            if ($this->typedUrls->allows($url)) {
                return EgressDecision::allow('typed', 'typed');
            }

            return EgressDecision::refuse('typed_refused');
        }

        return EgressDecision::refuse('not_allowed');
    }

    /**
     * The metric label: an allowlist `host:port` when a rule matched, else
     * `other`, so a refused host cannot grow the label set.
     *
     * @param array<string, mixed> $options
     */
    public function metricDestination(string $url, array $options = []): string
    {
        $decision = $this->decide($url, $options);
        if ($decision->allowed && null !== $decision->rule && 'typed' !== $decision->rule) {
            $destination = EgressDestination::fromUrl($url);

            return null === $destination ? 'other' : $destination->label();
        }

        return 'other';
    }

    /**
     * @return list<EgressRule>
     */
    private function rules(): array
    {
        $raw = [
            ...EgressBuiltinDestinations::rules(),
            ...$this->config->allowRules(),
            ...$this->config->noProxyHosts(),
        ];
        $rules = [];
        foreach ($raw as $item) {
            $rule = EgressRule::parse($item);
            if (null !== $rule) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }
}
