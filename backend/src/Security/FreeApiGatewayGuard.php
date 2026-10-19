<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\ApiKey;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Fail-closed backstop for Synaplan Demo keys ({@see ApiKeyScope::FREEAPI}).
 *
 * The private `synafreeapi_server` plugin gates those keys on kernel.request
 * (priority 5) and marks an admitted request with `_freeapi_gated`. This
 * listener runs right after it. A key that names `freeapi` and reaches a `/v1`
 * route without that mark — plugin missing, broken, or the key's list not
 * exactly `['freeapi']` — is refused before any controller spends money.
 *
 * `/v1/free/*` is the plugin's own surface and is not checked here.
 */
final class FreeApiGatewayGuard implements EventSubscriberInterface
{
    public const GATED_ATTRIBUTE = '_freeapi_gated';

    private const PAUSED_MESSAGE = 'The Synaplan Demo is paused. Your chats and files are safe on this server. Try again later, or add your own AI provider.';

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 4],
        ];
    }

    /**
     * True only for a request the Demo gate admitted AND whose key is exactly
     * `['freeapi']`. The gateway controllers use this to skip the per-account
     * hourly cap, which would otherwise throttle every Demo install at once.
     */
    public static function isGated(Request $request): bool
    {
        if (true !== $request->attributes->get(self::GATED_ATTRIBUTE)) {
            return false;
        }

        $apiKey = $request->attributes->get('api_key');

        return $apiKey instanceof ApiKey && ApiKeyScope::isFreeApiKey($apiKey->getScopes());
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if ('/v1' !== $path && !str_starts_with($path, '/v1/')) {
            return;
        }
        if ('/v1/free' === $path || str_starts_with($path, '/v1/free/')) {
            return;
        }

        $apiKey = $request->attributes->get('api_key');
        if (!$apiKey instanceof ApiKey || !ApiKeyScope::carriesFreeApi($apiKey->getScopes())) {
            return;
        }

        if (self::isGated($request)) {
            return;
        }

        $this->logger->warning('Synaplan Demo key reached the gateway without the Demo gate', [
            'key_id' => $apiKey->getId(),
            'owner_id' => $apiKey->getOwnerId(),
            'path' => $path,
        ]);

        $event->setResponse(new JsonResponse([
            'error' => [
                'message' => self::PAUSED_MESSAGE,
                'type' => 'server_error',
                'param' => null,
                'code' => 'freeapi_paused',
            ],
        ], Response::HTTP_SERVICE_UNAVAILABLE));
    }
}
