<?php

declare(strict_types=1);

namespace App\Service\Egress;

use App\AI\Catalog\ProviderCatalog;
use App\Observability\RequestTimelineContext;

/**
 * Where one recorded destination (`host:port`) stands against today's
 * allowlist: allowed by Synaplan itself, allowed by an administrator's
 * entry (which Remove takes away), not listed (which Allow adds), or an
 * address a person typed, which no list decides.
 */
final readonly class EgressDestinationStanding
{
    public const BUILTIN = 'builtin';
    public const LISTED = 'listed';
    public const NOT_LISTED = 'not_listed';
    public const TYPED = 'typed';

    public function __construct(
        private EgressConfig $config,
        private ProviderCatalog $providers,
    ) {
    }

    /**
     * @return array{state: string, rule: ?string, allow_rule: ?string}
     */
    public function of(string $destination): array
    {
        if (RequestTimelineContext::TYPED_DESTINATION === $destination) {
            return ['state' => self::TYPED, 'rule' => null, 'allow_rule' => null];
        }

        $parsed = self::parse($destination);
        if (null === $parsed) {
            return ['state' => self::NOT_LISTED, 'rule' => null, 'allow_rule' => null];
        }

        foreach ([...EgressBuiltinDestinations::rules($this->providers), ...$this->config->noProxyHosts()] as $raw) {
            if (EgressRule::parse($raw)?->matches($parsed)) {
                return ['state' => self::BUILTIN, 'rule' => null, 'allow_rule' => null];
            }
        }

        foreach ($this->config->allowRules() as $raw) {
            if (EgressRule::parse($raw)?->matches($parsed)) {
                return ['state' => self::LISTED, 'rule' => $raw, 'allow_rule' => null];
            }
        }

        return ['state' => self::NOT_LISTED, 'rule' => null, 'allow_rule' => self::suggestedRule($parsed)];
    }

    /**
     * The rule Allow writes: the host alone on the scheme's default port,
     * otherwise `host:port`.
     */
    public static function suggestedRule(EgressDestination $destination): string
    {
        return \in_array($destination->port, [80, 443], true)
            ? $destination->host
            : $destination->label();
    }

    private static function parse(string $destination): ?EgressDestination
    {
        $port = (int) substr((string) strrchr($destination, ':'), 1);
        if ($port < 1) {
            return null;
        }
        $scheme = 80 === $port ? 'http' : 'https';

        return EgressDestination::fromUrl($scheme.'://'.$destination);
    }
}
