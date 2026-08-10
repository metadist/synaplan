<?php

declare(strict_types=1);

namespace App\Realtime\Token;

use App\Repository\WidgetRepository;
use App\Repository\WidgetSessionRepository;
use App\Service\Widget\WidgetOriginValidator;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Decides whether an anonymous visitor may be issued a Centrifugo
 * connection token for a `(widgetId, sessionId)` pair.
 *
 * Checks, in order:
 *
 *   1. the widget exists,
 *   2. the session exists for that widget,
 *   3. the request originates from a host on the widget's domain
 *      allowlist (same semantics as the widget chat endpoints).
 *
 * Deliberately NOT a gate: session expiry. {@see WidgetSession::isExpired()}
 * only reflects the 24h *quota* window — the chat endpoints
 * ({@see \App\Service\WidgetSessionService::getOrCreateSession()}) and
 * history ({@see \App\Controller\WidgetPublicController::history()}) both
 * treat an expired-but-existing session as resumable, and the subscribe-side
 * {@see \App\Realtime\Authorizer\WidgetSessionAccessGuard} never checked
 * expiry to begin with. Gating the connection token on expiry while every
 * other consumer of the same session ignores it just breaks realtime for a
 * resumed session (#1451) without adding real protection — a probe that
 * already knows a valid (widgetId, sessionId) pair could ride along on the
 * chat endpoints anyway.
 *
 * Detailed failure reasons are logged here; callers only see the coarse
 * {@see WidgetVisitorAccess} outcome, which keeps the HTTP responses
 * generic by construction.
 */
final readonly class WidgetVisitorAccessChecker
{
    public function __construct(
        private WidgetRepository $widgetRepository,
        private WidgetSessionRepository $sessionRepository,
        private WidgetOriginValidator $originValidator,
        private LoggerInterface $logger,
    ) {
    }

    public function check(Request $request, string $widgetId, string $sessionId): WidgetVisitorAccess
    {
        $widget = $this->widgetRepository->findByWidgetId($widgetId);
        $session = null !== $widget
            ? $this->sessionRepository->findByWidgetAndSession($widgetId, $sessionId)
            : null;

        if (null === $widget || null === $session) {
            $this->logger->info('Realtime widget token refused', [
                'widget_id' => $widgetId,
                'widget_found' => null !== $widget,
                'session_found' => null !== $session,
            ]);

            return WidgetVisitorAccess::NotFound;
        }

        if (!$this->originValidator->isRequestAllowed($request, $widget->getAllowedDomains())) {
            $this->logger->warning('Realtime widget token blocked by domain allowlist', [
                'widget_id' => $widgetId,
                'host' => $this->originValidator->extractHostFromRequest($request),
            ]);

            return WidgetVisitorAccess::OriginDenied;
        }

        if ($session->isExpired()) {
            // Not a rejection — see the class docblock. Logged after the
            // origin check so the message cannot claim an issuance that the
            // allowlist then refused, and at debug level because the
            // connection token lives 60s: an info line here would repeat
            // every minute for every resumed visitor in production.
            $this->logger->debug('Realtime widget token issued for expired session', [
                'widget_id' => $widgetId,
            ]);
        }

        return WidgetVisitorAccess::Granted;
    }
}
