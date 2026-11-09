<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server\Repository;

use App\Entity\OAuthToken;
use App\Repository\OAuthTokenRepository;
use App\Service\OAuth\Server\Entity\AuthCode;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;

final readonly class AuthCodeRepository implements AuthCodeRepositoryInterface
{
    public function __construct(private OAuthTokenRepository $tokens)
    {
    }

    public function getNewAuthCode(): AuthCodeEntityInterface
    {
        return new AuthCode();
    }

    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        $user = $authCodeEntity->getUserIdentifier();
        $this->tokens->save(new OAuthToken(
            $authCodeEntity->getIdentifier(),
            OAuthToken::KIND_CODE,
            $authCodeEntity->getClient()->getIdentifier(),
            null === $user ? null : (int) $user,
            array_map(static fn ($scope): string => $scope->getIdentifier(), $authCodeEntity->getScopes()),
            $authCodeEntity->getExpiryDateTime()->getTimestamp(),
        ));
    }

    public function revokeAuthCode(string $codeId): void
    {
        $this->tokens->revoke($codeId);
    }

    public function isAuthCodeRevoked(string $codeId): bool
    {
        return $this->tokens->isRevoked($codeId);
    }
}
