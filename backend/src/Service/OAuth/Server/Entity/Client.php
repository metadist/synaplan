<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server\Entity;

use App\Entity\PlatformInstance;
use League\OAuth2\Server\Entities\ClientEntityInterface;

/**
 * A cloud installation as an OAuth2 client: its client_id is the instance
 * id, and it may only use the authorization code and refresh token grants.
 *
 * Every cloud is a confidential client that authenticates by
 * private_key_jwt, but league only knows client secrets. The token and
 * revocation endpoints verify the assertion before league sees the request
 * and refuse without one, so league is told the client is public: it then
 * skips its secret check and insists on PKCE, which Synaplan requires anyway.
 */
final readonly class Client implements ClientEntityInterface
{
    public const GRANTS = ['authorization_code', 'refresh_token'];

    public function __construct(public PlatformInstance $instance)
    {
    }

    public function getIdentifier(): string
    {
        return $this->instance->getInstanceId();
    }

    public function getName(): string
    {
        return $this->instance->getHost();
    }

    /**
     * @return list<string>
     */
    public function getRedirectUri(): array
    {
        return $this->instance->getRedirectUris();
    }

    public function isConfidential(): bool
    {
        return true;
    }

    public function supportsGrantType(string $grantType): bool
    {
        return in_array($grantType, self::GRANTS, true);
    }
}
