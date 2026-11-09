<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\ExternalIdentity;
use App\Entity\PlatformInstance;
use App\Repository\ExternalIdentityRepository;
use App\Repository\PlatformInstanceRepository;
use App\Service\OAuth\Server\AccessTokenValidator;
use App\Service\PlatformLink\Exception\PlatformDisconnectedException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * A person reaching Synaplan through a cloud with an access token Synaplan
 * issued to that cloud (OAuth2, authorization code flow). The token names
 * the person (sub), the cloud (client_id) and what the person allowed
 * (scope); the scopes are enforced like a linked API key's by
 * {@see ApiKeyScopeSubscriber}. The person's link with that cloud must be
 * active: a cloud whose link the person ended or turned off gets nothing.
 */
final class OAuthBearerAuthenticator extends AbstractAuthenticator
{
    public const CLIENT_ATTRIBUTE = 'oauth_client_id';
    public const SCOPES_ATTRIBUTE = 'oauth_scopes';

    public function __construct(
        private readonly AccessTokenValidator $tokens,
        private readonly PlatformInstanceRepository $instances,
        private readonly ExternalIdentityRepository $identities,
    ) {
    }

    public function supports(Request $request): bool
    {
        $token = BearerToken::jwtFromRequest($request);

        return null !== $token && $this->tokens->isOurs($token);
    }

    public function authenticate(Request $request): Passport
    {
        $claims = $this->tokens->validate((string) BearerToken::jwtFromRequest($request));
        if (null === $claims || null === $claims['user_id']) {
            throw new CustomUserMessageAuthenticationException('Invalid or expired access token.');
        }
        $instance = $this->instances->findByInstanceId($claims['client_id']);
        if (!$instance instanceof PlatformInstance || !$instance->isActive()) {
            throw new CustomUserMessageAuthenticationException('This cloud is not connected to Synaplan.');
        }
        $linked = array_filter(
            $this->identities->findForUserOnInstance($claims['user_id'], $instance->getClient(), $instance->getInstanceId()),
            static fn (ExternalIdentity $identity): bool => ExternalIdentity::STATUS_REVOKED !== $identity->getStatus() && !$identity->isOff(),
        );
        if ([] === $linked) {
            throw new PlatformDisconnectedException();
        }
        foreach ($linked as $identity) {
            $identity->touchLastSeen();
            $this->identities->save($identity);
        }

        $request->attributes->set(self::CLIENT_ATTRIBUTE, $instance->getInstanceId());
        $request->attributes->set(self::SCOPES_ATTRIBUTE, $claims['scopes']);

        return new SelfValidatingPassport(new UserBadge((string) $claims['user_id']));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($exception instanceof PlatformDisconnectedException) {
            return new JsonResponse([
                'error' => PlatformDisconnectedException::CODE,
                'message' => PlatformDisconnectedException::MESSAGE,
                'code' => PlatformDisconnectedException::CODE,
            ], Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse([
            'error' => 'invalid_token',
            'message' => $exception->getMessageKey(),
            'code' => 'invalid_token',
        ], Response::HTTP_UNAUTHORIZED, ['WWW-Authenticate' => 'Bearer error="invalid_token"']);
    }
}
