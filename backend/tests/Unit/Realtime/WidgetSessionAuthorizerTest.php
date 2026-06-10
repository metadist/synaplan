<?php

declare(strict_types=1);

namespace App\Tests\Unit\Realtime;

use App\Entity\User;
use App\Entity\Widget;
use App\Entity\WidgetSession;
use App\Realtime\Authorizer\SubscriberContext;
use App\Realtime\Authorizer\WidgetSessionAccessGuard;
use App\Realtime\Authorizer\WidgetSessionAuthorizer;
use App\Realtime\Channel\UserChannel;
use App\Realtime\Channel\WidgetSessionChannel;
use App\Realtime\Exception\UnauthorizedSubscriptionException;
use App\Repository\WidgetRepository;
use App\Repository\WidgetSessionRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class WidgetSessionAuthorizerTest extends TestCase
{
    private WidgetRepository&MockObject $widgetRepo;
    private WidgetSessionRepository&MockObject $sessionRepo;
    private WidgetSessionAuthorizer $authorizer;

    protected function setUp(): void
    {
        $this->widgetRepo = $this->createMock(WidgetRepository::class);
        $this->sessionRepo = $this->createMock(WidgetSessionRepository::class);
        $this->authorizer = new WidgetSessionAuthorizer(
            new WidgetSessionAccessGuard($this->widgetRepo, $this->sessionRepo),
        );
    }

    public function testSupportsOnlyWidgetSessionChannel(): void
    {
        $this->assertTrue($this->authorizer->supports(new WidgetSessionChannel('w', 's')));
        $this->assertFalse($this->authorizer->supports(new UserChannel(1)));
    }

    public function testRejectsWhenWidgetMissing(): void
    {
        $this->widgetRepo->method('findByWidgetId')->willReturn(null);

        $this->expectException(UnauthorizedSubscriptionException::class);
        $this->authorizer->authorize(
            new WidgetSessionChannel('w', 's'),
            new SubscriberContext(visitorId: 's')
        );
    }

    public function testRejectsWhenSessionMissing(): void
    {
        $this->widgetRepo->method('findByWidgetId')->willReturn($this->buildWidget(7));
        $this->sessionRepo->method('findByWidgetAndSession')->willReturn(null);

        $this->expectException(UnauthorizedSubscriptionException::class);
        $this->authorizer->authorize(
            new WidgetSessionChannel('w', 's'),
            new SubscriberContext(visitorId: 's')
        );
    }

    public function testAcceptsAnonymousVisitorWhenSessionIdMatches(): void
    {
        $this->widgetRepo->method('findByWidgetId')->willReturn($this->buildWidget(7));
        $this->sessionRepo->method('findByWidgetAndSession')->willReturn(new WidgetSession());

        $this->authorizer->authorize(
            new WidgetSessionChannel('w', 'sid_xyz'),
            new SubscriberContext(visitorId: 'sid_xyz')
        );
        $this->expectNotToPerformAssertions();
    }

    public function testRejectsVisitorWithMismatchedSessionId(): void
    {
        $this->widgetRepo->method('findByWidgetId')->willReturn($this->buildWidget(7));
        $this->sessionRepo->method('findByWidgetAndSession')->willReturn(new WidgetSession());

        $this->expectException(UnauthorizedSubscriptionException::class);
        $this->authorizer->authorize(
            new WidgetSessionChannel('w', 'sid_xyz'),
            new SubscriberContext(visitorId: 'sid_other')
        );
    }

    public function testAcceptsAuthenticatedOwner(): void
    {
        $widget = $this->buildWidget(7);
        $this->widgetRepo->method('findByWidgetId')->willReturn($widget);
        $this->sessionRepo->method('findByWidgetAndSession')->willReturn(new WidgetSession());

        $this->authorizer->authorize(
            new WidgetSessionChannel('w', 's'),
            new SubscriberContext(user: $this->buildUser(7))
        );
        $this->expectNotToPerformAssertions();
    }

    public function testRejectsAuthenticatedNonOwner(): void
    {
        $widget = $this->buildWidget(7);
        $this->widgetRepo->method('findByWidgetId')->willReturn($widget);
        $this->sessionRepo->method('findByWidgetAndSession')->willReturn(new WidgetSession());

        $this->expectException(UnauthorizedSubscriptionException::class);
        $this->authorizer->authorize(
            new WidgetSessionChannel('w', 's'),
            new SubscriberContext(user: $this->buildUser(99))
        );
    }

    private function buildWidget(int $ownerId): Widget
    {
        $widget = new Widget();
        $widget->setOwnerId($ownerId);

        return $widget;
    }

    private function buildUser(int $id): User
    {
        $user = new User();
        $reflection = new \ReflectionProperty(User::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($user, $id);

        return $user;
    }
}
