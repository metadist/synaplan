<?php

declare(strict_types=1);

namespace App\Service\OAuth\Server\Entity;

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use League\OAuth2\Server\CryptKeyInterface;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

/**
 * An access token as a JWT profile for OAuth 2.0 access tokens (RFC 9068):
 * header typ at+jwt and the key id from Synaplan's JWK Set; claims iss
 * (Synaplan), aud and client_id (the cloud), sub (the Synaplan user), jti,
 * iat/nbf/exp and scope.
 */
final class AccessToken implements AccessTokenEntityInterface
{
    use EntityTrait;
    use TokenEntityTrait;

    private CryptKeyInterface $privateKey;

    public function __construct(private readonly string $issuer, private readonly string $kid)
    {
    }

    public function setPrivateKey(CryptKeyInterface $privateKey): void
    {
        $this->privateKey = $privateKey;
    }

    public function toString(): string
    {
        $config = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::plainText($this->privateKey->getKeyContents(), $this->privateKey->getPassPhrase() ?? ''),
            InMemory::plainText('empty', 'empty'),
        );
        $now = new \DateTimeImmutable();
        $scopes = array_map(static fn ($scope): string => $scope->getIdentifier(), $this->getScopes());

        return $config->builder()
            ->withHeader('kid', $this->kid)
            ->withHeader('typ', 'at+jwt')
            ->issuedBy($this->issuer)
            ->permittedFor($this->getClient()->getIdentifier())
            ->identifiedBy($this->getIdentifier())
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($this->getExpiryDateTime())
            ->relatedTo((string) $this->getUserIdentifier())
            ->withClaim('client_id', $this->getClient()->getIdentifier())
            ->withClaim('scope', implode(' ', $scopes))
            ->getToken($config->signer(), $config->signingKey())
            ->toString();
    }
}
