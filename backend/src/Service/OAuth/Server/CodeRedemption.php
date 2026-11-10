<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server;

use App\Entity\OAuthToken;
use App\Entity\PlatformInstance;
use App\Repository\OAuthTokenRepository;
use App\Repository\PlatformInstanceRepository;
use App\Service\PlatformLink\PlatformLinksConfig;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The link is created when the cloud redeems the authorization code, not when
 * the person allows it. Allowing only parks a code; until the cloud's own
 * server trades that code for tokens, nothing is connected.
 */
final readonly class CodeRedemption
{
    public function __construct(
        private TokenCipher $cipher,
        private OAuthTokenRepository $tokens,
        private PlatformInstanceRepository $instances,
        private CloudLinkService $links,
        private PlatformLinksConfig $platformLinks,
        private GrantContext $context,
    ) {
    }

    public function context(): GrantContext
    {
        return $this->context;
    }

    /**
     * @throws OAuthServerException when the code was redeemed but must not become a link
     */
    public function onCodeRedeemed(ServerRequestInterface $request): void
    {
        $body = (array) $request->getParsedBody();
        $code = $body['code'] ?? null;
        $data = is_string($code) ? $this->cipher->open($code) : null;
        $id = is_array($data) ? ($data['auth_code_id'] ?? null) : null;
        $row = is_string($id) ? $this->tokens->find($id) : null;
        if (!$row instanceof OAuthToken || OAuthToken::KIND_CODE !== $row->getKind() || null === $row->getUserId()) {
            throw OAuthServerException::invalidGrant('The authorization code could not be linked.');
        }

        $userId = $row->getUserId();
        $clientId = $row->getClientId();
        $externalId = $row->getExternalId();
        if (null === $externalId || '' === $externalId) {
            $this->tokens->revokeAll($clientId, $userId);
            throw OAuthServerException::invalidGrant('The authorization code could not be linked.');
        }

        $instance = $this->instances->findByInstanceId($clientId);
        if (!$instance instanceof PlatformInstance || !$instance->isActive() || !$this->platformLinks->isEnabled($userId)) {
            $this->tokens->revokeForAccount($clientId, $userId, $externalId);
            throw OAuthServerException::invalidGrant('This cloud is not connected to Synaplan.');
        }

        try {
            $this->links->link($userId, $instance, $externalId, $this->context->ip());
        } catch (CloudLinkRefused) {
            $this->tokens->revokeForAccount($clientId, $userId, $externalId);
            throw OAuthServerException::invalidGrant('This cloud account cannot be linked.');
        }
    }
}
