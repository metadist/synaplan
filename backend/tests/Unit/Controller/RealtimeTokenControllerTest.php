<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\RealtimeTokenController;
use App\Entity\User;
use App\Entity\Widget;
use App\Entity\WidgetSession;
use App\Realtime\Authorizer\ChannelAuthorizerLocator;
use App\Realtime\Channel\ChannelParser;
use App\Realtime\Token\RealtimeTokenService;
use App\Realtime\Token\WidgetVisitorAccessChecker;
use App\Repository\WidgetRepository;
use App\Repository\WidgetSessionRepository;
use App\Service\Widget\WidgetOriginValidator;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * The controller is the trust boundary for browser-issued WS tokens, so
 * we keep these tests focused on the security-critical invariants:
 *
 *   * Subscription token `sub` claim matches the connection token `sub`
 *     (Centrifugo rejects the subscribe otherwise — a regression here is
 *     silent in dev because nothing throws, the WS just stops working).
 *   * Anonymous visitors cannot smuggle authenticated identity by
 *     supplying a stray widgetId/sessionId.
 *   * Channel parser failures and authorizer rejections surface as
 *     well-defined, GENERIC HTTP errors instead of leaking internals.
 *   * The widget token endpoint cannot be used as an enumeration oracle:
 *     unknown widget, unknown session and expired session all yield the
 *     same 404 body; requests from non-allowlisted origins are refused;
 *     per-IP rate limiting kicks in.
 */
#[AllowMockObjectsWithoutExpectations]
final class RealtimeTokenControllerTest extends TestCase
{
    private const SECRET = 'a-very-secret-key-of-sufficient-length-for-hs256';
    private const ALLOWED_HOST = 'shop.example.test';

    public function testVisitorSubscriptionTokenSubMatchesConnectionTokenSub(): void
    {
        $widgetId = 'wdg_abc';
        $sessionId = 'sid_xyz';

        $widgetRepo = $this->createMock(WidgetRepository::class);
        $widgetRepo->method('findByWidgetId')->willReturn($this->buildWidget());

        $sessionRepo = $this->createMock(WidgetSessionRepository::class);
        $sessionRepo->method('findByWidgetAndSession')->willReturn($this->buildSession());

        $tokenService = $this->buildTokenService();
        $controller = $this->buildController(
            tokenService: $tokenService,
            widgetRepo: $widgetRepo,
            sessionRepo: $sessionRepo,
        );

        // 1. Visitor obtains a connection token.
        $connectionResponse = $controller->issueWidgetToken($this->widgetTokenRequest(), $widgetId, $sessionId);
        $connectionPayload = json_decode((string) $connectionResponse->getContent(), true);
        $this->assertIsArray($connectionPayload);
        $connectionToken = (string) $connectionPayload['token'];
        $connectionDecoded = (array) JWT::decode($connectionToken, new Key(self::SECRET, 'HS256'));

        // 2. Visitor obtains a subscription token for their session channel.
        $subscribeRequest = new Request(content: (string) json_encode([
            'channel' => sprintf('widget:session.%s.%s', $widgetId, $sessionId),
            'widgetId' => $widgetId,
            'sessionId' => $sessionId,
        ]));
        $subscribeResponse = $controller->issueSubscriptionToken($subscribeRequest, null);
        $subscribePayload = json_decode((string) $subscribeResponse->getContent(), true);
        $this->assertIsArray($subscribePayload);
        $subscribeToken = (string) $subscribePayload['token'];
        $subscribeDecoded = (array) JWT::decode($subscribeToken, new Key(self::SECRET, 'HS256'));

        $this->assertSame(
            $connectionDecoded['sub'],
            $subscribeDecoded['sub'],
            'Centrifugo refuses subscribe when subscription `sub` differs from connection `sub`. '
            .'This invariant MUST hold for visitors as well as operators.'
        );
        $this->assertSame(sprintf('widget:%s:%s', $widgetId, $sessionId), $subscribeDecoded['sub']);
    }

    public function testOperatorSubscriptionTokenSubMatchesConnectionTokenSub(): void
    {
        $owner = $this->buildUser(7);

        $widgetRepo = $this->createMock(WidgetRepository::class);
        $widgetRepo->method('findByWidgetId')->willReturn($this->buildWidget());

        $sessionRepo = $this->createMock(WidgetSessionRepository::class);
        $sessionRepo->method('findByWidgetAndSession')->willReturn($this->buildSession());

        $tokenService = $this->buildTokenService();
        $controller = $this->buildController(
            tokenService: $tokenService,
            widgetRepo: $widgetRepo,
            sessionRepo: $sessionRepo,
        );

        $connectionResponse = $controller->issueOperatorToken($owner);
        $connectionPayload = json_decode((string) $connectionResponse->getContent(), true);
        $this->assertIsArray($connectionPayload);
        $connectionDecoded = (array) JWT::decode((string) $connectionPayload['token'], new Key(self::SECRET, 'HS256'));

        $subscribeRequest = new Request(content: (string) json_encode([
            'channel' => 'widget:session.wdg_abc.sid_xyz',
        ]));
        $subscribeResponse = $controller->issueSubscriptionToken($subscribeRequest, $owner);
        $subscribePayload = json_decode((string) $subscribeResponse->getContent(), true);
        $this->assertIsArray($subscribePayload);
        $subscribeDecoded = (array) JWT::decode((string) $subscribePayload['token'], new Key(self::SECRET, 'HS256'));

        $this->assertSame($connectionDecoded['sub'], $subscribeDecoded['sub']);
        $this->assertSame('user:7', $subscribeDecoded['sub']);
    }

    public function testRejectsInvalidChannelNameWithGenericError(): void
    {
        $controller = $this->buildController(
            tokenService: $this->buildTokenService(),
            widgetRepo: $this->createMock(WidgetRepository::class),
            sessionRepo: $this->createMock(WidgetSessionRepository::class),
        );

        $request = new Request(content: (string) json_encode(['channel' => 'totally:bogus.channel']));
        $response = $controller->issueSubscriptionToken($request, null);

        $this->assertSame(400, $response->getStatusCode());

        // Parser messages enumerate valid namespaces — they must never reach
        // an anonymous caller.
        $payload = json_decode((string) $response->getContent(), true);
        $this->assertIsArray($payload);
        $this->assertSame('invalid_channel', $payload['error']);
    }

    public function testRejectsMissingChannelClaim(): void
    {
        $controller = $this->buildController(
            tokenService: $this->buildTokenService(),
            widgetRepo: $this->createMock(WidgetRepository::class),
            sessionRepo: $this->createMock(WidgetSessionRepository::class),
        );

        $request = new Request(content: (string) json_encode(['not_a_channel' => 1]));
        $response = $controller->issueSubscriptionToken($request, null);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testVisitorTypingSubscriptionTokenSubMatchesConnectionTokenSub(): void
    {
        // Centrifugo enforces the same `sub`-match rule for the dedicated
        // `widgettyping:*` namespace. If we ever drift from this we'd
        // silently break operator/visitor live typing in production.
        $widgetId = 'wdg_abc';
        $sessionId = 'sid_xyz';

        $widgetRepo = $this->createMock(WidgetRepository::class);
        $widgetRepo->method('findByWidgetId')->willReturn($this->buildWidget());
        $sessionRepo = $this->createMock(WidgetSessionRepository::class);
        $sessionRepo->method('findByWidgetAndSession')->willReturn($this->buildSession());

        $tokenService = $this->buildTokenService();
        $controller = $this->buildController(
            tokenService: $tokenService,
            widgetRepo: $widgetRepo,
            sessionRepo: $sessionRepo,
        );

        $connectionResponse = $controller->issueWidgetToken($this->widgetTokenRequest(), $widgetId, $sessionId);
        $connectionPayload = json_decode((string) $connectionResponse->getContent(), true);
        $this->assertIsArray($connectionPayload);
        $connectionDecoded = (array) JWT::decode((string) $connectionPayload['token'], new Key(self::SECRET, 'HS256'));

        $subscribeRequest = new Request(content: (string) json_encode([
            'channel' => sprintf('widgettyping:%s.%s', $widgetId, $sessionId),
            'widgetId' => $widgetId,
            'sessionId' => $sessionId,
        ]));
        $subscribeResponse = $controller->issueSubscriptionToken($subscribeRequest, null);
        $this->assertSame(200, $subscribeResponse->getStatusCode(), 'visitor typing subscribe should be accepted');

        $subscribePayload = json_decode((string) $subscribeResponse->getContent(), true);
        $this->assertIsArray($subscribePayload);
        $subscribeDecoded = (array) JWT::decode((string) $subscribePayload['token'], new Key(self::SECRET, 'HS256'));

        $this->assertSame($connectionDecoded['sub'], $subscribeDecoded['sub']);
        $this->assertSame(sprintf('widget:%s:%s', $widgetId, $sessionId), $subscribeDecoded['sub']);
        $this->assertSame(sprintf('widgettyping:%s.%s', $widgetId, $sessionId), $subscribeDecoded['channel']);
    }

    public function testAnonymousVisitorCannotSubscribeWithoutWidgetIdInPayload(): void
    {
        // Belt-and-suspenders: even if the authorizer somehow accepted the
        // subscriber, the controller refuses to mint a subscription token
        // whose `sub` claim cannot match a real connection token.
        $widgetRepo = $this->createMock(WidgetRepository::class);
        $widgetRepo->method('findByWidgetId')->willReturn($this->buildWidget());
        $sessionRepo = $this->createMock(WidgetSessionRepository::class);
        $sessionRepo->method('findByWidgetAndSession')->willReturn($this->buildSession());

        $controller = $this->buildController(
            tokenService: $this->buildTokenService(),
            widgetRepo: $widgetRepo,
            sessionRepo: $sessionRepo,
        );

        // Visitor omits widgetId / sessionId — the authorizer should fail
        // because visitorId is null (no proof of session ownership).
        $request = new Request(content: (string) json_encode([
            'channel' => 'widget:session.wdg_abc.sid_xyz',
        ]));
        $response = $controller->issueSubscriptionToken($request, null);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testWidgetTokenLookupFailuresAreIndistinguishable(): void
    {
        // Unknown widget.
        $widgetRepo = $this->createMock(WidgetRepository::class);
        $widgetRepo->method('findByWidgetId')->willReturn(null);
        $sessionRepo = $this->createMock(WidgetSessionRepository::class);

        $controller = $this->buildController(
            tokenService: $this->buildTokenService(),
            widgetRepo: $widgetRepo,
            sessionRepo: $sessionRepo,
        );
        $unknownWidget = $controller->issueWidgetToken($this->widgetTokenRequest(), 'wdg_nope', 'sid_xyz');

        // Known widget, unknown session.
        $widgetRepo = $this->createMock(WidgetRepository::class);
        $widgetRepo->method('findByWidgetId')->willReturn($this->buildWidget());
        $sessionRepo = $this->createMock(WidgetSessionRepository::class);
        $sessionRepo->method('findByWidgetAndSession')->willReturn(null);

        $controller = $this->buildController(
            tokenService: $this->buildTokenService(),
            widgetRepo: $widgetRepo,
            sessionRepo: $sessionRepo,
        );
        $unknownSession = $controller->issueWidgetToken($this->widgetTokenRequest(), 'wdg_abc', 'sid_nope');

        // Known widget, expired session.
        $widgetRepo = $this->createMock(WidgetRepository::class);
        $widgetRepo->method('findByWidgetId')->willReturn($this->buildWidget());
        $sessionRepo = $this->createMock(WidgetSessionRepository::class);
        $sessionRepo->method('findByWidgetAndSession')->willReturn($this->buildSession(expiresIn: -60));

        $controller = $this->buildController(
            tokenService: $this->buildTokenService(),
            widgetRepo: $widgetRepo,
            sessionRepo: $sessionRepo,
        );
        $expiredSession = $controller->issueWidgetToken($this->widgetTokenRequest(), 'wdg_abc', 'sid_xyz');

        foreach ([$unknownWidget, $unknownSession, $expiredSession] as $response) {
            $this->assertSame(404, $response->getStatusCode());
        }

        // Identical bodies — a probe must not be able to learn WHICH part of
        // the (widgetId, sessionId) pair was wrong.
        $this->assertSame((string) $unknownWidget->getContent(), (string) $unknownSession->getContent());
        $this->assertSame((string) $unknownWidget->getContent(), (string) $expiredSession->getContent());
    }

    public function testWidgetTokenRefusedForDisallowedOrigin(): void
    {
        $widgetRepo = $this->createMock(WidgetRepository::class);
        $widgetRepo->method('findByWidgetId')->willReturn($this->buildWidget());
        $sessionRepo = $this->createMock(WidgetSessionRepository::class);
        $sessionRepo->method('findByWidgetAndSession')->willReturn($this->buildSession());

        $controller = $this->buildController(
            tokenService: $this->buildTokenService(),
            widgetRepo: $widgetRepo,
            sessionRepo: $sessionRepo,
        );

        $evilOrigin = new Request(server: ['HTTP_ORIGIN' => 'https://evil.test']);
        $this->assertSame(403, $controller->issueWidgetToken($evilOrigin, 'wdg_abc', 'sid_xyz')->getStatusCode());

        $noOrigin = new Request();
        $this->assertSame(403, $controller->issueWidgetToken($noOrigin, 'wdg_abc', 'sid_xyz')->getStatusCode());
    }

    public function testWidgetTokenRefusedWhenWidgetHasNoAllowedDomains(): void
    {
        $widgetRepo = $this->createMock(WidgetRepository::class);
        $widgetRepo->method('findByWidgetId')->willReturn($this->buildWidget(allowedDomains: []));
        $sessionRepo = $this->createMock(WidgetSessionRepository::class);
        $sessionRepo->method('findByWidgetAndSession')->willReturn($this->buildSession());

        $controller = $this->buildController(
            tokenService: $this->buildTokenService(),
            widgetRepo: $widgetRepo,
            sessionRepo: $sessionRepo,
        );

        // Same fail-closed rule as the chat endpoints: no allowlist, no embed.
        $this->assertSame(403, $controller->issueWidgetToken($this->widgetTokenRequest(), 'wdg_abc', 'sid_xyz')->getStatusCode());
    }

    public function testWidgetTokenEndpointIsRateLimitedPerIp(): void
    {
        $widgetRepo = $this->createMock(WidgetRepository::class);
        $widgetRepo->method('findByWidgetId')->willReturn(null);
        $sessionRepo = $this->createMock(WidgetSessionRepository::class);

        $controller = $this->buildController(
            tokenService: $this->buildTokenService(),
            widgetRepo: $widgetRepo,
            sessionRepo: $sessionRepo,
            widgetTokenLimit: 2,
        );

        // Even failed lookups consume budget — enumeration IS the failure case.
        $first = $controller->issueWidgetToken($this->widgetTokenRequest(), 'wdg_guess1', 'sid_guess1');
        $second = $controller->issueWidgetToken($this->widgetTokenRequest(), 'wdg_guess2', 'sid_guess2');
        $third = $controller->issueWidgetToken($this->widgetTokenRequest(), 'wdg_guess3', 'sid_guess3');

        $this->assertSame(404, $first->getStatusCode());
        $this->assertSame(404, $second->getStatusCode());
        $this->assertSame(429, $third->getStatusCode());
    }

    public function testAnonymousSubscribeIsRateLimitedButOperatorIsNot(): void
    {
        $widgetRepo = $this->createMock(WidgetRepository::class);
        $widgetRepo->method('findByWidgetId')->willReturn($this->buildWidget());
        $sessionRepo = $this->createMock(WidgetSessionRepository::class);
        $sessionRepo->method('findByWidgetAndSession')->willReturn($this->buildSession());

        $controller = $this->buildController(
            tokenService: $this->buildTokenService(),
            widgetRepo: $widgetRepo,
            sessionRepo: $sessionRepo,
            subscribeAnonLimit: 1,
        );

        $makeRequest = static fn (): Request => new Request(content: (string) json_encode([
            'channel' => 'widget:session.wdg_abc.sid_xyz',
            'widgetId' => 'wdg_abc',
            'sessionId' => 'sid_xyz',
        ]));

        $this->assertSame(200, $controller->issueSubscriptionToken($makeRequest(), null)->getStatusCode());
        $this->assertSame(429, $controller->issueSubscriptionToken($makeRequest(), null)->getStatusCode());

        // The authenticated operator path must NOT consume the anonymous
        // budget — operators are identified and accountable.
        $owner = $this->buildUser(7);
        $operatorRequest = new Request(content: (string) json_encode([
            'channel' => 'widget:session.wdg_abc.sid_xyz',
        ]));
        $this->assertSame(200, $controller->issueSubscriptionToken($operatorRequest, $owner)->getStatusCode());
    }

    private function buildTokenService(): RealtimeTokenService
    {
        return new RealtimeTokenService(
            hmacSecret: self::SECRET,
            clock: new class implements ClockInterface {
                private readonly \DateTimeImmutable $now;

                public function __construct()
                {
                    $this->now = new \DateTimeImmutable();
                }

                public function now(): \DateTimeImmutable
                {
                    return $this->now;
                }
            },
            ttlSeconds: 60,
        );
    }

    private function buildLocator(WidgetRepository $widgetRepo, WidgetSessionRepository $sessionRepo): ChannelAuthorizerLocator
    {
        $guard = new \App\Realtime\Authorizer\WidgetSessionAccessGuard($widgetRepo, $sessionRepo);

        return new ChannelAuthorizerLocator([
            new \App\Realtime\Authorizer\WidgetSessionAuthorizer($guard),
            new \App\Realtime\Authorizer\WidgetTypingAuthorizer($guard),
            new \App\Realtime\Authorizer\WidgetOperatorsAuthorizer($widgetRepo),
        ]);
    }

    private function buildController(
        RealtimeTokenService $tokenService,
        WidgetRepository $widgetRepo,
        WidgetSessionRepository $sessionRepo,
        int $widgetTokenLimit = 1000,
        int $subscribeAnonLimit = 1000,
    ): RealtimeTokenController {
        $controller = new RealtimeTokenController(
            tokenService: $tokenService,
            authorizerLocator: $this->buildLocator($widgetRepo, $sessionRepo),
            channelParser: new ChannelParser(),
            visitorAccessChecker: new WidgetVisitorAccessChecker(
                $widgetRepo,
                $sessionRepo,
                new WidgetOriginValidator(),
                new NullLogger(),
            ),
            realtimeWidgetTokenLimiter: $this->buildLimiterFactory('widget_token', $widgetTokenLimit),
            realtimeSubscribeAnonLimiter: $this->buildLimiterFactory('subscribe_anon', $subscribeAnonLimit),
            logger: new NullLogger(),
        );

        // AbstractController::json() needs the serializer service.
        $container = new Container();
        $container->set('serializer', new class {
            public function serialize(mixed $data, string $format): string
            {
                return json_encode($data, JSON_THROW_ON_ERROR);
            }
        });
        $controller->setContainer($container);

        return $controller;
    }

    private function buildLimiterFactory(string $id, int $limit): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => $id, 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '1 minute'],
            new InMemoryStorage(),
        );
    }

    /**
     * @param list<string>|null $allowedDomains
     */
    private function buildWidget(?array $allowedDomains = null): Widget
    {
        return (new Widget())
            ->setOwnerId(7)
            ->setAllowedDomains($allowedDomains ?? [self::ALLOWED_HOST]);
    }

    private function buildSession(int $expiresIn = 3600): WidgetSession
    {
        return (new WidgetSession())->setExpires(time() + $expiresIn);
    }

    /**
     * A request that passes the origin allowlist for {@see self::buildWidget()}.
     */
    private function widgetTokenRequest(): Request
    {
        return new Request(server: ['HTTP_ORIGIN' => 'https://'.self::ALLOWED_HOST]);
    }

    private function buildUser(int $id): User
    {
        $user = new User();
        $reflection = new \ReflectionProperty(User::class, 'id');
        $reflection->setValue($user, $id);

        return $user;
    }
}
