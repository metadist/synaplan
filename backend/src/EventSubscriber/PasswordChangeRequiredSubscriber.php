<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Locks an account out of everything except changing its own password while
 * User::mustChangePassword() is set.
 *
 * The flag is only ever set for a password the deployment generated rather than
 * a human chose (see BootstrapAdminService). Such a credential is one-time use:
 * it travels through a parameter store or a log line on the way to the admin,
 * so it must not survive the first sign-in. Enforcing that server-side — not
 * just with a frontend redirect — is what makes it an actual guarantee, and is
 * what the AWS Marketplace AMI policy requires.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 6)]
final readonly class PasswordChangeRequiredSubscriber
{
    /**
     * The path prefix of every firewall in security.yaml that can authenticate a
     * user, so the lock covers all of them: the JSON API the frontend talks to,
     * the MCP endpoint and the OpenAI-compatible gateway. The latter two accept
     * an API key, and leaving them out would let an account whose initial
     * password is still in use drive the product through a different door.
     *
     * PasswordChangeRequiredSubscriberTest reads the firewalls out of
     * security.yaml and fails if one of them is missing here.
     */
    private const GUARDED_PATH_PREFIXES = ['/api', '/mcp', '/v1'];

    /**
     * Routes that stay reachable while the change is pending: the change
     * itself, the session endpoints the frontend needs to render the forced
     * dialog, and the way out.
     */
    private const ALLOWED_ROUTES = [
        'api_profile_change_password',
        'api_auth_me',
        'api_auth_logout',
        'api_auth_refresh',
        'api_config_runtime_config',
    ];

    public function __construct(
        private Security $security,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$this->isGuardedPath($request->getPathInfo())) {
            return;
        }

        if (\in_array($request->attributes->get('_route'), self::ALLOWED_ROUTES, true)) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User || !$user->mustChangePassword()) {
            return;
        }

        $event->setResponse(new JsonResponse([
            'error' => 'Password change required',
            'code' => 'PASSWORD_CHANGE_REQUIRED',
            'message' => 'This account still uses its initial password. Set a new password to continue.',
        ], Response::HTTP_FORBIDDEN));
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
}
