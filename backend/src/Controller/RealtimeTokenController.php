<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Realtime\Authorizer\ChannelAuthorizerLocator;
use App\Realtime\Authorizer\SubscriberContext;
use App\Realtime\Channel\ChannelParser;
use App\Realtime\Exception\InvalidChannelException;
use App\Realtime\Exception\UnauthorizedSubscriptionException;
use App\Realtime\Token\RealtimeSubject;
use App\Realtime\Token\RealtimeTokenService;
use App\Realtime\Token\WidgetVisitorAccess;
use App\Realtime\Token\WidgetVisitorAccessChecker;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Issues short-lived JWTs for browser-side Centrifugo connections and
 * channel subscriptions.
 *
 * The flow:
 *
 *   1. Browser hits {@see self::issueOperatorToken()} (cookie auth) or
 *      {@see self::issueWidgetToken()} (widget+session bearer) to get a
 *      connection token.
 *   2. Browser opens WS to /connection/websocket and presents the token.
 *   3. For each channel, browser asks {@see self::issueSubscriptionToken()}
 *      and Centrifugo binds the subscription server-side.
 *
 * Tokens are intentionally short-lived (60s) so revocation is implicit:
 * pull privileges from the user and their next refresh fails, kicking
 * them off the channel within a minute.
 *
 * Hardening on the anonymous (PUBLIC_ACCESS) endpoints:
 *
 *   - per-IP rate limits (see config/packages/rate_limiter.yaml) keep
 *     widget/session UUID enumeration impractical;
 *   - the visitor token endpoint validates the request origin against the
 *     widget's domain allowlist (same semantics as the chat endpoints);
 *   - error responses are deliberately generic — a probe cannot tell a
 *     missing widget from a missing/expired session.
 */
#[OA\Tag(name: 'Realtime')]
final class RealtimeTokenController extends AbstractController
{
    public function __construct(
        private readonly RealtimeTokenService $tokenService,
        private readonly ChannelAuthorizerLocator $authorizerLocator,
        private readonly ChannelParser $channelParser,
        private readonly WidgetVisitorAccessChecker $visitorAccessChecker,
        private readonly RateLimiterFactoryInterface $realtimeWidgetTokenLimiter,
        private readonly RateLimiterFactoryInterface $realtimeSubscribeAnonLimiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Issue a connection token for an authenticated dashboard user.
     */
    #[Route('/api/v1/realtime/token', name: 'api_realtime_token_user', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/realtime/token',
        summary: 'Issue Centrifugo connection token (operator)',
        description: 'Returns a short-lived HMAC JWT identifying the authenticated dashboard user to Centrifugo.',
        security: [['Bearer' => []]],
        tags: ['Realtime']
    )]
    #[OA\Response(
        response: 200,
        description: 'Connection token',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'token', type: 'string', description: 'HMAC JWT for the WS connect handshake'),
                new OA\Property(property: 'expiresIn', type: 'integer', description: 'Seconds until the token expires', example: 60),
                new OA\Property(property: 'subject', type: 'string', description: 'Echoed `sub` claim used by the WS client', example: 'user:42'),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Not authenticated')]
    public function issueOperatorToken(#[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $subject = RealtimeSubject::forOperator((int) $user->getId());
        $token = $this->tokenService->issueConnectionToken($subject, [
            'kind' => 'operator',
        ]);

        return $this->json([
            'token' => $token,
            'expiresIn' => $this->tokenService->ttlSeconds(),
            'subject' => $subject,
        ]);
    }

    /**
     * Issue a connection token for an anonymous widget visitor.
     *
     * The visitor proves possession of the (widgetId, sessionId) pair.
     * The session id is a guess-resistant UUID minted server-side, so
     * possession is treated as proof of ownership for the purposes of
     * subscribing to that one session's channel — no sensitive data is
     * exposed via the channel that is not already part of the chat.
     *
     * Defence-in-depth on top of the UUID: per-IP rate limit, origin
     * allowlist check, session-expiry check, and a single generic 404 so
     * probes cannot distinguish "widget exists" from "session exists".
     */
    #[Route('/api/v1/realtime/widget/{widgetId}/sessions/{sessionId}/token', name: 'api_realtime_token_widget', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/realtime/widget/{widgetId}/sessions/{sessionId}/token',
        summary: 'Issue Centrifugo connection token (widget visitor)',
        description: 'Anonymous visitor token. The (widgetId, sessionId) pair is verified against the existing widget session store and the request origin against the widget domain allowlist before a token is minted.',
        tags: ['Realtime']
    )]
    #[OA\Parameter(name: 'widgetId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'sessionId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Connection token (same shape as operator)')]
    #[OA\Response(response: 403, description: 'Request origin not on the widget domain allowlist')]
    #[OA\Response(response: 404, description: 'Unknown widget/session pair (deliberately indistinguishable)')]
    #[OA\Response(response: 429, description: 'Rate limit exceeded')]
    public function issueWidgetToken(Request $request, string $widgetId, string $sessionId): JsonResponse
    {
        $limit = $this->realtimeWidgetTokenLimiter
            ->create($request->getClientIp() ?? 'unknown')
            ->consume();
        if (!$limit->isAccepted()) {
            return $this->json(['error' => 'too_many_requests'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $access = $this->visitorAccessChecker->check($request, $widgetId, $sessionId);
        if (WidgetVisitorAccess::NotFound === $access) {
            // One generic 404 for every lookup failure — separate "widget not
            // found" / "session not found" bodies turn this public endpoint
            // into an enumeration oracle. Details are logged by the checker.
            return $this->json(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }
        if (WidgetVisitorAccess::OriginDenied === $access) {
            return $this->json(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        $subject = RealtimeSubject::forVisitor($widgetId, $sessionId);
        $token = $this->tokenService->issueConnectionToken($subject, [
            'kind' => 'widget-visitor',
            'widgetId' => $widgetId,
        ]);

        return $this->json([
            'token' => $token,
            'expiresIn' => $this->tokenService->ttlSeconds(),
            'subject' => $subject,
        ]);
    }

    /**
     * Issue a per-channel subscription token.
     *
     * Body shape:
     *
     *   {
     *     "channel": "widget:session.wdg_x.sid_y",
     *     "widgetId": "wdg_x",        // when called by an anonymous visitor
     *     "sessionId": "sid_y"        // when called by an anonymous visitor
     *   }
     *
     * The same endpoint mints tokens for `widgettyping:wdg_x.sid_y` —
     * the channel namespace decides which authorizer runs. Operators leave
     * widgetId/sessionId out (identity comes from the auth cookie); visitors
     * include them so we know which session they are claiming to own.
     */
    #[Route('/api/v1/realtime/subscribe', name: 'api_realtime_subscribe', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/realtime/subscribe',
        summary: 'Issue Centrifugo subscription token for a single channel',
        description: 'Validates the channel name, dispatches to the matching ChannelAuthorizerInterface, and (on success) returns a short-lived subscription JWT.',
        tags: ['Realtime']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['channel'],
            properties: [
                new OA\Property(property: 'channel', type: 'string', example: 'widget:session.wdg_x.sid_y'),
                new OA\Property(property: 'widgetId', type: 'string', nullable: true, description: 'Required for anonymous visitors subscribing to widget channels.'),
                new OA\Property(property: 'sessionId', type: 'string', nullable: true, description: 'Required for anonymous visitors subscribing to widget channels.'),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Subscription token',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'token', type: 'string'),
                new OA\Property(property: 'channel', type: 'string'),
                new OA\Property(property: 'expiresIn', type: 'integer', example: 60),
            ]
        )
    )]
    #[OA\Response(response: 400, description: 'Invalid channel name')]
    #[OA\Response(response: 403, description: 'Subscription not authorised')]
    #[OA\Response(response: 429, description: 'Rate limit exceeded (anonymous callers)')]
    public function issueSubscriptionToken(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        // Operators are already authenticated and accountable; only the
        // anonymous visitor path needs the per-IP brake.
        if (null === $user) {
            $limit = $this->realtimeSubscribeAnonLimiter
                ->create($request->getClientIp() ?? 'unknown')
                ->consume();
            if (!$limit->isAccepted()) {
                return $this->json(['error' => 'too_many_requests'], Response::HTTP_TOO_MANY_REQUESTS);
            }
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
        }

        $channelName = is_string($payload['channel'] ?? null) ? trim((string) $payload['channel']) : '';
        if ('' === $channelName) {
            return $this->json(['error' => 'channel is required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $channel = $this->channelParser->parse($channelName);
        } catch (InvalidChannelException $e) {
            // Parser messages name valid namespaces and expected formats —
            // useful in logs, but free recon when echoed to an anonymous
            // caller. Keep the wire response generic.
            $this->logger->info('Realtime subscribe rejected: invalid channel', [
                'channel' => $channelName,
                'user_id' => $user?->getId(),
                'reason' => $e->getMessage(),
            ]);

            return $this->json(['error' => 'invalid_channel'], Response::HTTP_BAD_REQUEST);
        }

        $subscriber = $this->buildSubscriberContext($user, $payload);

        try {
            $this->authorizerLocator->authorize($channel, $subscriber);
        } catch (UnauthorizedSubscriptionException $e) {
            $this->logger->info('Realtime subscription refused', [
                'channel' => $channelName,
                'user_id' => $user?->getId(),
                'reason' => $e->getMessage(),
            ]);

            return $this->json(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        $subject = RealtimeSubject::forSubscriber($subscriber);
        $token = $this->tokenService->issueSubscriptionToken($subject, $channel->name());

        return $this->json([
            'token' => $token,
            'channel' => $channel->name(),
            'expiresIn' => $this->tokenService->ttlSeconds(),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildSubscriberContext(?User $user, array $payload): SubscriberContext
    {
        if ($user instanceof User) {
            return new SubscriberContext(user: $user);
        }

        $widgetId = is_string($payload['widgetId'] ?? null) ? (string) $payload['widgetId'] : null;
        $sessionId = is_string($payload['sessionId'] ?? null) ? (string) $payload['sessionId'] : null;

        return new SubscriberContext(
            visitorId: $sessionId,
            extra: [
                'widgetId' => $widgetId,
                'sessionId' => $sessionId,
            ],
        );
    }
}
