<?php

declare(strict_types=1);

namespace App\Tests\Unit\Realtime;

use App\Entity\User;
use App\Realtime\Authorizer\ChannelAuthorizerInterface;
use App\Realtime\Authorizer\ChannelAuthorizerLocator;
use App\Realtime\Authorizer\SubscriberContext;
use App\Realtime\Authorizer\SystemBroadcastAuthorizer;
use App\Realtime\Authorizer\UserChannelAuthorizer;
use App\Realtime\Channel\ChannelInterface;
use App\Realtime\Channel\SystemBroadcastChannel;
use App\Realtime\Channel\UserChannel;
use App\Realtime\Channel\WidgetSessionChannel;
use App\Realtime\Exception\UnauthorizedSubscriptionException;
use PHPUnit\Framework\TestCase;

final class ChannelAuthorizerLocatorTest extends TestCase
{
    public function testFailsClosedWhenNoAuthorizerSupportsChannel(): void
    {
        $locator = new ChannelAuthorizerLocator([]);

        $this->expectException(UnauthorizedSubscriptionException::class);
        $this->expectExceptionMessageMatches('/No authorizer registered/');

        $locator->authorize(new WidgetSessionChannel('w', 's'), new SubscriberContext(visitorId: 's'));
    }

    public function testDelegatesToFirstSupportingAuthorizer(): void
    {
        $matched = false;

        $matchAll = new class(callback: function () use (&$matched): void {
            $matched = true;
        }) implements ChannelAuthorizerInterface {
            public function __construct(private readonly \Closure $callback)
            {
            }

            public function supports(ChannelInterface $channel): bool
            {
                return true;
            }

            public function authorize(ChannelInterface $channel, SubscriberContext $subscriber): void
            {
                ($this->callback)();
            }
        };

        $locator = new ChannelAuthorizerLocator([$matchAll]);
        $locator->authorize(new SystemBroadcastChannel('topic'), new SubscriberContext());

        $this->assertTrue($matched);
    }

    public function testSystemBroadcastAcceptsAnyone(): void
    {
        $locator = new ChannelAuthorizerLocator([new SystemBroadcastAuthorizer()]);

        $locator->authorize(new SystemBroadcastChannel('news'), new SubscriberContext());
        $locator->authorize(new SystemBroadcastChannel('news'), new SubscriberContext(visitorId: 'sid'));

        $this->expectNotToPerformAssertions();
    }

    public function testUserChannelRequiresMatchingUser(): void
    {
        $locator = new ChannelAuthorizerLocator([new UserChannelAuthorizer()]);

        $user = new User();
        $reflection = new \ReflectionProperty(User::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($user, 7);

        $locator->authorize(new UserChannel(7), new SubscriberContext(user: $user));
        $this->expectNotToPerformAssertions();
    }

    public function testUserChannelRejectsAnonymous(): void
    {
        $locator = new ChannelAuthorizerLocator([new UserChannelAuthorizer()]);

        $this->expectException(UnauthorizedSubscriptionException::class);
        $locator->authorize(new UserChannel(7), new SubscriberContext(visitorId: 'sid'));
    }

    public function testUserChannelRejectsMismatchedUser(): void
    {
        $locator = new ChannelAuthorizerLocator([new UserChannelAuthorizer()]);

        $user = new User();
        $reflection = new \ReflectionProperty(User::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($user, 99);

        $this->expectException(UnauthorizedSubscriptionException::class);
        $locator->authorize(new UserChannel(7), new SubscriberContext(user: $user));
    }
}
