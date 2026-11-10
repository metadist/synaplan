<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server;

use App\Entity\PlatformInstance;
use App\Repository\PlatformInstanceRepository;
use App\Security\ApiKeyScope;
use App\Service\OAuth\Server\Entity\User as OAuthUser;
use App\Service\OAuth\Server\Repository\ScopeRepository;
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
        private CloudLinkService $links,
    ) {
    }

    /**
     * Which cloud asks, for which of its accounts, and what it may do then.
     * The scopes are the ones the tokens will carry: a request that names
     * none gets the default set.
     *
     * @return array{client: string, host: string, external_id: string, scopes: list<string>}|null
     */
    public function describe(string $id): ?array
    {
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
        ];
    }

    /**
     * Where the browser goes next (back to the cloud, with a code or the
     * refusal), or null when the request is unknown, ran out, was decided
     * before, or its cloud is no longer a client.
     */
    public function decide(string $id, int $userId, bool $approve, string $ip): ?string
    {
        $params = $this->requests->find($id);
        if (null === $params) {
            return null;
        }
        $this->requests->forget($id);

        $server = $this->servers->create();
        $codeIssued = false;
        try {
            $authorization = $server->validateAuthorizationRequest(Psr7::query($params));
            $authorization->setUser(new OAuthUser($userId));
            $authorization->setAuthorizationApproved($approve);
            $response = $server->completeAuthorizationRequest($authorization, Psr7::response());
            $codeIssued = true;
        } catch (OAuthServerException $e) {
            $response = $e->generateHttpResponse(Psr7::response());
        }
        $location = $response->getHeaderLine('Location');
        if ('' === $location) {
            return null;
        }
        $instance = $codeIssued ? $this->activeClient($params['client_id'] ?? '') : null;
        if (null !== $instance) {
            $this->links->link($userId, $instance, $params['external_id'] ?? '', $ip);
        }

        return $location;
    }

    private function activeClient(string $clientId): ?PlatformInstance
    {
        $instance = $this->instances->findByInstanceId($clientId);

        return $instance instanceof PlatformInstance && $instance->isActive() ? $instance : null;
    }
}
