<?php

declare(strict_types=1);

namespace App\Module\Http;

use App\Module\Gate\ModuleGateConfig;
use App\Module\ModuleRegistry;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Answers a uniform `404 feature_not_configured` on the routes of an absent
 * feature module — but only while that module's `MODULES.GATE_<ID>` flag is on.
 *
 * Runs on `kernel.controller`, i.e. after the firewall, so authentication and
 * authorization errors keep their own status codes and an anonymous probe of a
 * protected route still sees 401, never a hint about the installation's modules.
 * Which routes belong to a module is declared by the module itself
 * ({@see \App\Module\Contract\FeatureModuleInterface::routeNames()}); health,
 * runtime config, API docs, credential/connect routes, store notification
 * webhooks (Apple/Google/Stripe) and the Meta verify handshake are never
 * listed there and therefore never gated. The WhatsApp POST webhook
 * (`api_webhooks_whatsapp`) is listed and is gated when WhatsApp is absent.
 * A configured module is never gated regardless of the flag.
 */
#[AsEventListener(event: KernelEvents::CONTROLLER)]
final readonly class ModuleGateListener
{
    public const ERROR_CODE = 'feature_not_configured';

    public function __construct(
        private ModuleRegistry $modules,
        private ModuleGateConfig $gate,
    ) {
    }

    public function __invoke(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $routeName = $event->getRequest()->attributes->get('_route');
        if (!\is_string($routeName) || '' === $routeName) {
            return;
        }

        $module = $this->modules->forRoute($routeName);
        if (null === $module || !$this->gate->isGated($module->id()) || $module->isConfigured()) {
            return;
        }

        $body = [
            'error' => self::ERROR_CODE,
            'module' => $module->id(),
            'docs' => $module->docsAnchor(),
        ];

        $event->setController(static fn (): JsonResponse => new JsonResponse($body, Response::HTTP_NOT_FOUND));
    }
}
