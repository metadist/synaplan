<?php

declare(strict_types=1);

namespace App\AI\Credential;

use App\AI\Catalog\ModelListing;
use App\AI\Catalog\ProviderCatalog;
use App\AI\Catalog\ProviderDescriptor;
use App\AI\Catalog\ProviderKeySpec;

/**
 * The key lifecycle of every provider whose platform key the
 * {@see ProviderKeyStore} manages: which env var bootstraps the key, where a
 * person gets one, and what to probe for live validation — read from the
 * provider descriptors ({@see ProviderCatalog::withKey()}), so a provider
 * class is the only place that declares them.
 *
 * Providers without a platform key (Ollama, Piper, OpenAI-compatible
 * endpoints, Cloudflare's env-only token) are deliberately absent.
 *
 * Every env var listed here is *managed*: the legacy system-config page
 * shows it read-only and points at Providers & keys ({@see SystemConfigService}).
 */
final readonly class ProviderKeyCatalog
{
    public const MANAGED_BY = 'ai-infrastructure';

    public function __construct(
        private ProviderCatalog $catalog,
    ) {
    }

    /**
     * Admin → AI keys order.
     *
     * @return list<string>
     */
    public function providerNames(): array
    {
        return array_map(static fn (ProviderDescriptor $d): string => $d->name, $this->catalog->withKey());
    }

    public function has(string $provider): bool
    {
        return true === $this->catalog->find($provider)?->hasPlatformKey();
    }

    /**
     * Reverse lookup: which provider does an env var (e.g. "GROQ_API_KEY" or
     * "HIGGSFIELD_API_SECRET") bootstrap? Used by the admin system-config
     * surface to mark those fields as managed by Providers & keys.
     */
    public function providerForEnvVar(string $envVar): ?string
    {
        foreach ($this->catalog->withKey() as $descriptor) {
            $key = $descriptor->key;
            if (null !== $key && ($key->envVar === $envVar || $key->secretEnvVar === $envVar)) {
                return $descriptor->name;
            }
        }

        return null;
    }

    /**
     * Is this env var the *secret* half of a key + secret pair?
     */
    public function isSecretEnvVar(string $envVar): bool
    {
        foreach ($this->catalog->withKey() as $descriptor) {
            if ($descriptor->key?->secretEnvVar === $envVar) {
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
    public function managedEnvVars(): array
    {
        $vars = [];
        foreach ($this->catalog->withKey() as $descriptor) {
            $key = $descriptor->key;
            if (null === $key || null === $key->envVar) {
                continue;
            }
            $vars[] = $key->envVar;
            if (null !== $key->secretEnvVar) {
                $vars[] = $key->secretEnvVar;
            }
        }

        return $vars;
    }

    public function secretEnvVarFor(string $provider): ?string
    {
        return $this->spec($provider)->secretEnvVar;
    }

    public function requiresSecret(string $provider): bool
    {
        return $this->spec($provider)->requiresSecret();
    }

    /**
     * Can the key be probed live? False for providers without a validation request.
     */
    public function isTestable(string $provider): bool
    {
        return $this->spec($provider)->isTestable();
    }

    /**
     * Does a key for this provider make chat possible? Media and speech
     * providers share the store and the card but never become the default
     * chat provider and are not offered in the first-run wizard.
     */
    public function servesChat(string $provider): bool
    {
        return $this->spec($provider)->servesChat;
    }

    /**
     * Does the validation endpoint return the provider's model list? Only then
     * may model health and the inventory read it as an availability oracle.
     */
    public function listsModels(string $provider): bool
    {
        return ModelListing::Lists === $this->spec($provider)->modelListing;
    }

    public function spec(string $provider): ProviderKeySpec
    {
        $descriptor = $this->catalog->find($provider);
        if (null === $descriptor || !$descriptor->hasPlatformKey() || null === $descriptor->key) {
            throw new \InvalidArgumentException(sprintf('Unknown AI provider "%s". Supported: %s.', strtolower($provider), implode(', ', $this->providerNames())));
        }

        return $descriptor->key;
    }

    /**
     * The key row as the admin surfaces read it.
     *
     * @return array{displayName: string, envVar: string, secretEnvVar?: string, consoleUrl: string, freeTier: bool, recommended: bool, modelListing?: bool, chat?: bool, validation: array{method: string, url: string, headers: array<string, string>}|null}
     */
    public function get(string $provider): array
    {
        $descriptor = $this->catalog->find($provider);
        $key = $this->spec($provider);
        \assert(null !== $descriptor);

        $row = [
            'displayName' => $key->presentation->displayName ?? $descriptor->displayName,
            'envVar' => (string) $key->envVar,
        ];
        if (null !== $key->secretEnvVar) {
            $row['secretEnvVar'] = $key->secretEnvVar;
        }
        $row['consoleUrl'] = (string) $key->consoleUrl;
        $row['freeTier'] = $key->presentation->freeTier;
        $row['recommended'] = $key->presentation->recommended;
        if (ModelListing::Lists !== $key->modelListing && null !== $key->validation) {
            $row['modelListing'] = false;
        }
        if (!$key->servesChat) {
            $row['chat'] = false;
        }
        $row['validation'] = $key->validation?->toArray();

        return $row;
    }
}
