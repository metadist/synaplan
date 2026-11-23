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
        return $this->ofAll([$destination])[$destination];
    }

    /**
     * Reads the lists once for the whole batch, so a report with many
     * destinations costs one settings lookup, not one per row.
     *
     * @param list<string> $destinations
     *
     * @return array<string, array{state: string, rule: ?string, allow_rule: ?string}>
     */
    public function ofAll(array $destinations): array
    {
        $builtin = self::parseAll([...EgressBuiltinDestinations::rules($this->providers), ...$this->config->noProxyHosts()]);
        $listed = self::parseAll($this->config->allowRules());

        $standings = [];
        foreach ($destinations as $destination) {
            $standings[$destination] = self::classify($destination, $builtin, $listed);
        }

        return $standings;
    }

    /**
     * @param array<string, EgressRule> $builtin
     * @param array<string, EgressRule> $listed  keyed by the stored entry
     *
     * @return array{state: string, rule: ?string, allow_rule: ?string}
     */
    private static function classify(string $destination, array $builtin, array $listed): array
    {
        if (RequestTimelineContext::TYPED_DESTINATION === $destination) {
            return ['state' => self::TYPED, 'rule' => null, 'allow_rule' => null];
        }

        $parsed = self::parse($destination);
        if (null === $parsed) {
            return ['state' => self::NOT_LISTED, 'rule' => null, 'allow_rule' => null];
        }

        foreach ($builtin as $rule) {
            if ($rule->matches($parsed)) {
                return ['state' => self::BUILTIN, 'rule' => null, 'allow_rule' => null];
            }
        }

        foreach ($listed as $raw => $rule) {
            if ($rule->matches($parsed)) {
                return ['state' => self::LISTED, 'rule' => (string) $raw, 'allow_rule' => null];
            }
        }

        return ['state' => self::NOT_LISTED, 'rule' => null, 'allow_rule' => self::suggestedRule($parsed)];
    }

    /**
     * @param list<string> $raws
     *
     * @return array<string, EgressRule>
     */
    private static function parseAll(array $raws): array
    {
        $rules = [];
        foreach ($raws as $raw) {
            $rule = EgressRule::parse($raw);
            if (null !== $rule) {
                $rules[$raw] = $rule;
            }
        }

        return $rules;
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
