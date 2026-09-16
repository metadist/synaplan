<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\ApiKey;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Central enforcement of API-key scopes ({@see ApiKeyScope}).
 *
 * Runs on kernel.request AFTER the security firewall (priority 8) has
 * authenticated the request, so the {@see ApiKey} that
 * {@see ApiKeyAuthenticator} stashed on `api_key` is available. Session-cookie
 * and OIDC users never carry that attribute, so they are untouched (C6).
 *
 * A *restricted* key that reaches a path its scopes do not cover gets a stable
 * `403 insufficient_scope` — never a 401, because the key itself is valid.
 * Unrestricted (legacy / `*`) keys skip every check (C1 grandfather).
 */
final class ApiKeyScopeSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        // Priority 6: after the firewall (8) populates request attributes, but
        // before the controller resolver runs, so we can short-circuit with a
        // clean JSON response instead of throwing.
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 6],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $apiKey = $request->attributes->get('api_key');

        // No API key on the request → session / OIDC user, or anonymous. Not
        // our concern; the firewalls and voters handle those.
        if (!$apiKey instanceof ApiKey) {
            return;
        }

        $scopes = $apiKey->getScopes();

        // Grandfathered keys (empty / legacy-webhook-only / `*`) keep full
        // access — this listener is a no-op for them.
        if (!ApiKeyScope::isRestricted($scopes)) {
            return;
        }

        $path = $request->getPathInfo();

        // A key may always revoke itself (Synamail sign-out): a leaked key can
        // only destroy itself, never the owner's other keys.
        if (ApiKeyScope::isSelfRevoke($request->getMethod(), $path, (int) $apiKey->getId())) {
            return;
        }

        if (ApiKeyScope::allows($scopes, $path)) {
            return;
        }

        $required = ApiKeyScope::requiredScopesForPath($path);

        $this->logger->warning('API key blocked by insufficient scope', [
            'key_id' => $apiKey->getId(),
            'owner_id' => $apiKey->getOwnerId(),
            'path' => $path,
            'required' => $required,
            'granted' => $scopes,
        ]);

        $event->setResponse(new JsonResponse([
            'success' => false,
            'error' => 'insufficient_scope',
            'code' => 'insufficient_scope',
            'message' => 'This API key is not permitted to access this resource.',
            'required' => $required,
            'granted' => array_values($scopes),
        ], Response::HTTP_FORBIDDEN));
    }
}
