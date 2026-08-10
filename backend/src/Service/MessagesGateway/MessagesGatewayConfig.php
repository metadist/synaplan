<?php

declare(strict_types=1);

namespace App\Service\MessagesGateway;

use App\Repository\ConfigRepository;
use App\Service\Security\SsrfGuard;
use Psr\Log\LoggerInterface;

/**
 * Feature flags and settings for the Anthropic-compatible Messages gateway
 * (BCONFIG group {@see self::CONFIG_GROUP}).
 *
 * Resolution order for per-user flags: per-user row → global (ownerId=0) →
 * code default. {@see self::KEY_UPSTREAM_URL} is global-only (admin-set) with
 * an env fallback for the local smoke harness.
 */
final readonly class MessagesGatewayConfig
{
    public const CONFIG_GROUP = 'MESSAGES_GATEWAY';

    public const KEY_ENABLED = 'ENABLED';
    public const KEY_ALLOW_OPERATOR_KEY = 'ALLOW_OPERATOR_KEY';
    public const KEY_MCP_TOOLS_ENABLED = 'MCP_TOOLS_ENABLED';
    public const KEY_MCP_TOOLS_WITH_CLIENT_TOOLS = 'MCP_TOOLS_WITH_CLIENT_TOOLS';
    public const KEY_MCP_MAX_ITERATIONS = 'MCP_MAX_ITERATIONS';
    public const KEY_WEB_SEARCH_MODE = 'WEB_SEARCH_MODE';
    public const KEY_WEB_FETCH_MODE = 'WEB_FETCH_MODE';
    public const KEY_VISION_MODE = 'VISION_MODE';

    public const KEY_VISION_IMAGE_DETAIL = 'VISION_IMAGE_DETAIL';
    public const KEY_VISION_MAX_IMAGES = 'VISION_MAX_IMAGES';
    public const KEY_CONTEXT_INJECTION_ENABLED = 'CONTEXT_INJECTION_ENABLED';
    public const KEY_BUDGET_NOTICE_ENABLED = 'BUDGET_NOTICE_ENABLED';
    public const KEY_SESSION_SUMMARY_ENABLED = 'SESSION_SUMMARY_ENABLED';
    public const KEY_MODEL_ALIASES = 'MODEL_ALIASES';
    public const KEY_UPSTREAM_URL = 'UPSTREAM_URL';

    public const DEFAULT_UPSTREAM_URL = 'https://api.anthropic.com';

    /** Synaplan runs the search whenever a search provider is configured, otherwise {@see self::WEB_SEARCH_PASSTHROUGH}. */
    public const WEB_SEARCH_AUTO = 'auto';
    /** Always answer the client's `web_search` declaration with Synaplan's own search. */
    public const WEB_SEARCH_SYNAPLAN = 'synaplan';
    /** Forward the declaration untouched — only api.anthropic.com can honour it. */
    public const WEB_SEARCH_PASSTHROUGH = 'passthrough';
    /** Drop the declaration before it reaches the upstream. */
    public const WEB_SEARCH_OFF = 'off';

    public const WEB_SEARCH_MODES = [
        self::WEB_SEARCH_AUTO,
        self::WEB_SEARCH_SYNAPLAN,
        self::WEB_SEARCH_PASSTHROUGH,
        self::WEB_SEARCH_OFF,
    ];

    /**
     * Offer Anthropic's `web_fetch_*` server tool on Anthropic routes (inject if
     * the client omitted it). Synaplan never executes the fetch itself.
     */
    public const WEB_FETCH_AUTO = 'auto';
    /** Same as {@see self::WEB_FETCH_AUTO} — explicit "leave it to Anthropic" label. */
    public const WEB_FETCH_PASSTHROUGH = 'passthrough';
    /** Drop `web_fetch_*` declarations; do not inject. */
    public const WEB_FETCH_OFF = 'off';

    public const WEB_FETCH_MODES = [
        self::WEB_FETCH_AUTO,
        self::WEB_FETCH_PASSTHROUGH,
        self::WEB_FETCH_OFF,
    ];

    /** Synaplan vision when the resolved model cannot see images (or always, in synaplan mode); else funnel. */
    public const VISION_AUTO = 'auto';
    /** Prefer the account's PIC2TEXT / catalog vision model for every image turn. */
    public const VISION_SYNAPLAN = 'synaplan';
    /** Never rewrite the model; leave images on the wire for the upstream. */
    public const VISION_PASSTHROUGH = 'passthrough';
    /** Never rewrite and do not offer the analyze_image tool. */
    public const VISION_OFF = 'off';

    public const VISION_MODES = [
        self::VISION_AUTO,
        self::VISION_SYNAPLAN,
        self::VISION_PASSTHROUGH,
        self::VISION_OFF,
    ];

    /** Let the provider pick the resolution it reads the image at. */
    public const IMAGE_DETAIL_AUTO = 'auto';
    /** Cheap fixed-resolution read (fewest image tokens). */
    public const IMAGE_DETAIL_LOW = 'low';
    /** Full tiled read (most image tokens). */
    public const IMAGE_DETAIL_HIGH = 'high';

    public const IMAGE_DETAILS = [
        self::IMAGE_DETAIL_AUTO,
        self::IMAGE_DETAIL_LOW,
        self::IMAGE_DETAIL_HIGH,
    ];

    public const MIN_MCP_MAX_ITERATIONS = 1;
    public const MAX_MCP_MAX_ITERATIONS = 32;
    public const MIN_VISION_MAX_IMAGES = 0;
    public const MAX_VISION_MAX_IMAGES = 100;

    private const DEFAULT_ENABLED = false;
    private const DEFAULT_ALLOW_OPERATOR_KEY = false;
    private const DEFAULT_MCP_TOOLS_ENABLED = false;
    private const DEFAULT_MCP_TOOLS_WITH_CLIENT_TOOLS = false;
    private const DEFAULT_MCP_MAX_ITERATIONS = 8;
    private const DEFAULT_WEB_SEARCH_MODE = self::WEB_SEARCH_AUTO;
    private const DEFAULT_WEB_FETCH_MODE = self::WEB_FETCH_AUTO;
    private const DEFAULT_VISION_MODE = self::VISION_AUTO;

    private const DEFAULT_VISION_IMAGE_DETAIL = self::IMAGE_DETAIL_AUTO;
    private const DEFAULT_VISION_MAX_IMAGES = 0;
    private const DEFAULT_CONTEXT_INJECTION_ENABLED = false;
    private const DEFAULT_BUDGET_NOTICE_ENABLED = true;
    private const DEFAULT_SESSION_SUMMARY_ENABLED = true;

    private string $envUpstreamUrl;

    public function __construct(
        private ConfigRepository $configRepository,
        private SsrfGuard $ssrfGuard,
        private LoggerInterface $logger,
        ?string $envUpstreamUrl = null,
    ) {
        // Symfony's default:: env processor yields null when the var is unset.
        $this->envUpstreamUrl = $envUpstreamUrl ?? '';
    }

    public function isEnabled(?int $userId): bool
    {
        return $this->resolveBool(self::KEY_ENABLED, $userId, self::DEFAULT_ENABLED);
    }

    public function allowOperatorKey(?int $userId): bool
    {
        return $this->resolveBool(self::KEY_ALLOW_OPERATOR_KEY, $userId, self::DEFAULT_ALLOW_OPERATOR_KEY);
    }

    public function isMcpToolsEnabled(?int $userId): bool
    {
        return $this->resolveBool(self::KEY_MCP_TOOLS_ENABLED, $userId, self::DEFAULT_MCP_TOOLS_ENABLED);
    }

    public function allowMcpToolsWithClientTools(?int $userId): bool
    {
        return $this->resolveBool(
            self::KEY_MCP_TOOLS_WITH_CLIENT_TOOLS,
            $userId,
            self::DEFAULT_MCP_TOOLS_WITH_CLIENT_TOOLS,
        );
    }

    public function mcpMaxIterations(?int $userId): int
    {
        $raw = $this->resolveString(self::KEY_MCP_MAX_ITERATIONS, $userId, (string) self::DEFAULT_MCP_MAX_ITERATIONS);
        $n = (int) $raw;

        return max(
            self::MIN_MCP_MAX_ITERATIONS,
            min(self::MAX_MCP_MAX_ITERATIONS, $n > 0 ? $n : self::DEFAULT_MCP_MAX_ITERATIONS),
        );
    }

    /**
     * How the gateway answers Anthropic's `web_search_*` server tool.
     *
     * Anthropic ships web search as a *server* tool: the client declares it and
     * expects the API side to run the search. `auto` (the default) resolves that
     * with whatever actually works — Synaplan's own search when a provider is
     * configured, otherwise a plain passthrough that only api.anthropic.com can
     * honour. Unknown values fall back to the default rather than disabling
     * search, so a typo cannot silently break a working install.
     *
     * @return self::WEB_SEARCH_AUTO|self::WEB_SEARCH_SYNAPLAN|self::WEB_SEARCH_PASSTHROUGH|self::WEB_SEARCH_OFF
     */
    public function webSearchMode(?int $userId): string
    {
        $raw = $this->resolveToken(self::KEY_WEB_SEARCH_MODE, $userId, self::DEFAULT_WEB_SEARCH_MODE);

        return \in_array($raw, self::WEB_SEARCH_MODES, true) ? $raw : self::DEFAULT_WEB_SEARCH_MODE;
    }

    /**
     * How the gateway handles Anthropic's `web_fetch_*` server tool.
     *
     * Synaplan never fetches pages itself — `auto` / `passthrough` keep (or
     * inject) the declaration so api.anthropic.com can honour it. `off` drops
     * the capability. Unknown values fall back to the default so a typo cannot
     * silently disable page reading.
     *
     * @return self::WEB_FETCH_AUTO|self::WEB_FETCH_PASSTHROUGH|self::WEB_FETCH_OFF
     */
    public function webFetchMode(?int $userId): string
    {
        $raw = $this->resolveToken(self::KEY_WEB_FETCH_MODE, $userId, self::DEFAULT_WEB_FETCH_MODE);

        return \in_array($raw, self::WEB_FETCH_MODES, true) ? $raw : self::DEFAULT_WEB_FETCH_MODE;
    }

    /**
     * How the gateway handles image turns for Claude Code compatibility.
     *
     * `auto` (default) rewrites to the user's PIC2TEXT / catalog vision model
     * only when the resolved model lacks `vision`; otherwise images stay on the
     * wire for Anthropic (or any vision-capable upstream). Unknown values fall
     * back to the default so a typo cannot silently disable vision.
     *
     * @return self::VISION_AUTO|self::VISION_SYNAPLAN|self::VISION_PASSTHROUGH|self::VISION_OFF
     */
    public function visionMode(?int $userId): string
    {
        $raw = $this->resolveToken(self::KEY_VISION_MODE, $userId, self::DEFAULT_VISION_MODE);

        return \in_array($raw, self::VISION_MODES, true) ? $raw : self::DEFAULT_VISION_MODE;
    }

    /**
     * Resolution the provider should read forwarded images at.
     *
     * Only routes that expose the knob apply it (OpenAI-compatible upstreams
     * via `image_url.detail`); `auto` leaves the choice to the provider.
     *
     * @return self::IMAGE_DETAIL_AUTO|self::IMAGE_DETAIL_LOW|self::IMAGE_DETAIL_HIGH
     */
    public function visionImageDetail(?int $userId): string
    {
        $raw = $this->resolveToken(self::KEY_VISION_IMAGE_DETAIL, $userId, self::DEFAULT_VISION_IMAGE_DETAIL);

        return \in_array($raw, self::IMAGE_DETAILS, true) ? $raw : self::DEFAULT_VISION_IMAGE_DETAIL;
    }

    /**
     * Maximum image blocks forwarded per request; 0 means unlimited.
     */
    public function visionMaxImages(?int $userId): int
    {
        $raw = $this->resolveString(self::KEY_VISION_MAX_IMAGES, $userId, null);
        if (null === $raw || '' === trim($raw) || !is_numeric(trim($raw))) {
            return self::DEFAULT_VISION_MAX_IMAGES;
        }

        return max(self::MIN_VISION_MAX_IMAGES, min(self::MAX_VISION_MAX_IMAGES, (int) trim($raw)));
    }

    public function isContextInjectionEnabled(?int $userId): bool
    {
        return $this->resolveBool(
            self::KEY_CONTEXT_INJECTION_ENABLED,
            $userId,
            self::DEFAULT_CONTEXT_INJECTION_ENABLED,
        );
    }

    public function isBudgetNoticeEnabled(?int $userId): bool
    {
        return $this->resolveBool(
            self::KEY_BUDGET_NOTICE_ENABLED,
            $userId,
            self::DEFAULT_BUDGET_NOTICE_ENABLED,
        );
    }

    /**
     * Rolling per-session summary chat (default ON — the gateway itself is
     * opt-in, and the summary is the user-visible activity trail).
     */
    public function isSessionSummaryEnabled(?int $userId): bool
    {
        return $this->resolveBool(
            self::KEY_SESSION_SUMMARY_ENABLED,
            $userId,
            self::DEFAULT_SESSION_SUMMARY_ENABLED,
        );
    }

    /**
     * @return array<string, string> alias pattern → target model id
     */
    public function modelAliases(): array
    {
        $raw = $this->configRepository->getValue(0, self::CONFIG_GROUP, self::KEY_MODEL_ALIASES);
        if (null === $raw || '' === $raw) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->logger->warning('MessagesGateway: MODEL_ALIASES is not valid JSON', [
                'raw' => $raw,
            ]);

            return [];
        }

        if (!\is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $from => $to) {
            if (\is_string($from) && '' !== $from && \is_string($to) && '' !== $to) {
                $out[$from] = $to;
            }
        }

        return $out;
    }

    /**
     * Effective upstream base URL (no trailing slash).
     *
     * Resolution: global BCONFIG → env MESSAGES_GATEWAY_UPSTREAM_URL → default.
     */
    public function upstreamUrl(): string
    {
        $db = $this->configRepository->getValue(0, self::CONFIG_GROUP, self::KEY_UPSTREAM_URL);
        if (null !== $db && '' !== trim($db)) {
            return rtrim(trim($db), '/');
        }

        $env = trim($this->envUpstreamUrl);
        if ('' !== $env) {
            return rtrim($env, '/');
        }

        return self::DEFAULT_UPSTREAM_URL;
    }

    /**
     * Persist a new global upstream URL after validation. Admin-only caller
     * responsibility — this method does not check roles.
     *
     * @throws \InvalidArgumentException when the URL fails validation
     */
    public function setUpstreamUrl(string $url, int $actingUserId): void
    {
        $normalized = $this->validateUpstreamUrl($url);
        $previous = $this->upstreamUrl();

        $this->configRepository->setValue(0, self::CONFIG_GROUP, self::KEY_UPSTREAM_URL, $normalized);

        $this->logger->warning('MessagesGateway: UPSTREAM_URL changed (audit)', [
            'acting_user_id' => $actingUserId,
            'previous' => $previous,
            'new' => $normalized,
        ]);
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function validateUpstreamUrl(string $url): string
    {
        $url = trim($url);
        if ('' === $url) {
            throw new \InvalidArgumentException('Upstream URL must not be empty.');
        }

        $parsed = parse_url($url);
        if (false === $parsed || !isset($parsed['scheme'], $parsed['host'])) {
            throw new \InvalidArgumentException('Upstream URL is not a valid absolute URL.');
        }

        if (isset($parsed['user']) || isset($parsed['pass'])) {
            throw new \InvalidArgumentException('Upstream URL must not contain credentials.');
        }

        $scheme = strtolower((string) $parsed['scheme']);
        $host = strtolower((string) $parsed['host']);

        if ('https' === $scheme) {
            if ($this->ssrfGuard->isBlockedUrl($url)) {
                throw new \InvalidArgumentException('Upstream URL points at a blocked/private host. Use a public HTTPS endpoint.');
            }

            return rtrim($url, '/');
        }

        if ('http' === $scheme) {
            // Dev/fixture exception: plain HTTP only for loopback / RFC-1918.
            if (!$this->isLocalDevHost($host)) {
                throw new \InvalidArgumentException('Plain HTTP upstream URLs are only allowed for loopback or private (RFC-1918) hosts.');
            }

            return rtrim($url, '/');
        }

        throw new \InvalidArgumentException('Upstream URL scheme must be https (or http for local dev hosts).');
    }

    private function isLocalDevHost(string $host): bool
    {
        $host = trim($host, "[] \t");
        if (\in_array($host, ['localhost', 'localhost.localdomain', 'host.docker.internal'], true)) {
            return true;
        }

        if (false === filter_var($host, \FILTER_VALIDATE_IP)) {
            return false;
        }

        // Private / loopback / link-local — allowed only for the http exception.
        return false === filter_var(
            $host,
            \FILTER_VALIDATE_IP,
            \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE,
        );
    }

    private function resolveBool(string $setting, ?int $userId, bool $default): bool
    {
        $raw = $this->resolveString($setting, $userId, null);
        if (null === $raw) {
            return $default;
        }

        return filter_var($raw, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * Stored enum value, lowercased and trimmed. Callers match it against their
     * own allow-list so an unknown value falls back to the code default — a typo
     * in BCONFIG must never break a working install.
     */
    private function resolveToken(string $setting, ?int $userId, string $default): string
    {
        return strtolower(trim((string) $this->resolveString($setting, $userId, $default)));
    }

    private function resolveString(string $setting, ?int $userId, ?string $default): ?string
    {
        if (null !== $userId && $userId > 0) {
            // UPSTREAM_URL is global-only — never read a per-user override.
            if (self::KEY_UPSTREAM_URL !== $setting) {
                $perUser = $this->configRepository->getValue($userId, self::CONFIG_GROUP, $setting);
                if (null !== $perUser) {
                    return $perUser;
                }
            }
        }

        $global = $this->configRepository->getValue(0, self::CONFIG_GROUP, $setting);
        if (null !== $global) {
            return $global;
        }

        return $default;
    }
}
