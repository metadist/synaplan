<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server;

use App\Entity\PlatformInstance;
use App\Service\PlatformLink\PlatformInstanceService;
use League\OAuth2\Server\Exception\OAuthServerException;

/**
 * Pushed authorization requests (RFC 9126). A cloud sends the parameters of
 * an authorization request from its server, authenticated as the client,
 * and the browser carries only a one-time reference to them.
 *
 * This is the only way to start an authorization: the request names the
 * cloud account that is being linked (external_id), and that must be what
 * the cloud says, not what a browser was sent with. Otherwise anyone
 * signed in to Synaplan could claim another person's cloud account.
 */
final readonly class PushedAuthorizationService
{
    /** Client authentication travels with the push and is not part of the request. */
    private const NOT_PARKED = ['client_assertion', 'client_assertion_type', 'client_secret'];

    public function __construct(
        private OAuthServerFactory $servers,
        private AuthorizationRequestStore $requests,
    ) {
    }

    /**
     * Checks and keeps the request; returns its request_uri.
     *
     * @param array<array-key, mixed> $params the form the cloud posted
     *
     * @throws OAuthServerException when the request is not one Synaplan would show for consent
     */
    public function push(PlatformInstance $client, array $params): string
    {
        $params = array_diff_key(
            array_filter($params, static fn (mixed $v, mixed $k): bool => is_string($v) && is_string($k), \ARRAY_FILTER_USE_BOTH),
            array_flip(self::NOT_PARKED),
        );
        if (isset($params['request_uri'])) {
            throw OAuthServerException::invalidRequest('request_uri', 'A pushed request cannot name a request_uri.');
        }
        if ('S256' !== ($params['code_challenge_method'] ?? null) || '' === ($params['code_challenge'] ?? '')) {
            throw OAuthServerException::invalidRequest('code_challenge', 'PKCE with S256 is required.');
        }
        $externalId = trim($params['external_id'] ?? '');
        if ('' === $externalId || mb_strlen($externalId) > PlatformInstanceService::EXTERNAL_ID_MAX_LENGTH) {
            throw OAuthServerException::invalidRequest('external_id', 'external_id names the person in the cloud and is required.');
        }
        $params['external_id'] = $externalId;
        $params['client_id'] = $client->getInstanceId();
        $this->servers->create()->validateAuthorizationRequest(Psr7::query($params));

        return AuthorizationRequestStore::REQUEST_URI_PREFIX.$this->requests->park($params);
    }

    /**
     * The id of the pushed request a browser arrived with, or null when the
     * reference is unknown, ran out, or belongs to another client.
     */
    public function resolve(string $clientId, string $requestUri): ?string
    {
        if (!str_starts_with($requestUri, AuthorizationRequestStore::REQUEST_URI_PREFIX)) {
            return null;
        }
        $id = substr($requestUri, strlen(AuthorizationRequestStore::REQUEST_URI_PREFIX));
        $params = $this->requests->find($id);

        return null !== $params && '' !== $clientId && ($params['client_id'] ?? null) === $clientId ? $id : null;
    }
}
