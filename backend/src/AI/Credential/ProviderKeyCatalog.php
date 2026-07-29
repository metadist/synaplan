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
 */
final class ProviderKeyCatalog
{
    /**
     * @var array<string, array{
     *     displayName: string,
     *     envVar: string,
     *     consoleUrl: string,
     *     freeTier: bool,
     *     recommended: bool,
     *     validation: array{method: string, url: string, headers: array<string, string>}
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
        'huggingface' => [
            'displayName' => 'HuggingFace',
            'envVar' => 'HUGGINGFACE_API_KEY',
            'consoleUrl' => 'https://huggingface.co/settings/tokens',
            'freeTier' => true,
            'recommended' => false,
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
     * Reverse lookup: which provider does an env var (e.g. "GROQ_API_KEY")
     * bootstrap? Used by the admin system-config surface to route writes of
     * those fields into the ProviderKeyStore instead of the .env file.
     */
    public static function providerForEnvVar(string $envVar): ?string
    {
        foreach (self::PROVIDERS as $name => $meta) {
            if ($meta['envVar'] === $envVar) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @return array{displayName: string, envVar: string, consoleUrl: string, freeTier: bool, recommended: bool, validation: array{method: string, url: string, headers: array<string, string>}}
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
