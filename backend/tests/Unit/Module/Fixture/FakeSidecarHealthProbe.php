<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Fixture;

use App\Module\Probe\SidecarHealthProbeInterface;

/**
 * Records every probed URL and answers from a fixed map.
 */
final class FakeSidecarHealthProbe implements SidecarHealthProbeInterface
{
    /** @var list<string> */
    public array $reachableCalls = [];

    /** @var list<string> */
    public array $fetchCalls = [];

    /**
     * @param array<string, bool>        $reachable url => answer (default false)
     * @param array<string, string|null> $bodies    url => body   (default null)
     */
    public function __construct(
        private readonly array $reachable = [],
        private readonly array $bodies = [],
    ) {
    }

    public function isReachable(string $url, ?string $httpUser = null, ?string $httpPass = null): bool
    {
        $this->reachableCalls[] = $url.(null !== $httpUser ? ' as '.$httpUser : '');

        return $this->reachable[$url] ?? false;
    }

    public function fetchText(string $url, ?string $httpUser = null, ?string $httpPass = null): ?string
    {
        $this->fetchCalls[] = $url;

        return $this->bodies[$url] ?? null;
    }
}
