<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server\Repository;

use App\Entity\PlatformInstance;
use App\Repository\PlatformInstanceRepository;
use App\Service\OAuth\Server\Entity\Client;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;

/**
 * Only an approved cloud is a client. Clients authenticate with
 * private_key_jwt inside the grants ({@see \App\Service\OAuth\Server\Grant\AuthenticatesWithClientAssertion});
 * none has a secret, so secret validation always fails.
 */
final readonly class ClientRepository implements ClientRepositoryInterface
{
    public function __construct(
        private PlatformInstanceRepository $instances,
    ) {
    }

    public function getClientEntity(string $clientIdentifier): ?ClientEntityInterface
    {
        $instance = $this->instances->findByInstanceId($clientIdentifier);
        if (!$instance instanceof PlatformInstance || PlatformInstance::STATUS_ACTIVE !== $instance->getStatus() || null === $instance->getJwks()) {
            return null;
        }

        return new Client($instance);
    }

    public function validateClient(string $clientIdentifier, ?string $clientSecret, ?string $grantType): bool
    {
        return false;
    }
}
