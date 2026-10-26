<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Security\PendingPaymentSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * A pending-payment account signs in to pay, and nothing else (AS1).
 */
final class PendingPaymentSubscriberTest extends TestCase
{
    private TokenStorage $tokens;

    protected function setUp(): void
    {
        $this->tokens = new TokenStorage();
    }

    #[DataProvider('openPaths')]
    public function testCheckoutAndTheSessionStayOpen(string $method, string $path): void
    {
        $event = $this->dispatch($method, $path, User::ACCOUNT_STATUS_PENDING_PAYMENT);

        self::assertFalse($event->hasResponse());
    }

    #[DataProvider('closedPaths')]
    public function testAnythingElseIsRefused(string $path): void
    {
        $event = $this->dispatch('POST', $path, User::ACCOUNT_STATUS_PENDING_PAYMENT);
        $response = $event->getResponse();

        self::assertNotNull($response);
        self::assertSame(403, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(PendingPaymentSubscriber::CODE, $body['code']);
        self::assertSame('This account is waiting for payment. Finish checkout to use Synaplan.', $body['message']);
    }

    public function testAnActiveAccountIsNotRestricted(): void
    {
        $event = $this->dispatch('POST', '/api/v1/chats', User::ACCOUNT_STATUS_ACTIVE);

        self::assertFalse($event->hasResponse());
    }

    public function testAnAnonymousRequestIsNotRestricted(): void
    {
        $event = $this->dispatch('POST', '/api/v1/chats', null);

        self::assertFalse($event->hasResponse());
    }

    public function testASubRequestIsLeftAlone(): void
    {
        $this->signIn(User::ACCOUNT_STATUS_PENDING_PAYMENT);
        $event = new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('/api/v1/chats', 'POST'),
            HttpKernelInterface::SUB_REQUEST,
        );
        $this->subscriber()->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function openPaths(): iterable
    {
        yield 'checkout' => ['POST', '/api/v1/subscription/checkout'];
        yield 'plans' => ['GET', '/api/v1/subscription/plans'];
        yield 'who am I' => ['GET', '/api/v1/auth/me'];
        yield 'logout' => ['POST', '/api/v1/auth/logout'];
        yield 'profile' => ['GET', '/api/v1/profile'];
        yield 'runtime config' => ['GET', '/api/v1/config/runtime'];
        yield 'stop impersonating' => ['POST', '/api/v1/admin/impersonate/exit'];
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function closedPaths(): iterable
    {
        yield 'chat' => ['/api/v1/chats'];
        yield 'messages' => ['/v1/messages'];
        yield 'a path that only shares a prefix' => ['/api/v1/authentication'];
        yield 'writing runtime config' => ['/api/v1/config/runtime'];
        yield 'starting impersonation' => ['/api/v1/admin/impersonate'];
    }

    private function dispatch(string $method, string $path, ?string $status): RequestEvent
    {
        if (null !== $status) {
            $this->signIn($status);
        }
        $event = new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create($path, $method),
            HttpKernelInterface::MAIN_REQUEST,
        );
        $this->subscriber()->onKernelRequest($event);

        return $event;
    }

    private function signIn(string $status): void
    {
        $user = (new User())
            ->setMail('pending@example.com')
            ->setAccountStatus($status);
        $this->tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function subscriber(): PendingPaymentSubscriber
    {
        return new PendingPaymentSubscriber($this->tokens, new NullLogger());
    }
}
