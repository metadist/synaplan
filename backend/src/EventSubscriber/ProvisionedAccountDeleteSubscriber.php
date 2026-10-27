<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\ApiKey;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\PlatformLink\PlatformLifecycleService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The generic admin delete, when an API key calls it, uses the same rule as
 * lifecycle(deleted). A session admin is left to AdminController, which is
 * already at its size limit.
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
            KernelEvents::REQUEST => ['onKernelRequest', 4],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->attributes->get('api_key') instanceof ApiKey || Request::METHOD_DELETE !== $request->getMethod()) {
            return;
        }

        $id = $this->userId($request->getPathInfo());
        if (null === $id) {
            return;
        }

        $admin = $this->tokens->getToken()?->getUser();
        if (!$admin instanceof User || !$admin->isAdmin() || $admin->getId() === $id) {
            return;
        }

        $target = $this->users->find($id);
        if (!$target instanceof User) {
            return;
        }

        $result = $this->lifecycle->deleteCalledByApiKey($target, $request->getClientIp() ?? '');
        if ($result['deleted']) {
            $event->setResponse(new JsonResponse(['success' => true, 'message' => 'User deleted']));

            return;
        }

        $event->setResponse(new JsonResponse([
            'success' => false,
            'error' => 'account_in_use',
            'code' => 'account_in_use',
            'message' => $result['message'],
        ], Response::HTTP_CONFLICT));
    }

    private function userId(string $path): ?int
    {
        if (1 !== preg_match('#^/api/v1/admin/users/(\d+)$#', $path, $match)) {
            return null;
        }

        return (int) $match[1];
    }
}
