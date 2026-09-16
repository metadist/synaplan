<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\StreamAttachController;
use App\Entity\GuestSession;
use App\Entity\User;
use App\Entity\Widget;
use App\Entity\WidgetSession;
use App\Service\Chat\Run\ChatRun;
use App\Service\Chat\Run\ChatRunService;
use App\Service\GuestSessionService;
use App\Service\WidgetService;
use App\Service\WidgetSessionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The re-attach endpoint is PUBLIC_ACCESS (it has to serve widget visitors and
 * guests, who have no app session), so the owner check inside the controller is
 * the ONLY thing standing between a caller and somebody else's conversation.
 * These tests pin that gate: which identity a request resolves to, and that
 * every failure mode refuses instead of falling through to a stream.
 *
 * The stream callback is deliberately never invoked — the guards all answer
 * before it, and running it would write SSE frames into the test output.
 */
final class StreamAttachControllerTest extends TestCase
{
    private ChatRunService&MockObject $chatRunService;
    private WidgetService&Stub $widgetService;
    private WidgetSessionService&Stub $widgetSessionService;
    private GuestSessionService&Stub $guestSessionService;

    protected function setUp(): void
    {
        $this->chatRunService = $this->createMock(ChatRunService::class);
        $this->widgetService = $this->createStub(WidgetService::class);
        $this->widgetSessionService = $this->createStub(WidgetSessionService::class);
        $this->guestSessionService = $this->createStub(GuestSessionService::class);
    }

    public function testAMissingRunIdIsRejectedBeforeAnyIdentityWork(): void
    {
        $this->chatRunService->expects(self::never())->method('authorize');

        $response = $this->controller()->attach(new Request(['runId' => '   ']), $this->userWithId(42));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testARequestWithoutAnyUsableIdentityIsUnauthorized(): void
    {
        $this->chatRunService->expects(self::never())->method('authorize');

        $response = $this->controller()->attach($this->attachRequest(), null);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testAnAppUserIsScopedToItsUserId(): void
    {
        $this->chatRunService->expects(self::once())
            ->method('authorize')
            ->with('run-1', 'user:42')
            ->willReturn($this->runningRun('user:42'));

        $this->assertSseStream($this->controller()->attach($this->attachRequest(), $this->userWithId(42)));
    }

    public function testAnUnknownOrForeignRunAnswersAPlain404(): void
    {
        // authorize() returns null both for "no such run" and "not yours", and
        // the endpoint must not tell the two apart — otherwise it becomes a
        // probe for which run ids exist.
        $this->chatRunService->expects(self::once())->method('authorize')->willReturn(null);

        $response = $this->controller()->attach($this->attachRequest(), $this->userWithId(42));

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame('{"error":"Run not found"}', (string) $response->getContent());
    }

    public function testAWidgetIsScopedToItsSessionNotToTheWidgetOwner(): void
    {
        // Widget turns all run under the operator's user, so scoping on the user
        // would let one visitor replay another visitor's conversation.
        $this->widgetService->method('getWidgetById')->willReturn(new Widget());
        $this->widgetSessionService->method('getSession')->willReturn($this->widgetSession('sess-1'));

        $this->chatRunService->expects(self::once())
            ->method('authorize')
            ->with('run-1', 'widget:sess-1')
            ->willReturn($this->runningRun('widget:sess-1'));

        $this->assertSseStream($this->controller()->attach($this->widgetRequest(), null));
    }

    public function testAnUnknownWidgetIsUnauthorized(): void
    {
        $this->widgetService->method('getWidgetById')->willReturn(null);
        $this->chatRunService->expects(self::never())->method('authorize');

        $response = $this->controller()->attach($this->widgetRequest(), null);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testAWidgetSessionIsOnlyLookedUpNeverCreated(): void
    {
        // A re-attach that could mint a session would turn a read endpoint into
        // a session factory for anyone who can guess a widget id.
        $sessionService = $this->createMock(WidgetSessionService::class);
        $sessionService->expects(self::once())->method('getSession')->with('w-1', 'sess-1')->willReturn(null);
        $sessionService->expects(self::never())->method('getOrCreateSession');

        $this->widgetService->method('getWidgetById')->willReturn(new Widget());
        $this->widgetSessionService = $sessionService;
        $this->chatRunService->expects(self::never())->method('authorize');

        $response = $this->controller()->attach($this->widgetRequest(), null);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testAGuestIsScopedToItsSessionId(): void
    {
        $this->guestSessionService->method('getSession')->willReturn($this->guestSession('guest-1', false));

        $this->chatRunService->expects(self::once())
            ->method('authorize')
            ->with('run-1', 'guest:guest-1')
            ->willReturn($this->runningRun('guest:guest-1'));

        $request = $this->attachRequest(['guestSession' => 'guest-1']);

        $this->assertSseStream($this->controller()->attach($request, null));
    }

    public function testAnExpiredGuestSessionIsUnauthorized(): void
    {
        $this->guestSessionService->method('getSession')->willReturn($this->guestSession('g', true));
        $this->chatRunService->expects(self::never())->method('authorize');

        $request = $this->attachRequest(['guestSession' => 'g']);

        self::assertSame(
            Response::HTTP_UNAUTHORIZED,
            $this->controller()->attach($request, null)->getStatusCode(),
        );
    }

    public function testAnUnknownGuestSessionIsUnauthorized(): void
    {
        $this->guestSessionService->method('getSession')->willReturn(null);
        $this->chatRunService->expects(self::never())->method('authorize');

        $request = $this->attachRequest(['guestSession' => 'nope']);

        self::assertSame(
            Response::HTTP_UNAUTHORIZED,
            $this->controller()->attach($request, null)->getStatusCode(),
        );
    }

    private function controller(): StreamAttachController
    {
        $controller = new StreamAttachController(
            $this->chatRunService,
            $this->widgetService,
            $this->widgetSessionService,
            $this->guestSessionService,
            new NullLogger(),
        );

        // AbstractController::json() reaches into the container to look for a
        // serializer; an empty one makes it fall back to JsonResponse.
        $controller->setContainer(new Container());

        return $controller;
    }

    /**
     * @param array<string, string> $extraQuery
     */
    private function attachRequest(array $extraQuery = []): Request
    {
        return new Request(['runId' => 'run-1'] + $extraQuery);
    }

    private function widgetRequest(): Request
    {
        return new Request(
            ['runId' => 'run-1'],
            server: ['HTTP_X_WIDGET_ID' => 'w-1', 'HTTP_X_WIDGET_SESSION' => 'sess-1'],
        );
    }

    private function assertSseStream(Response $response): void
    {
        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame('text/event-stream', $response->headers->get('Content-Type'));
        // Symfony expands the directive it was given; what matters is that the
        // replay is never cached by a proxy.
        self::assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        self::assertSame('no', $response->headers->get('X-Accel-Buffering'));
    }

    private function runningRun(string $ownerKey): ChatRun
    {
        return new ChatRun('run-1', $ownerKey, 7, 'track-1');
    }

    private function userWithId(int $id): User
    {
        $user = new User();

        $reflection = new \ReflectionProperty(User::class, 'id');
        $reflection->setValue($user, $id);

        return $user;
    }

    private function widgetSession(string $sessionId): WidgetSession
    {
        $session = $this->createStub(WidgetSession::class);
        $session->method('getSessionId')->willReturn($sessionId);

        return $session;
    }

    private function guestSession(string $sessionId, bool $expired): GuestSession
    {
        $session = $this->createStub(GuestSession::class);
        $session->method('getSessionId')->willReturn($sessionId);
        $session->method('isExpired')->willReturn($expired);

        return $session;
    }
}
