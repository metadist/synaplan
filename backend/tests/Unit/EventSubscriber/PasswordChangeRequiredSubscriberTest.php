<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\Entity\User;
use App\EventSubscriber\PasswordChangeRequiredSubscriber;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[AllowMockObjectsWithoutExpectations]
final class PasswordChangeRequiredSubscriberTest extends TestCase
{
    public function testBlocksApiRoutesWhileTheInitialPasswordIsStillInUse(): void
    {
        $event = $this->dispatch($this->user(mustChange: true), '/api/v1/messages', 'api_messages_send');

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $this->assertStringContainsString('PASSWORD_CHANGE_REQUIRED', (string) $response->getContent());
    }

    public function testLetsTheUserThroughToTheChangeItself(): void
    {
        $event = $this->dispatch(
            $this->user(mustChange: true),
            '/api/v1/profile/password',
            'api_profile_change_password',
        );

        $this->assertNull($event->getResponse());
    }

    public function testLetsTheFrontendLoadTheSessionAndSignOut(): void
    {
        foreach (['api_auth_me' => '/api/v1/auth/me', 'api_auth_logout' => '/api/v1/auth/logout'] as $route => $path) {
            $event = $this->dispatch($this->user(mustChange: true), $path, $route);
            $this->assertNull($event->getResponse(), $route.' must stay reachable');
        }
    }

    public function testIgnoresUsersWhoChoseTheirOwnPassword(): void
    {
        $event = $this->dispatch($this->user(mustChange: false), '/api/v1/messages', 'api_messages_send');

        $this->assertNull($event->getResponse());
    }

    public function testIgnoresAnonymousRequests(): void
    {
        $event = $this->dispatch(null, '/api/v1/messages', 'api_messages_send');

        $this->assertNull($event->getResponse());
    }

    public function testIgnoresNonApiRequests(): void
    {
        $event = $this->dispatch($this->user(mustChange: true), '/login', 'app_login');

        $this->assertNull($event->getResponse());
    }

    private function dispatch(?UserInterface $user, string $path, string $route): RequestEvent
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $request = Request::create($path);
        $request->attributes->set('_route', $route);

        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        (new PasswordChangeRequiredSubscriber($security))($event);

        return $event;
    }

    private function user(bool $mustChange): User
    {
        return (new User())
            ->setMail('admin@example.com')
            ->setMustChangePassword($mustChange);
    }
}
