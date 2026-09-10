<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Tests\Unit\Module\Fixture\BuildsAllModules;
use PHPUnit\Framework\TestCase;

/**
 * Feature modules S1 / FM4 — every optional feature is owned.
 *
 * Walks every environment variable referenced by config/services.yaml (both the
 * `env(X): ''` defaults under `parameters:` and inline `%env(...:X)%` references)
 * and asserts each one belongs to exactly one feature module, is explicitly
 * listed as core, or sits on the dated allow-list below. A new optional env key
 * without an owner fails this test — the "declare it or boot fails" idea of
 * PlugDeclarationCheckPass applied to first-party code, at test time so prod
 * boot never gets slower.
 *
 * Module ownership comes from the descriptors' `configuredBy()` (S2), so the
 * declaration that drives the status page and the gates is the one this test
 * checks — there is no second list to keep in sync.
 *
 * Scope: services.yaml only. Infrastructure DSNs in config/packages/*.yaml
 * (DATABASE_URL, MESSENGER_TRANSPORT_DSN, MAILER_DSN, …) are mandatory, not
 * optional features, and are out of scope by design.
 */
final class ModuleOwnershipTest extends TestCase
{
    use BuildsAllModules;

    /** Guard against a vacuous pass if the parser stops matching (132 keys on 2026-09-10). */
    private const MIN_EXPECTED_ENV_KEYS = 100;

    /** Master plan §0 row 3 — the v1 module set. Order is the S4 rollout order. */
    private const MODULE_IDS = [
        'tika',
        'docling',
        'office_convert',
        'searxng',
        'piper_tts',
        'local_ai',
        'higgsfield',
        'google_ai',
        'thehive',
        'stripe_billing',
        'mobile_iap',
        'whatsapp',
    ];

    /**
     * Optional-looking keys that deliberately stay core in v1 (master plan §0
     * row 3 and §8). Grouped by the feature they belong to; the group name is
     * documentation only. A key listed here is a conscious decision, not a gap.
     *
     * @var array<string, list<string>>
     */
    private const CORE_ENV_KEYS = [
        // Application, bootstrap and platform identity — never optional.
        'application' => [
            'APP_SECRET',
            'APP_URL',
            'APP_VERSION',
            'FRONTEND_URL',
            'AUTH_COOKIE_SECURE',
            'LOG_FORMAT',
            'REDIS_DSN',
            'SYNAPLAN_PLATFORM',
            'BOOTSTRAP_ADMIN_EMAIL',
            'BOOTSTRAP_ADMIN_PASSWORD',
            'BOOTSTRAP_ADMIN_FORCE_PASSWORD_CHANGE',
            'GUEST_MAX_SESSIONS_PER_IP',
            'DEFAULT_USER_PLUGINS',
            'MCP_ALLOWED_HOSTS',
        ],
        // Product-defining AI providers. Keys are also entered at runtime via
        // ProviderKeyStore; the provider set is the product, not an add-on.
        'core_ai_providers' => [
            'ANTHROPIC_API_KEY',
            'OPENAI_API_KEY',
            'OPENAI_STORE_RESPONSES',
            'GROQ_API_KEY',
            'MISTRAL_API_KEY',
            'TRUSTEDTOKENS_API_KEY',
            'HUGGINGFACE_API_KEY',
            'XAI_API_KEY',
            'PERPLEXITY_API_KEY',
            'CLOUDFLARE_ACCOUNT_ID',
            'CLOUDFLARE_API_TOKEN',
            'TRITON_SERVER_URL',
            'EMBEDDING_FALLBACK_PROVIDER',
        ],
        // Web-search and rerank plug adapters already have their own registry,
        // PlugKeyStore and declaration check (AI Plugs track); v2 candidates.
        'plug_adapters' => [
            'TAVILY_API_KEY',
            'EXA_API_KEY',
            'FIRECRAWL_API_KEY',
            'JINA_API_KEY',
            'COHERE_API_KEY',
            'VOYAGE_API_KEY',
            'BRAVE_SEARCH_ENABLED',
            'BRAVE_SEARCH_API_KEY',
            'BRAVE_SEARCH_API_URL',
            'BRAVE_SEARCH_COUNT',
            'BRAVE_SEARCH_COUNTRY',
            'BRAVE_SEARCH_SEARCH_LANG',
        ],
        // OAuth / OIDC / anti-abuse — own flags, security-critical; core in v1.
        'auth' => [
            'GOOGLE_CLIENT_ID',
            'GOOGLE_CLIENT_SECRET',
            'GITHUB_CLIENT_ID',
            'GITHUB_CLIENT_SECRET',
            'APPLE_CLIENT_ID',
            'APPLE_KEY_ID',
            'APPLE_PRIVATE_KEY',
            'APPLE_TEAM_ID',
            'OIDC_CLIENT_ID',
            'OIDC_CLIENT_SECRET',
            'OIDC_DISCOVERY_URL',
            'OIDC_BEARER_AUDIENCE',
            'OIDC_ADMIN_ROLES',
            'OIDC_ROLE_CLAIMS',
            'OIDC_SCOPES',
            'OIDC_AUTO_REDIRECT',
            'RECAPTCHA_ENABLED',
            'RECAPTCHA_SECRET_KEY',
            'RECAPTCHA_MIN_SCORE',
        ],
        // Native app shell identity (deep links, Sign in with Apple audience).
        'mobile_shell' => [
            'NATIVE_DEEPLINK_SCHEME',
            'APPLE_APP_BUNDLE_ID',
        ],
        // Centrifugo realtime — infrastructure with its own enable flag.
        'realtime' => [
            'REALTIME_ENABLED',
            'REALTIME_API_URL',
            'REALTIME_API_KEY',
            'REALTIME_TOKEN_SECRET',
        ],
        // Vector storage — RAG is core; the provider choice is infrastructure.
        'vector_store' => [
            'VECTOR_STORAGE_PROVIDER',
            'QDRANT_URL',
            'QDRANT_MEMORIES_COLLECTION',
            'QDRANT_DOCUMENTS_COLLECTION',
            'QDRANT_DIGESTS_COLLECTION',
            'QDRANT_ROUTING_ANCHORS_COLLECTION',
        ],
        // Local speech-to-text ships in the base image (whisper.cpp + ffmpeg).
        'local_speech_to_text' => [
            'WHISPER_ENABLED',
            'WHISPER_DEFAULT_MODEL',
            'WHISPER_BINARY',
            'WHISPER_MODELS_PATH',
            'FFMPEG_BINARY',
        ],
        // In-process document pipeline (Imagick rasterizing, PhpOffice text and
        // merge) — no sidecar, always available.
        'document_pipeline' => [
            'RASTERIZE_DPI',
            'RASTERIZE_PAGE_CAP',
            'RASTERIZE_TIMEOUT_MS',
            'OFFICE_TEXT_MAX_ROWS',
            'OFFICE_COMBINE_MAX_FILES',
        ],
        // E-mail channel and operator notifications stay core (master plan §8).
        'email_and_notifications' => [
            'GMAIL_USERNAME',
            'GMAIL_PASSWORD',
            'DISCORD_WEBHOOK_URL',
        ],
        // Anthropic-compatible Messages gateway upstream (desktop client path).
        'messages_gateway' => [
            'MESSAGES_GATEWAY_UPSTREAM_URL',
        ],
    ];

    /**
     * Dated allow-list of env keys that are referenced but not yet classified.
     * It may only shrink: an entry that no longer exists in services.yaml fails
     * testAllowedUnownedEntriesStillExist so the list cannot rot. Empty at S1 —
     * every key found on 2026-09-10 is classified above.
     *
     * @return array<string, string> env key => date added (YYYY-MM-DD)
     */
    private static function allowedUnowned(): array
    {
        return [];
    }

    public function testModuleIdsMatchThePlan(): void
    {
        $this->assertCount(12, self::MODULE_IDS);

        $declared = array_keys($this->allModules());
        sort($declared);
        $plan = self::MODULE_IDS;
        sort($plan);

        $this->assertSame($plan, $declared, 'The descriptor set must be exactly the plan\'s module set.');
    }

    public function testEveryEnvKeyInServicesYamlIsOwnedOrCore(): void
    {
        $existing = $this->envKeysInServicesYaml();
        $this->assertGreaterThanOrEqual(self::MIN_EXPECTED_ENV_KEYS, count($existing), 'The services.yaml parser found suspiciously few env keys — the regexes no longer match the file.');

        $owned = array_merge($this->moduleKeys(), $this->coreKeys(), array_keys(self::allowedUnowned()));
        $unowned = array_values(array_diff($existing, $owned));

        $this->assertSame([], $unowned, sprintf(
            "Unowned optional env keys in config/services.yaml:\n  %s\n\nAdd each to a module's configuredBy(), to a CORE_ENV_KEYS group with a reason, or (last resort) to allowedUnowned() with today's date.",
            implode("\n  ", $unowned),
        ));
    }

    public function testNoEnvKeyIsClaimedTwice(): void
    {
        $all = array_merge($this->moduleKeys(), $this->coreKeys(), array_keys(self::allowedUnowned()));
        $counts = array_count_values($all);
        $duplicates = array_keys(array_filter($counts, static fn (int $count): bool => $count > 1));

        $this->assertSame([], $duplicates, 'Each env key must have exactly one owner: '.implode(', ', $duplicates));
    }

    public function testClassifiedKeysStillExistInServicesYaml(): void
    {
        $existing = $this->envKeysInServicesYaml();
        $stale = array_values(array_diff(array_merge($this->moduleKeys(), $this->coreKeys()), $existing));

        $this->assertSame([], $stale, sprintf(
            "Classified env keys no longer referenced by config/services.yaml (renamed or removed?):\n  %s",
            implode("\n  ", $stale),
        ));
    }

    public function testAllowedUnownedEntriesStillExist(): void
    {
        $existing = $this->envKeysInServicesYaml();

        foreach (self::allowedUnowned() as $key => $dateAdded) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $dateAdded, "allowedUnowned()[{$key}] must carry the date it was added.");
            $this->assertContains($key, $existing, "allowedUnowned()[{$key}] is no longer referenced — remove the entry; the list only shrinks.");
        }
    }

    public function testEveryModuleOwnsAtLeastOneEnvKey(): void
    {
        foreach ($this->allModules() as $moduleId => $module) {
            $this->assertNotEmpty($module->configuredBy()->envKeys, "Module '{$moduleId}' must be configured by at least one env key (master plan §0 row 4).");
        }
    }

    /**
     * @return list<string>
     */
    private function moduleKeys(): array
    {
        $keys = [];
        foreach ($this->allModules() as $module) {
            $keys[] = $module->configuredBy()->envKeys;
        }

        return array_merge(...$keys);
    }

    /**
     * @return list<string>
     */
    private function coreKeys(): array
    {
        return array_merge(...array_values(self::CORE_ENV_KEYS));
    }

    /**
     * Every env variable name services.yaml refers to: `env(NAME):` defaults in
     * the parameters block and `%env(processor:NAME)%` references anywhere.
     *
     * @return list<string> sorted, unique
     */
    private function envKeysInServicesYaml(): array
    {
        $path = dirname(__DIR__, 2).'/config/services.yaml';
        $yaml = file_get_contents($path);
        $this->assertNotFalse($yaml, "Cannot read {$path}");

        // `env(NAME): 'default'` under parameters:
        preg_match_all('/^\s*env\(([A-Z][A-Z0-9_]*)\):/m', $yaml, $defaults);
        // `%env(NAME)%`, `%env(int:NAME)%`, `%env(default:param_name:NAME)%`, `%env(string:default::NAME)%` …
        // processors and parameter names are lowercase, the env name is the trailing upper-case token.
        preg_match_all('/%env\([a-z0-9_:]*?([A-Z][A-Z0-9_]*)\)%/', $yaml, $inline);

        $names = array_values(array_unique(array_merge($defaults[1], $inline[1])));
        sort($names);

        return $names;
    }
}
