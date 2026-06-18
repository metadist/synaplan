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
 *   2. the session exists for that widget and is not expired,
 *   3. the request originates from a host on the widget's domain
 *      allowlist (same semantics as the widget chat endpoints).
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

        if (null === $widget || null === $session || $session->isExpired()) {
            $this->logger->info('Realtime widget token refused', [
                'widget_id' => $widgetId,
                'widget_found' => null !== $widget,
                'session_found' => null !== $session,
                'session_expired' => null !== $session && $session->isExpired(),
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

        return WidgetVisitorAccess::Granted;
    }
}
