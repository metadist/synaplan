<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server;

use App\Entity\PlatformInstance;
use Defuse\Crypto\Crypto;

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
        $userId = $this->refreshTokenOwner($client, $token);
        if (null !== $userId) {
            $this->links->endByCloud($client, $userId, $ip);
        }
    }

    /**
     * The person a refresh token of this client was issued for. League
     * encrypts refresh tokens with the server's key, so reading one proves
     * Synaplan issued it.
     */
    private function refreshTokenOwner(PlatformInstance $client, string $token): ?int
    {
        try {
            $data = json_decode(Crypto::decryptWithPassword($token, $this->servers->encryptionKey()), true);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($data) || ($data['client_id'] ?? null) !== $client->getInstanceId()) {
            return null;
        }
        $user = $data['user_id'] ?? null;
        if (is_int($user)) {
            return $user;
        }

        return is_string($user) && ctype_digit($user) ? (int) $user : null;
    }
}
