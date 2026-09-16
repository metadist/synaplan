<?php

declare(strict_types=1);

namespace App\AI\Credential;

/**
 * Static metadata for the cloud providers whose API keys the
 * {@see ProviderKeyStore} manages: which env var bootstraps the key, where a
 * user gets one, and what to probe for live validation.
 *
 * Kept next to the store (not in ProviderRegistry) because it describes the
 * KEY lifecycle, not runtime capabilities — providers without a platform key
 * (Ollama, Piper, OpenAI-compatible endpoints) deliberately have no entry.
 *
 * Two optional facets:
 *  - `secretEnvVar`: the provider authenticates with a key + secret pair
 *    (Higgsfield). The store keeps both halves in one row; a save without the
 *    secret is refused.
 *  - `validation: null`: no cheap authenticated endpoint is known, so the key
 *    is stored untested and the UI says "Saved — not tested" instead of
 *    pretending a probe happened.
 *  - `modelListing: false`: the validation endpoint authenticates the key but
 *    says nothing about served models (whoami, account, landing page), so
 *    model health and the model inventory must not read it as a listing.
 *
 * Every env var listed here is *managed*: the legacy system-config page shows
 * it read-only and points at Models & keys ({@see SystemConfigService}).
 */
final class ProviderKeyCatalog
{
    public const MANAGED_BY = 'ai-infrastructure';

    /**
     * @var array<string, array{
     *     displayName: string,
     *     envVar: string,
     *     secretEnvVar?: string,
     *     consoleUrl: string,
     *     freeTier: bool,
     *     recommended: bool,
     *     modelListing?: bool,
     *     chat?: bool,
     *     validation: array{method: string, url: string, headers: array<string, string>}|null
     * }>
     */
    private const PROVIDERS = [
        'groq' => [
            'displayName' => 'Groq',
            'envVar' => 'GROQ_API_KEY',
            'consoleUrl' => 'https://console.groq.com/keys',
            'freeTier' => true,
            'recommended' => true,
            'validation' => [
                'method' => 'GET',
                'url' => 'https://api.groq.com/openai/v1/models',
                'headers' => ['Authorization' => 'Bearer {key}'],
            ],
        ],
        'openai' => [
            'displayName' => 'OpenAI',
            'envVar' => 'OPENAI_API_KEY',
            'consoleUrl' => 'https://platform.openai.com/api-keys',
            'freeTier' => false,
            'recommended' => false,
            'validation' => [
                'method' => 'GET',
                'url' => 'https://api.openai.com/v1/models',
                'headers' => ['Authorization' => 'Bearer {key}'],
            ],
        ],
        'anthropic' => [
            'displayName' => 'Anthropic',
            'envVar' => 'ANTHROPIC_API_KEY',
            'consoleUrl' => 'https://console.anthropic.com/settings/keys',
            'freeTier' => false,
            'recommended' => false,
            'validation' => [
                'method' => 'GET',
                'url' => 'https://api.anthropic.com/v1/models',
                'headers' => ['x-api-key' => '{key}', 'anthropic-version' => '2023-06-01'],
            ],
        ],
        'google' => [
            'displayName' => 'Google Gemini',
            'envVar' => 'GOOGLE_GEMINI_API_KEY',
            'consoleUrl' => 'https://aistudio.google.com/apikey',
            'freeTier' => true,
            'recommended' => false,
            'validation' => [
                'method' => 'GET',
                'url' => 'https://generativelanguage.googleapis.com/v1beta/models',
                'headers' => ['x-goog-api-key' => '{key}'],
            ],
        ],
        'mistral' => [
            'displayName' => 'Mistral',
            'envVar' => 'MISTRAL_API_KEY',
            'consoleUrl' => 'https://console.mistral.ai/api-keys',
            'freeTier' => true,
            'recommended' => false,
            'validation' => [
                'method' => 'GET',
                'url' => 'https://api.mistral.ai/v1/models',
                'headers' => ['Authorization' => 'Bearer {key}'],
            ],
        ],
        'trustedtokens' => [
            'displayName' => 'TrustedTokens',
            'envVar' => 'TRUSTEDTOKENS_API_KEY',
            'consoleUrl' => 'https://trustedtokens.eu/',
            'freeTier' => false,
            'recommended' => false,
            'validation' => [
                'method' => 'GET',
                'url' => 'https://api.trustedtokens.eu/v1/models',
                'headers' => ['Authorization' => 'Bearer {key}'],
            ],
        ],
        'a2agent' => [
            'displayName' => 'A2Agent',
            'envVar' => 'A2AGENT_API_KEY',
            'consoleUrl' => 'https://a2agent.me/',
            'freeTier' => false,
            'recommended' => false,
            'validation' => [
                'method' => 'GET',
                'url' => 'https://a2agent.me/v1/models',
                'headers' => ['Authorization' => 'Bearer {key}'],
            ],
        ],
        'huggingface' => [
            'displayName' => 'HuggingFace',
            'envVar' => 'HUGGINGFACE_API_KEY',
            'consoleUrl' => 'https://huggingface.co/settings/tokens',
            'freeTier' => true,
            'recommended' => false,
            'modelListing' => false,
            'validation' => [
                'method' => 'GET',
                'url' => 'https://huggingface.co/api/whoami-v2',
                'headers' => ['Authorization' => 'Bearer {key}'],
            ],
        ],
        'xai' => [
            'displayName' => 'xAI',
            'envVar' => 'XAI_API_KEY',
            'consoleUrl' => 'https://console.x.ai/',
            'freeTier' => false,
            'recommended' => false,
            'validation' => [
                'method' => 'GET',
                'url' => 'https://api.x.ai/v1/models',
                'headers' => ['Authorization' => 'Bearer {key}'],
            ],
        ],
        'perplexity' => [
            'displayName' => 'Perplexity',
            'envVar' => 'PERPLEXITY_API_KEY',
            'consoleUrl' => 'https://www.perplexity.ai/account/api',
            'freeTier' => false,
            'recommended' => false,
            'validation' => [
                'method' => 'GET',
                'url' => 'https://api.perplexity.ai/models',
                'headers' => ['Authorization' => 'Bearer {key}'],
            ],
        ],
        // Media and speech providers: same store, same card, so an operator
        // has exactly one place for every instance key. `chat => false` keeps
        // them out of chat-only surfaces (first-run wizard, "use as default").
        'thehive' => [
            'displayName' => 'TheHive',
            'envVar' => 'THEHIVE_API_KEY',
            'consoleUrl' => 'https://thehive.ai/',
            'freeTier' => false,
            'recommended' => false,
            'chat' => false,
            // TheHive exposes no authenticated read-only endpoint that does
            // not bill a generation — the key is stored untested.
            'validation' => null,
        ],
        'higgsfield' => [
            'displayName' => 'Higgsfield',
            'envVar' => 'HIGGSFIELD_API_KEY',
            'secretEnvVar' => 'HIGGSFIELD_API_SECRET',
            'consoleUrl' => 'https://cloud.higgsfield.ai/',
            'freeTier' => false,
            'recommended' => false,
            'modelListing' => false,
            'chat' => false,
            'validation' => [
                'method' => 'GET',
                'url' => 'https://platform.higgsfield.ai/',
                'headers' => ['Authorization' => 'Key {key}:{secret}'],
            ],
        ],
        'elevenlabs' => [
            'displayName' => 'ElevenLabs',
            'envVar' => 'ELEVENLABS_API_KEY',
            'consoleUrl' => 'https://elevenlabs.io/app/settings/api-keys',
            'freeTier' => true,
            'recommended' => false,
            'modelListing' => false,
            'chat' => false,
            'validation' => [
                'method' => 'GET',
                'url' => 'https://api.elevenlabs.io/v1/user',
                'headers' => ['xi-api-key' => '{key}'],
            ],
        ],
    ];

    /**
     * @return list<string>
     */
    public static function providerNames(): array
    {
        return array_keys(self::PROVIDERS);
    }

    public static function has(string $provider): bool
    {
        return isset(self::PROVIDERS[strtolower($provider)]);
    }

    /**
     * Reverse lookup: which provider does an env var (e.g. "GROQ_API_KEY" or
     * "HIGGSFIELD_API_SECRET") bootstrap? Used by the admin system-config
     * surface to mark those fields as managed by Models & keys.
     */
    public static function providerForEnvVar(string $envVar): ?string
    {
        foreach (self::PROVIDERS as $name => $meta) {
            if ($meta['envVar'] === $envVar || ($meta['secretEnvVar'] ?? null) === $envVar) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Is this env var the *secret* half of a key + secret pair?
     */
    public static function isSecretEnvVar(string $envVar): bool
    {
        foreach (self::PROVIDERS as $meta) {
            if (($meta['secretEnvVar'] ?? null) === $envVar) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every env var the catalog manages (keys and secrets), in catalog order.
     *
     * @return list<string>
     */
    public static function managedEnvVars(): array
    {
        $vars = [];
        foreach (self::PROVIDERS as $meta) {
            $vars[] = $meta['envVar'];
            if (isset($meta['secretEnvVar'])) {
                $vars[] = $meta['secretEnvVar'];
            }
        }

        return $vars;
    }

    public static function secretEnvVarFor(string $provider): ?string
    {
        return self::get($provider)['secretEnvVar'] ?? null;
    }

    public static function requiresSecret(string $provider): bool
    {
        return null !== self::secretEnvVarFor($provider);
    }

    /**
     * Can the key be probed live? False for providers with `validation: null`.
     */
    public static function isTestable(string $provider): bool
    {
        return null !== self::get($provider)['validation'];
    }

    /**
     * Does a key for this provider make chat possible? Media and speech
     * providers share the store and the card but never become the default
     * chat provider and are not offered in the first-run wizard.
     */
    public static function servesChat(string $provider): bool
    {
        return self::get($provider)['chat'] ?? true;
    }

    /**
     * Does the validation endpoint return the provider's model list? Only then
     * may model health and the inventory read it as an availability oracle.
     */
    public static function listsModels(string $provider): bool
    {
        $meta = self::get($provider);

        return null !== $meta['validation'] && ($meta['modelListing'] ?? true);
    }

    /**
     * @return array{displayName: string, envVar: string, secretEnvVar?: string, consoleUrl: string, freeTier: bool, recommended: bool, modelListing?: bool, chat?: bool, validation: array{method: string, url: string, headers: array<string, string>}|null}
     */
    public static function get(string $provider): array
    {
        $provider = strtolower($provider);
        if (!isset(self::PROVIDERS[$provider])) {
            throw new \InvalidArgumentException(sprintf('Unknown AI provider "%s". Supported: %s.', $provider, implode(', ', array_keys(self::PROVIDERS))));
        }

        return self::PROVIDERS[$provider];
    }
}
