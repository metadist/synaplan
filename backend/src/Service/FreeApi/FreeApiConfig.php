<?php

declare(strict_types=1);

namespace App\Service\FreeApi;

use App\AI\Credential\OpenAiCompatibleEndpointRegistry;
use App\Repository\ConfigRepository;
use App\Service\Feature\FeatureFlagEnv;

/**
 * Switches and fixed facts of the Synaplan Demo client (module `freeapi`).
 *
 * Off unless `FEATURE_FREEAPI_ENABLED` or the admin row `FREEAPI.ENABLED`
 * turns it on AND `FREEAPI_BASE_URL` names a usable server. The server is
 * reached over https only; plain http is accepted for the fake server in the
 * dev and test environments. CI and the test environment never talk to a
 * real (https) server.
 */
final readonly class FreeApiConfig
{
    public const GROUP = 'FREEAPI';
    public const ENABLED = 'ENABLED';

    /** Name of the OpenAI-compatible endpoint the Demo registers. */
    public const ENDPOINT_NAME = OpenAiCompatibleEndpointRegistry::DEMO_ENDPOINT;
    public const ENDPOINT_LABEL = 'Synaplan Demo';

    /** Client protocol of `contract/v1`; the server answers `freeapi_upgrade_required` below its minimum. */
    public const PROTOCOL = 1;

    /** Version of the Demo terms the consent sentence refers to. */
    public const TERMS_VERSION = '2026-10';

    private const PLATFORMS = ['dev', 'minimal', 'quickstart', 'lite', 'selfhost', 'helm'];
    private const LOCAL_ENVIRONMENTS = ['dev', 'test'];

    public function __construct(
        private ConfigRepository $configRepository,
        private FeatureFlagEnv $featureFlags,
        private string $baseUrl,
        private string $environment,
        private bool $ci,
        private string $appVersion,
        private string $platform,
    ) {
    }

    public function isEnabled(): bool
    {
        $forced = $this->featureFlags->forced(self::GROUP, self::ENABLED);
        if (null !== $forced) {
            return $forced;
        }

        $stored = $this->configRepository->getValue(0, self::GROUP, self::ENABLED);

        return null !== $stored && true === filter_var(trim($stored), \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE);
    }

    /** The module counts as configured: switched on and a usable server named. */
    public function isConfigured(): bool
    {
        return $this->isEnabled() && null !== $this->baseUrl();
    }

    /**
     * Normalised server origin without a trailing slash, or null when
     * FREEAPI_BASE_URL is empty or not allowed in this environment.
     */
    public function baseUrl(): ?string
    {
        $url = rtrim(trim($this->baseUrl), '/');
        if ('' === $url) {
            return null;
        }

        $parts = parse_url($url);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $local = \in_array($this->environment, self::LOCAL_ENVIRONMENTS, true);
        if ('https' === $scheme) {
            return ($this->ci || 'test' === $this->environment) ? null : $url;
        }

        return 'http' === $scheme && $local ? $url : null;
    }

    /** Host of the Demo server, named in the consent sentence. */
    public function serverHost(): ?string
    {
        $baseUrl = $this->baseUrl();

        return null === $baseUrl ? null : parse_url($baseUrl, \PHP_URL_HOST);
    }

    /** Raw FREEAPI_BASE_URL for operator-facing messages. */
    public function configuredBaseUrl(): string
    {
        return trim($this->baseUrl);
    }

    public function appVersion(): string
    {
        return '' !== trim($this->appVersion) ? substr(trim($this->appVersion), 0, 32) : 'dev';
    }

    /** One of the issuance platforms; `dev` in the dev environment, `selfhost` when unknown. */
    public function platform(): string
    {
        if ('dev' === $this->environment) {
            return 'dev';
        }
        $platform = strtolower(trim($this->platform));

        return \in_array($platform, self::PLATFORMS, true) ? $platform : 'selfhost';
    }
}
