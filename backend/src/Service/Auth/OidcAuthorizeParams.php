<?php

declare(strict_types=1);

namespace App\Service\Auth;

/**
 * Provider-specific parameters added to the OIDC authorization request.
 *
 * - `audience`: login validates the access token's `aud` against
 *   OIDC_BEARER_AUDIENCE. Kinde and Auth0 only put an audience into the token
 *   when the authorization request names it (otherwise `aud` is empty, or the
 *   token is opaque), so an explicit OIDC_BEARER_AUDIENCE is requested here.
 * - `org_code`: Kinde selects the organization from it; the token claim is
 *   still checked by {@see OidcAccessPolicy}.
 *
 * Providers that do not know a parameter ignore it.
 */
final readonly class OidcAuthorizeParams
{
    public function __construct(
        private OidcAccessPolicy $accessPolicy,
        private string $bearerAudience = '',
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function params(): array
    {
        $params = [];

        $audience = trim($this->bearerAudience);
        if ('' !== $audience) {
            $params['audience'] = $audience;
        }

        $orgCode = $this->accessPolicy->orgCode();
        if (null !== $orgCode) {
            $params['org_code'] = $orgCode;
        }

        return $params;
    }
}
