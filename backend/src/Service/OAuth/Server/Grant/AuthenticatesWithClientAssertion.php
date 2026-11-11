<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server\Grant;

use App\Entity\PlatformInstance;
use App\Service\OAuth\Server\ClientAssertionVerifier;
use App\Service\OAuth\Server\GrantContext;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RequestEvent;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Client authentication for league's grants by private_key_jwt (RFC 7523):
 * league only checks client secrets, and Synaplan's clients have none. The
 * grant refuses a token request whose assertion does not prove the client.
 */
trait AuthenticatesWithClientAssertion
{
    private ClientAssertionVerifier $assertions;

    private string $tokenEndpoint;

    private GrantContext $context;

    protected function validateClient(ServerRequestInterface $request): ClientEntityInterface
    {
        // A cloud account remembered for an earlier grant must not leak into this one.
        $this->context->setExternalId(null);
        $body = (array) $request->getParsedBody();
        $assertion = $body['client_assertion'] ?? null;
        $instance = ClientAssertionVerifier::TYPE === ($body['client_assertion_type'] ?? null) && is_string($assertion)
            ? $this->assertions->verify($assertion, [$this->tokenEndpoint])
            : null;
        $claimed = $body['client_id'] ?? null;
        if (!$instance instanceof PlatformInstance || (is_string($claimed) && '' !== $claimed && $claimed !== $instance->getInstanceId())) {
            $this->getEmitter()->emit(new RequestEvent(RequestEvent::CLIENT_AUTHENTICATION_FAILED, $request));

            throw OAuthServerException::invalidClient($request);
        }

        return $this->getClientEntityOrFail($instance->getInstanceId(), $request);
    }
}
