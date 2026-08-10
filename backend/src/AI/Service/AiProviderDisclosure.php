<?php

declare(strict_types=1);

namespace App\AI\Service;

use App\AI\Credential\ChatReadinessService;
use App\Repository\ModelRepository;

/**
 * MOBILE-APP SEAM (App Review 5.1.2(i) / Play "prominent disclosure"): names
 * the AI providers a user's input can reach.
 *
 * Since November 2025 Apple requires the consent screen to identify the
 * third-party AI by name — "external AI providers" is not enough. The app shows
 * that screen before sign-in, so the list has to come from the public runtime
 * config, and it has to reflect the instance the app is pointed at rather than
 * a list baked into the bundle.
 *
 * Two filters make the list match reality rather than the catalog: the model
 * has to be pickable in chat, and the provider has to be usable. A provider
 * without a configured key cannot receive anything, and listing it would pad a
 * disclosure that people only read while it stays short.
 */
final readonly class AiProviderDisclosure
{
    public function __construct(
        private ModelRepository $modelRepository,
        private ProviderRegistry $providerRegistry,
        private ChatReadinessService $chatReadiness,
    ) {
    }

    /**
     * Display names of the providers behind the usable, selectable chat models.
     *
     * @return list<string> Sorted and deduplicated, e.g. ['Anthropic', 'Google AI']
     */
    public function chatProviderNames(): array
    {
        $displayNames = [];
        foreach ($this->providerRegistry->getUniqueProviders() as $provider) {
            $displayNames[strtolower($provider->getName())] = $provider->getDisplayName();
        }

        // Cached for 30s inside the service — the public config is polled, and
        // a fresh probe per request would put an Ollama round trip on an
        // unauthenticated endpoint.
        $available = $this->chatReadiness->providerAvailability();

        $names = [];
        foreach ($this->modelRepository->findSelectableChatServices() as $service) {
            $key = strtolower(trim($service));

            // Unknown to the registry means there is no client that could send
            // anything, so it belongs in no disclosure. 'test' is a fixture.
            if ('' === $key || 'test' === $key || !isset($displayNames[$key])) {
                continue;
            }

            if (!($available[$key] ?? false)) {
                continue;
            }

            $names[$key] = $displayNames[$key];
        }

        $names = array_values($names);
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
    }
}
