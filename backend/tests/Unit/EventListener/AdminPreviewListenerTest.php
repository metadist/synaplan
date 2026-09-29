<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\Attribute\AdminPreview as AdminPreviewAttribute;
use App\Entity\User;
use App\EventListener\AdminPreviewListener;
use App\Repository\UserRepository;
use App\Service\Feature\AdminPreview;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class AdminPreviewListenerTest extends TestCase
{
    public function testARegularUserGetsAPlain404(): void
    {
        $event = $this->dispatch($this->user('NEW'), new PreviewController());

        $response = $this->responseOf($event);
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        $this->assertSame(
            ['error' => 'not_found'],
            json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR),
        );
    }

    public function testAnAnonymousCallerGetsAPlain404(): void
    {
        $event = $this->dispatch(null, new PreviewController());

        $this->assertSame(Response::HTTP_NOT_FOUND, $this->responseOf($event)?->getStatusCode());
    }

    public function testAnAdminReachesTheController(): void
    {
        $event = $this->dispatch($this->user('ADMIN'), new PreviewController());

        $this->assertNull($this->responseOf($event));
    }

    public function testAMethodAttributeIsEnough(): void
    {
        $controller = new MethodPreviewController();
        $event = $this->dispatch($this->user('NEW'), [$controller, 'show']);

        $this->assertSame(Response::HTTP_NOT_FOUND, $this->responseOf($event)?->getStatusCode());
    }

    public function testAControllerWithoutTheAttributeIsUntouched(): void
    {
        $event = $this->dispatch($this->user('NEW'), new OpenController());

        $this->assertNull($this->responseOf($event));
    }

    public function testASubRequestIsIgnored(): void
    {
        $event = $this->event(new PreviewController(), HttpKernelInterface::SUB_REQUEST);
        $this->listener($this->user('NEW'))($event);

        $this->assertNull($this->responseOf($event));
    }

    public function testAnUnknownFeatureFailsLoudly(): void
    {
        $this->expectException(\LogicException::class);

        $this->dispatch($this->user('ADMIN'), new UnknownPreviewController());
    }

    private function dispatch(?User $user, callable $controller): ControllerEvent
    {
        $event = $this->event($controller, HttpKernelInterface::MAIN_REQUEST);
        $this->listener($user)($event);

        return $event;
    }

    private function listener(?User $user): AdminPreviewListener
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $tokens = $this->createStub(TokenStorageInterface::class);
        $tokens->method('getToken')->willReturn(null === $user ? null : $token);

        return new AdminPreviewListener(new AdminPreview($this->createStub(UserRepository::class)), $tokens);
    }

    private function event(callable $controller, int $requestType): ControllerEvent
    {
        $request = Request::create('/api/v1/channels/telegram');

        return new ControllerEvent(
            $this->createStub(HttpKernelInterface::class),
            $controller,
            $request,
            $requestType,
        );
    }

    private function responseOf(ControllerEvent $event): ?Response
    {
        $result = ($event->getController())();
        \assert($result instanceof Response);

        return 'controller' === $result->getContent() ? null : $result;
    }

    private function user(string $level): User
    {
        $user = new User();
        $user->setUserLevel($level);

        return $user;
    }
}

#[AdminPreviewAttribute(AdminPreview::TELEGRAM)]
final class PreviewController
{
    public function __invoke(): Response
    {
        return new Response('controller');
    }
}

final class MethodPreviewController
{
    #[AdminPreviewAttribute(AdminPreview::TELEGRAM)]
    public function show(): Response
    {
        return new Response('controller');
    }
}

final class OpenController
{
    public function __invoke(): Response
    {
        return new Response('controller');
    }
}

#[AdminPreviewAttribute('nope')]
final class UnknownPreviewController
{
    public function __invoke(): Response
    {
        return new Response('controller');
    }
}
