<?php

declare(strict_types=1);

namespace App\Service\OAuth;

/**
 * An operator-configured OAuth2 app registration the install can offer.
 *
 * Implementations own where their credentials live (BCONFIG, env, …); the
 * framework only needs to know whether a provider is usable and what its
 * endpoints are.
 */
interface OAuthProviderSource
{
    /** Stable id used in routes, the signed state and the connection config. */
    public function provider(): string;

    /** False when the operator has not (fully) configured the app registration. */
    public function isConfigured(): bool;

    public function toProviderConfig(): OAuthProviderConfig;
}
