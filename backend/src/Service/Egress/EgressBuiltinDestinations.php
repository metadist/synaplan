<?php

declare(strict_types=1);

namespace App\Service\Egress;

/**
 * Destinations the installation always talks to, until a provider describes
 * itself (AU22). A host rule matches any port. A `*.example.org` rule is one
 * wildcard label. A leading dot is a suffix (`.svc.cluster.local`).
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
     * Cloud provider and integration hosts. Custom OpenAI-compatible base
     * URLs are not here: in `report` mode they show up as `would_block`,
     * which is the gap AU22 closes.
     *
     * @var list<string>
     */
    public const PROVIDERS = [
        'api.openai.com',
        'api.anthropic.com',
        'generativelanguage.googleapis.com',
        'api.groq.com',
        'api.mistral.ai',
        'api.x.ai',
        'router.huggingface.co',
        'api.trustedtokens.eu',
        'a2agent.me',
        'api.meta.ai',
        'api.cerebras.ai',
        'api.perplexity.ai',
        'api.stripe.com',
        'api.telegram.org',
        'graph.facebook.com',
        'api.search.brave.com',
        'accounts.google.com',
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
    public static function rules(): array
    {
        return [...self::INTERNAL, ...self::PROVIDERS];
    }
}
