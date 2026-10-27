<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\ApiKey;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\PlatformLink\PlatformLifecycleService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The generic admin delete, when an API key calls it, uses the same rule as
 * lifecycle(deleted). A session admin is left to AdminController, which is
 * already at its size limit. The check runs after routing so an encoded id
 * is the id the controller would have received.
 */
final readonly class ProvisionedAccountDeleteSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private TokenStorageInterface $tokens,
        private UserRepository $users,
        private PlatformLifecycleService $lifecycle,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onController', 0],
        ];
    }

    public function onController(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->attributes->get('api_key') instanceof ApiKey) {
            return;
        }
        if ('admin_delete_user' !== $request->attributes->get('_route')) {
            return;
        }

        $raw = $request->attributes->get('id');
        if (!is_int($raw) && !(is_string($raw) && ctype_digit($raw))) {
            $event->setController(static fn (): JsonResponse => new JsonResponse(['error' => 'User not found'], Response::HTTP_NOT_FOUND));

            return;
        }

        $id = (int) $raw;
        $admin = $this->tokens->getToken()?->getUser();
        if (!$admin instanceof User || !$admin->isAdmin() || $admin->getId() === $id) {
            return;
        }

        $target = $this->users->find($id);
        if (!$target instanceof User) {
            return;
        }

        $result = $this->lifecycle->deleteCalledByApiKey($target, $request->getClientIp() ?? '');
        $event->setController(static function () use ($result): JsonResponse {
            if ($result['deleted']) {
                return new JsonResponse(['success' => true, 'message' => 'User deleted']);
            }

            return new JsonResponse([
                'success' => false,
                'error' => 'account_in_use',
                'code' => 'account_in_use',
                'message' => $result['message'],
            ], Response::HTTP_CONFLICT);
        });
    }
}
