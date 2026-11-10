<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server\Repository;

use App\Entity\OAuthToken;
use App\Repository\OAuthTokenRepository;
use App\Service\OAuth\Server\Entity\RefreshToken;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;

final readonly class RefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    public const ROTATION_GRACE = 60;

    public function __construct(private OAuthTokenRepository $tokens)
    {
    }

    public function getNewRefreshToken(): RefreshTokenEntityInterface
    {
        return new RefreshToken();
    }

    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        $access = $refreshTokenEntity->getAccessToken();
        $user = $access->getUserIdentifier();
        $this->tokens->save(new OAuthToken(
            $refreshTokenEntity->getIdentifier(),
            OAuthToken::KIND_REFRESH,
            $access->getClient()->getIdentifier(),
            null === $user ? null : (int) $user,
            array_map(static fn ($scope): string => $scope->getIdentifier(), $access->getScopes()),
            $refreshTokenEntity->getExpiryDateTime()->getTimestamp(),
            $access->getIdentifier(),
        ));
    }

    /**
     * League calls this when it rotates a refresh token. The old one keeps
     * working for ROTATION_GRACE seconds, so a client whose answer got lost
     * can repeat the refresh instead of losing the person's grant. Revoking
     * the token or the grant still ends it at once.
     */
    public function revokeRefreshToken(string $tokenId): void
    {
        $this->tokens->retire($tokenId, time() + self::ROTATION_GRACE);
    }

    public function isRefreshTokenRevoked(string $tokenId): bool
    {
        return $this->tokens->isRevoked($tokenId);
    }
}
