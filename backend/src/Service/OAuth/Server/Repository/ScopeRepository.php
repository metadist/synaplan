<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server\Repository;

use App\Security\ApiKeyScope;
use App\Service\OAuth\Server\Entity\Scope;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;

/**
 * The scopes a cloud may ask for are the platform scopes API keys of linked
 * people carry ({@see ApiKeyScope}); a token is checked against them the same
 * way. Without a scope, a cloud gets everything except memories.
 */
final readonly class ScopeRepository implements ScopeRepositoryInterface
{
    public const SUPPORTED = [
        ApiKeyScope::PLATFORM_CHAT,
        ApiKeyScope::PLATFORM_FILES,
        ApiKeyScope::PLATFORM_RAG,
        ApiKeyScope::PLATFORM_WATCH,
        ApiKeyScope::PLATFORM_MEMORIES,
    ];

    public function getScopeEntityByIdentifier(string $identifier): ?ScopeEntityInterface
    {
        return in_array($identifier, self::SUPPORTED, true) ? new Scope($identifier) : null;
    }

    public function finalizeScopes(array $scopes, string $grantType, ClientEntityInterface $clientEntity, ?string $userIdentifier = null, ?string $authCodeId = null): array
    {
        if ([] !== $scopes) {
            return array_values($scopes);
        }

        return array_map(static fn (string $id): Scope => new Scope($id), ApiKeyScope::platformLinkScopes());
    }
}
