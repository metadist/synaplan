<?php

declare(strict_types=1);

namespace App\Tests\Unit\Realtime;

use App\Entity\User;
use App\Entity\Widget;
use App\Entity\WidgetSession;
use App\Realtime\Authorizer\SubscriberContext;
use App\Realtime\Authorizer\WidgetSessionAccessGuard;
use App\Realtime\Authorizer\WidgetTypingAuthorizer;
use App\Realtime\Channel\UserChannel;
use App\Realtime\Channel\WidgetSessionChannel;
use App\Realtime\Channel\WidgetTypingChannel;
use App\Realtime\Exception\UnauthorizedSubscriptionException;
use App\Repository\WidgetRepository;
use App\Repository\WidgetSessionRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The typing authorizer is the trust boundary for browser-published
 * typing frames — Centrifugo enforces `allow_publish_for_subscriber: true`,
 * meaning passing this authorizer on subscribe is what grants publish
 * rights. Anything that loosens these checks is a privilege-escalation
 * vector (someone could push fake typing frames into another visitor's
 * conversation), so the regression coverage here mirrors the durable
 * session-channel checks 1:1.
 */
#[AllowMockObjectsWithoutExpectations]
final class WidgetTypingAuthorizerTest extends TestCase
{
    private WidgetRepository&MockObject $widgetRepo;
    private WidgetSessionRepository&MockObject $sessionRepo;
    private WidgetTypingAuthorizer $authorizer;

    protected function setUp(): void
    {
        $this->widgetRepo = $this->createMock(WidgetRepository::class);
        $this->sessionRepo = $this->createMock(WidgetSessionRepository::class);
        $this->authorizer = new WidgetTypingAuthorizer(
            new WidgetSessionAccessGuard($this->widgetRepo, $this->sessionRepo),
        );
    }

    public function testSupportsOnlyTypingChannel(): void
    {
        $this->assertTrue($this->authorizer->supports(new WidgetTypingChannel('w', 's')));
        $this->assertFalse($this->authorizer->supports(new WidgetSessionChannel('w', 's')));
        $this->assertFalse($this->authorizer->supports(new UserChannel(1)));
    }

    public function testRejectsWhenWidgetMissing(): void
    {
        $this->widgetRepo->method('findByWidgetId')->willReturn(null);

        $this->expectException(UnauthorizedSubscriptionException::class);
        $this->authorizer->authorize(
            new WidgetTypingChannel('w', 's'),
            new SubscriberContext(visitorId: 's')
        );
    }

    public function testRejectsWhenSessionMissing(): void
    {
        $this->widgetRepo->method('findByWidgetId')->willReturn($this->buildWidget(7));
        $this->sessionRepo->method('findByWidgetAndSession')->willReturn(null);

        $this->expectException(UnauthorizedSubscriptionException::class);
        $this->authorizer->authorize(
            new WidgetTypingChannel('w', 's'),
            new SubscriberContext(visitorId: 's')
        );
    }

    public function testAcceptsAnonymousVisitorWhenSessionIdMatches(): void
    {
        $this->widgetRepo->method('findByWidgetId')->willReturn($this->buildWidget(7));
        $this->sessionRepo->method('findByWidgetAndSession')->willReturn(new WidgetSession());

        $this->authorizer->authorize(
            new WidgetTypingChannel('w', 'sid_xyz'),
            new SubscriberContext(visitorId: 'sid_xyz', extra: ['widgetId' => 'w'])
        );
        $this->expectNotToPerformAssertions();
    }

    public function testRejectsVisitorWithMismatchedSessionId(): void
    {
        $this->widgetRepo->method('findByWidgetId')->willReturn($this->buildWidget(7));
        $this->sessionRepo->method('findByWidgetAndSession')->willReturn(new WidgetSession());

        $this->expectException(UnauthorizedSubscriptionException::class);
        $this->authorizer->authorize(
            new WidgetTypingChannel('w', 'sid_xyz'),
            new SubscriberContext(visitorId: 'sid_other', extra: ['widgetId' => 'w'])
        );
    }

    public function testRejectsVisitorWhoseClaimedWidgetIdDiffersFromChannel(): void
    {
        // Mirrors the session-channel check: publish rights on the typing
        // channel must never be grantable across widget boundaries.
        $this->widgetRepo->method('findByWidgetId')->willReturn($this->buildWidget(7));
        $this->sessionRepo->method('findByWidgetAndSession')->willReturn(new WidgetSession());

        $this->expectException(UnauthorizedSubscriptionException::class);
        $this->authorizer->authorize(
            new WidgetTypingChannel('w', 'sid_xyz'),
            new SubscriberContext(visitorId: 'sid_xyz', extra: ['widgetId' => 'other_widget'])
        );
    }

    public function testAcceptsAuthenticatedOwner(): void
    {
        $widget = $this->buildWidget(7);
        $this->widgetRepo->method('findByWidgetId')->willReturn($widget);
        $this->sessionRepo->method('findByWidgetAndSession')->willReturn(new WidgetSession());

        $this->authorizer->authorize(
            new WidgetTypingChannel('w', 's'),
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
            new WidgetTypingChannel('w', 's'),
            new SubscriberContext(user: $this->buildUser(99))
        );
    }

    public function testRejectsCallerWithoutAnyIdentity(): void
    {
        $widget = $this->buildWidget(7);
        $this->widgetRepo->method('findByWidgetId')->willReturn($widget);
        $this->sessionRepo->method('findByWidgetAndSession')->willReturn(new WidgetSession());

        // No user, no visitorId — fail-closed.
        $this->expectException(UnauthorizedSubscriptionException::class);
        $this->authorizer->authorize(
            new WidgetTypingChannel('w', 's'),
            new SubscriberContext()
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
        $reflection->setValue($user, $id);

        return $user;
    }
}
