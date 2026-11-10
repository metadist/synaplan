<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server;

use App\Entity\PlatformInstance;
use App\Repository\PlatformInstanceRepository;
use App\Security\ApiKeyScope;
use App\Service\OAuth\Server\Entity\User as OAuthUser;
use App\Service\OAuth\Server\Repository\ScopeRepository;
use App\Service\PlatformLink\PlatformLinksConfig;
use League\OAuth2\Server\Exception\OAuthServerException;

/**
 * The person's decision on a pushed authorization request: what the consent
 * page shows, and what allowing or denying does. A request is decided once.
 */
final readonly class ConsentService
{
    public function __construct(
        private AuthorizationRequestStore $requests,
        private OAuthServerFactory $servers,
        private PlatformInstanceRepository $instances,
        private PlatformLinksConfig $platformLinks,
        private GrantContext $context,
    ) {
    }

    /**
     * Which cloud asks, for which of its accounts, and what it may do then.
     * The scopes are the ones the tokens will carry: a request that names
     * none gets the default set.
     *
     * @return array{client: string, host: string, external_id: string, scopes: list<string>, unreviewed: bool}|null
     */
    public function describe(string $id, int $userId): ?array
    {
        if (!$this->platformLinks->isEnabled($userId)) {
            return null;
        }
        $params = $this->requests->find($id);
        $instance = null === $params ? null : $this->activeClient($params['client_id'] ?? '');
        if (null === $params || null === $instance) {
            return null;
        }
        $requested = array_values(array_intersect(
            ScopeRepository::SUPPORTED,
            explode(' ', $params['scope'] ?? ''),
        ));

        return [
            'client' => $instance->getClient(),
            'host' => $instance->getHost(),
            'external_id' => $params['external_id'] ?? '',
            'scopes' => [] !== $requested ? $requested : ApiKeyScope::platformLinkScopes(),
            'unreviewed' => 0 === $instance->getRegisteredBy(),
        ];
    }

    /**
     * Where the browser goes next (back to the cloud, with a code or the
     * refusal), or null when the request is unknown, ran out, was decided
     * before, or its cloud is no longer a client.
     */
    public function decide(string $id, int $userId, bool $approve): ?string
    {
        if (!$this->platformLinks->isEnabled($userId)) {
            return null;
        }
        $params = $this->requests->find($id);
        if (null === $params) {
            return null;
        }
        $this->requests->forget($id);

        // The code remembers the cloud account. The link itself waits until
        // the cloud redeems the code.
        $this->context->setExternalId($params['external_id'] ?? null);
        $server = $this->servers->create();
        try {
            $authorization = $server->validateAuthorizationRequest(Psr7::query($params));
            $authorization->setUser(new OAuthUser($userId));
            $authorization->setAuthorizationApproved($approve);
            $response = $server->completeAuthorizationRequest($authorization, Psr7::response());
        } catch (OAuthServerException $e) {
            $response = $e->generateHttpResponse(Psr7::response());
        } finally {
            $this->context->setExternalId(null);
        }
        $location = $response->getHeaderLine('Location');

        return '' === $location ? null : $location;
    }

    private function activeClient(string $clientId): ?PlatformInstance
    {
        $instance = $this->instances->findByInstanceId($clientId);

        return $instance instanceof PlatformInstance && $instance->isActive() ? $instance : null;
    }
}
