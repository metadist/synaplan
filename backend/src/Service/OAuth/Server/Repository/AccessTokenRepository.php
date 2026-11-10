<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server\Repository;

use App\Entity\OAuthToken;
use App\Repository\OAuthTokenRepository;
use App\Service\OAuth\Server\AccessTokenKeyStore;
use App\Service\OAuth\Server\Entity\AccessToken;
use App\Service\OAuth\Server\GrantContext;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;

final readonly class AccessTokenRepository implements AccessTokenRepositoryInterface
{
    public function __construct(
        private OAuthTokenRepository $tokens,
        private AccessTokenKeyStore $keys,
        private GrantContext $context,
        private string $issuer,
    ) {
    }

    public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, ?string $userIdentifier = null): AccessTokenEntityInterface
    {
        $token = new AccessToken(rtrim($this->issuer, '/'), $this->keys->kid());
        $token->setExternalId($this->context->requireExternalId());
        $token->setClient($clientEntity);
        foreach ($scopes as $scope) {
            $token->addScope($scope);
        }
        if (null !== $userIdentifier) {
            $token->setUserIdentifier($userIdentifier);
        }

        return $token;
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $user = $accessTokenEntity->getUserIdentifier();
        $this->tokens->save(new OAuthToken(
            $accessTokenEntity->getIdentifier(),
            OAuthToken::KIND_ACCESS,
            $accessTokenEntity->getClient()->getIdentifier(),
            null === $user ? null : (int) $user,
            array_map(static fn ($scope): string => $scope->getIdentifier(), $accessTokenEntity->getScopes()),
            $accessTokenEntity->getExpiryDateTime()->getTimestamp(),
            null,
            $accessTokenEntity instanceof AccessToken ? $accessTokenEntity->externalId() : $this->context->requireExternalId(),
        ));
    }

    public function revokeAccessToken(string $tokenId): void
    {
        $this->tokens->revoke($tokenId);
    }

    public function isAccessTokenRevoked(string $tokenId): bool
    {
        return $this->tokens->isRevoked($tokenId);
    }
}
