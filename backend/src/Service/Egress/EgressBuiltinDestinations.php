<?php

declare(strict_types=1);

namespace App\Service\Egress;

use App\AI\Catalog\ProviderCatalog;

/**
 * Destinations the installation always talks to. The AI providers' hosts
 * come from their descriptors ({@see ProviderCatalog::endpoints()}), so a
 * provider added in one class is allowed without an entry here. A host rule
 * matches any port. A `*.example.org` rule is one wildcard label. A leading
 * dot is a suffix (`.svc.cluster.local`).
 */
final class EgressBuiltinDestinations
{
    /**
     * Docker and Kubernetes names of this installation's own services.
     *
     * @var list<string>
     */
    public const INTERNAL = [
        'localhost',
        'localhost.localdomain',
        'host.docker.internal',
        'qdrant',
        'tika',
        'centrifugo',
        'ollama',
        'redis',
        'db',
        'mailhog',
        'backend',
        'compute',
        'voice',
        'transcriber',
        'keycloak',
        'egress',
        '.svc.cluster.local',
        '.cluster.local',
        '.internal',
    ];

    /**
     * Integrations that are not AI providers: payments, messengers, web
     * search, sign-in and app stores, source hosting, bot checks. Custom
     * OpenAI-compatible base URLs are not here: in `report` mode they show
     * up as `would_block` until an administrator lists them.
     *
     * @var list<string>
     */
    public const INTEGRATIONS = [
        'api.stripe.com',
        'api.telegram.org',
        'graph.facebook.com',
        'api.search.brave.com',
        'accounts.google.com',
        'androidpublisher.googleapis.com',
        'oauth2.googleapis.com',
        'www.googleapis.com',
        'github.com',
        'api.github.com',
        'appleid.apple.com',
        'www.google.com',
        'www.recaptcha.net',
        'challenges.cloudflare.com',
    ];

    /**
     * @return list<string>
     */
    public static function rules(ProviderCatalog $catalog): array
    {
        return [...self::INTERNAL, ...$catalog->endpoints(), ...self::INTEGRATIONS];
    }
}
