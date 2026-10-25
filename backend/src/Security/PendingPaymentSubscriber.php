<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * A pending-payment account may sign in, and then only to pay.
 *
 * {@see AccountStatusChecker} lets the account through authentication.
 * This listener runs after the firewall and refuses every other request
 * with 403 ACCOUNT_PENDING_PAYMENT. Checkout, the profile and the session
 * stay open. An API key never reaches here: {@see ApiKeyAuthenticator}
 * requires an active owner.
 */
final class PendingPaymentSubscriber implements EventSubscriberInterface
{
    public const CODE = 'ACCOUNT_PENDING_PAYMENT';

    /** @var list<string> */
    private const PREFIXES = [
        '/api/v1/auth',
        '/api/v1/subscription',
        '/api/v1/profile',
    ];

    public function __construct(
        private readonly TokenStorageInterface $tokens,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        // After the firewall (8) has authenticated the request, before the
        // controller. Ahead of the API-key scope check (6).
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 7],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $event->hasResponse()) {
            return;
        }

        $user = $this->tokens->getToken()?->getUser();
        if (!$user instanceof User || !$user->isPendingPayment()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if ($this->allowed($path, $request->getMethod())) {
            return;
        }

        $this->logger->warning('Pending-payment account blocked outside checkout', [
            'user_id' => $user->getId(),
            'path' => $path,
        ]);

        $event->setResponse(new JsonResponse([
            'success' => false,
            'error' => 'Payment required',
            'code' => self::CODE,
            'message' => 'This account is waiting for payment. Finish checkout to use Synaplan.',
        ], Response::HTTP_FORBIDDEN));
    }

    private function allowed(string $path, string $method): bool
    {
        // Public, but a signed-in request still carries the account. The
        // app reads this before it can open the checkout page.
        if ('GET' === $method && '/api/v1/config/runtime' === $path) {
            return true;
        }

        // While impersonating, the signed-in account is the pending user.
        // Exit restores the administrator from the stashed session.
        if ('POST' === $method && '/api/v1/admin/impersonate/exit' === $path) {
            return true;
        }

        foreach (self::PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }
}
