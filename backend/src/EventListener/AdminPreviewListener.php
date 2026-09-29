<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Attribute\AdminPreview as AdminPreviewAttribute;
use App\Service\Feature\AdminPreview;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Hides controllers marked `#[AdminPreview]` from everyone who is not an admin.
 *
 * Runs on `kernel.controller` after the firewall, so an anonymous probe of a
 * protected route still sees 401. Priority is below
 * {@see \App\Module\Http\ModuleGateListener}: when both would refuse, the
 * answer stays a plain 404, with no module id in the body.
 */
#[AsEventListener(event: KernelEvents::CONTROLLER, priority: -10)]
final readonly class AdminPreviewListener
{
    public const ERROR_CODE = 'not_found';

    public function __construct(
        private AdminPreview $preview,
        private TokenStorageInterface $tokens,
    ) {
    }

    public function __invoke(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        foreach ($event->getAttributes(AdminPreviewAttribute::class) as $attribute) {
            if ($this->preview->allows($attribute->feature, $this->currentUser())) {
                continue;
            }

            $event->setController(static fn (): JsonResponse => new JsonResponse(
                ['error' => self::ERROR_CODE],
                Response::HTTP_NOT_FOUND,
            ));

            return;
        }
    }

    private function currentUser(): ?UserInterface
    {
        $user = $this->tokens->getToken()?->getUser();

        return $user instanceof UserInterface ? $user : null;
    }
}
