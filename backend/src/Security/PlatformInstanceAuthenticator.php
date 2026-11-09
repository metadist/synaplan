<?php

declare(strict_types=1);

namespace App\Security;

use App\Service\Http\ClientAddress;
use App\Service\PlatformLink\Exception\PlatformInstanceNotActiveException;
use App\Service\PlatformLink\Exception\PlatformInstanceNotFoundException;
use App\Service\PlatformLink\Exception\PlatformInstanceSecretException;
use App\Service\PlatformLink\InstanceCredential;
use App\Service\PlatformLink\PlatformLinksConfig;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Authenticates a connected cloud by X-Instance-Id + X-Instance-Secret. It is
 * the only authenticator of the platform_instance firewall, so no controller
 * reads or checks the secret itself.
 *
 * The instance must be approved, except on routes with the route default
 * ALLOW_PENDING: the self-check a pending instance polls.
 */
final class PlatformInstanceAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public const ALLOW_PENDING = '_platform_instance_allow_pending';

    public function __construct(
        private readonly InstanceCredential $credential,
        private readonly PlatformLinksConfig $platformLinksConfig,
        private readonly RateLimiterFactoryInterface $platformInstanceAuthFailuresLimiter,
    ) {
    }

    public function supports(Request $request): bool
    {
        return '' !== trim((string) $request->headers->get('X-Instance-Id', ''));
    }

    public function authenticate(Request $request): Passport
    {
        $failures = $this->platformInstanceAuthFailuresLimiter->create(ClientAddress::resolve($request) ?? 'unknown');
        if (0 === $failures->consume(0)->getRemainingTokens()) {
            throw PlatformInstanceAuthenticationException::throttled();
        }

        try {
            $instance = $this->credential->require(
                trim((string) $request->headers->get('X-Instance-Id', '')),
                (string) $request->headers->get('X-Instance-Secret', ''),
                true === $request->attributes->get(self::ALLOW_PENDING),
            );
        } catch (PlatformInstanceNotFoundException) {
            $failures->consume();
            throw PlatformInstanceAuthenticationException::unknownInstance();
        } catch (PlatformInstanceSecretException) {
            $failures->consume();
            throw PlatformInstanceAuthenticationException::invalidSecret();
        } catch (PlatformInstanceNotActiveException) {
            throw PlatformInstanceAuthenticationException::pending();
        }

        $principal = new PlatformInstancePrincipal($instance);

        return new SelfValidatingPassport(
            new UserBadge($principal->getUserIdentifier(), static fn () => $principal),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if (!$this->platformLinksConfig->isEnabled(null)) {
            return $this->featureOff();
        }
        if (!$exception instanceof PlatformInstanceAuthenticationException) {
            $exception = PlatformInstanceAuthenticationException::invalidSecret();
        }

        return $this->refuse($exception);
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        if (!$this->platformLinksConfig->isEnabled(null)) {
            return $this->featureOff();
        }

        return $this->refuse(PlatformInstanceAuthenticationException::invalidSecret());
    }

    /**
     * With platform links switched off these routes do not exist, whatever
     * the caller sent; the apps read 404 as "not accepting connections".
     */
    private function featureOff(): JsonResponse
    {
        return new JsonResponse(['success' => false, 'error' => 'not_found'], Response::HTTP_NOT_FOUND);
    }

    private function refuse(PlatformInstanceAuthenticationException $exception): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'error' => $exception->error,
            'message' => $exception->getMessage(),
        ], $exception->status);
    }
}
