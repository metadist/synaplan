<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server;

use App\Entity\OAuthToken;
use App\Entity\PlatformInstance;
use App\Repository\OAuthTokenRepository;

/**
 * Token revocation (RFC 7009). An access token ends alone. A refresh token
 * ends the person's grant and their link with the cloud: a cloud revokes it
 * when the person disconnects there or the cloud account is deleted or
 * disabled. A token that is unknown, or another client's, changes nothing.
 */
final readonly class TokenRevocationService
{
    public function __construct(
        private OAuthServerFactory $servers,
        private AccessTokenValidator $accessTokens,
        private CloudLinkService $links,
        private TokenCipher $cipher,
        private OAuthTokenRepository $tokens,
    ) {
    }

    public function revoke(PlatformInstance $client, string $token, string $ip): void
    {
        if ('' === $token) {
            return;
        }
        $access = $this->accessTokens->validate($token, false);
        if (null !== $access) {
            if ($access['client_id'] === $client->getInstanceId()) {
                $this->servers->accessTokens()->revokeAccessToken($access['jti']);
            }

            return;
        }
        $refresh = $this->liveRefreshToken($client, $token);
        if ($refresh instanceof OAuthToken && null !== $refresh->getUserId() && null !== $refresh->getExternalId()) {
            $this->links->endByCloud($client, $refresh->getUserId(), $refresh->getExternalId(), $ip);
        }
    }

    /**
     * A refresh token of this client that is still live. An authorization
     * code decrypts with the same key but is not one, and a token that
     * already expired or was rotated does not end the link again.
     */
    private function liveRefreshToken(PlatformInstance $client, string $token): ?OAuthToken
    {
        $data = $this->cipher->open($token);
        $id = is_array($data) ? ($data['refresh_token_id'] ?? null) : null;
        if (!is_string($id) || ($data['client_id'] ?? null) !== $client->getInstanceId()) {
            return null;
        }
        $row = $this->tokens->find($id);
        if (!$row instanceof OAuthToken
            || OAuthToken::KIND_REFRESH !== $row->getKind()
            || $row->getClientId() !== $client->getInstanceId()
            || $row->isRevoked()
            || $row->getExpires() < time()
            || null === $row->getUserId()
            || null === $row->getExternalId()
            || '' === $row->getExternalId()
        ) {
            return null;
        }

        return $row;
    }
}
