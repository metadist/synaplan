<?php

declare(strict_types=1);

namespace App\Module\Probe;

/**
 * Minimal HTTP reachability check used by sidecar module descriptors.
 *
 * Kept behind an interface so descriptor tests can fake the network and so
 * `FeatureModuleInterface::status()` remains the only place a module talks to
 * the outside world.
 */
interface SidecarHealthProbeInterface
{
    /**
     * True when the URL answers with anything but a 5xx, an auth rejection
     * (401/403) or a transport failure. A 404 counts as reachable: the sidecar
     * is up even if that particular path is not served.
     */
    public function isReachable(string $url, ?string $httpUser = null, ?string $httpPass = null): bool;

    /**
     * Body of a successful (2xx) GET, or null on any failure.
     */
    public function fetchText(string $url, ?string $httpUser = null, ?string $httpPass = null): ?string;
}
