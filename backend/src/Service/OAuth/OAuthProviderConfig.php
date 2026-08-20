<?php

declare(strict_types=1);

namespace App\Service\OAuth;

/**
 * Everything {@see OAuthClient} needs to talk to one authorization server.
 *
 * Provider-agnostic on purpose: Microsoft is the first consumer, Dropbox
 * (connector plan 07 C13) is the next one, and neither should have to
 * reimplement the authorization-code exchange.
 */
final readonly class OAuthProviderConfig
{
    /**
     * @param list<string>          $scopes
     * @param array<string, string> $extraAuthorizeParams        provider-specific query
     *                                                           params for the consent URL (Microsoft: `prompt=consent`,
     *                                                           Dropbox: `token_access_type=offline`); never sent to the
     *                                                           token endpoint
     * @param bool                  $includeScopeInTokenRequests Microsoft wants `scope` on
     *                                                           exchange/refresh (to keep
     *                                                           `offline_access`). Dropbox's
     *                                                           token endpoint does not list
     *                                                           `scope` and treating the
     *                                                           space-separated value as a
     *                                                           downscope leaves a token that
     *                                                           can read the account but cannot
     *                                                           upload files
     */
    public function __construct(
        public string $provider,
        public string $authorizeUrl,
        public string $tokenUrl,
        public string $clientId,
        public string $clientSecret,
        public string $redirectUri,
        public array $scopes,
        public array $extraAuthorizeParams = [],
        public bool $includeScopeInTokenRequests = true,
    ) {
    }

    public function scopeString(): string
    {
        return implode(' ', $this->scopes);
    }
}
