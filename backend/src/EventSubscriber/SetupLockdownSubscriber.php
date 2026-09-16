<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Setup\SetupStateService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Closes the whole API while this installation still needs its first-run setup.
 *
 * A virgin instance has nothing to serve anyway, and closing everything in one
 * move is what makes the setup window safe. It replaces a handful of per-feature
 * guards and shuts, in one go: registration, guest chat, the widget endpoints
 * and — the important one — the public webhooks.
 * {@see \App\Service\EmailChatService::findOrCreateUserFromEmail()} creates a
 * BUSERLEVEL='ANONYMOUS' row, so without this a stranger could POST to the email
 * webhook, create a BUSER row and block the wizard permanently.
 *
 * Priority 9 puts this AHEAD of the firewall (8) and of
 * {@see PasswordChangeRequiredSubscriber} (6), but behind the router (32) so the
 * `_route` attribute is already resolved. Running before the firewall is
 * deliberate: during setup every guarded path answers with the same honest 503
 * instead of a misleading 401 from access control.
 *
 * 503 and not 403, for two reasons: the state is temporary, and the frontend
 * treats a 503 as "not signed in" without tearing down a session or wiping
 * native tokens.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 9)]
final readonly class SetupLockdownSubscriber
{
    /**
     * Mirrors {@see PasswordChangeRequiredSubscriber}: the path prefix of every
     * firewall that can reach application data — the JSON API, the MCP endpoint
     * and the OpenAI-compatible gateway.
     */
    private const GUARDED_PATH_PREFIXES = ['/api', '/mcp', '/v1'];

    /**
     * The only routes reachable during setup: the wizard's own endpoints, the
     * public runtime config the SPA needs in order to boot and find out that
     * setup is required at all, the health probe so orchestrators do not mark
     * a fresh container as unhealthy, and the session endpoints the wizard
     * uses after POST /admin signs the new administrator in. Without those
     * last two the SPA cannot learn who it is, and the completion screen
     * would bounce to /login.
     */
    private const ALLOWED_ROUTES = [
        'api_setup_state',
        'api_setup_admin',
        'api_setup_complete',
        'api_config_runtime_config',
        'api_health',
        'api_auth_me',
        'api_auth_refresh',
    ];

    /**
     * Path prefixes that stay reachable regardless of route name: the API
     * documentation (Nelmio serves it under several route names) so an operator
     * can inspect the instance while setting it up.
     */
    private const ALLOWED_PATH_PREFIXES = ['/api/doc'];

    public function __construct(
        private SetupStateService $setupState,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // CORS preflight carries no credentials and no payload; answering it with
        // a 503 would turn every cross-origin call into an opaque browser error
        // instead of the readable 503 the actual request receives.
        if (Request::METHOD_OPTIONS === $request->getMethod()) {
            return;
        }

        $path = $request->getPathInfo();
        if (!$this->isGuardedPath($path) || $this->isAlwaysAllowed($path)) {
            return;
        }

        if (\in_array($request->attributes->get('_route'), self::ALLOWED_ROUTES, true)) {
            return;
        }

        if (!$this->setupState->isSetupRequired()) {
            return;
        }

        $event->setResponse(new JsonResponse([
            'error' => 'Setup required',
            'code' => 'SETUP_REQUIRED',
            'message' => 'This Synaplan instance has not been set up yet. Open /setup in a browser to create the first administrator.',
            'setupUrl' => '/setup',
        ], Response::HTTP_SERVICE_UNAVAILABLE));
    }

    private function isGuardedPath(string $path): bool
    {
        foreach (self::GUARDED_PATH_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isAlwaysAllowed(string $path): bool
    {
        foreach (self::ALLOWED_PATH_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
