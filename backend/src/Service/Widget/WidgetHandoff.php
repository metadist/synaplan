<?php

declare(strict_types=1);

namespace App\Service\Widget;

use App\Entity\Widget;
use App\Entity\WidgetSession;
use App\Service\Notification\SlackNotificationService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Pages a person for a widget session, from the visitor's handoff button or
 * a configured trigger phrase. The session waits for a person before Slack
 * is called: a concurrent request then sees it and pages nobody twice, and
 * the dashboard shows the request even when Slack is down.
 */
final readonly class WidgetHandoff
{
    public function __construct(
        private SlackNotificationService $slack,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * The first trigger phrase the message contains, case-insensitively; the
     * operator sees it as the reason for the page.
     *
     * @param list<string> $triggers
     */
    public static function matchedTrigger(string $message, array $triggers): ?string
    {
        if ('' === $message || [] === $triggers) {
            return null;
        }
        $haystack = mb_strtolower($message);
        foreach ($triggers as $trigger) {
            $needle = mb_strtolower(trim($trigger));
            if ('' !== $needle && str_contains($haystack, $needle)) {
                return $trigger;
            }
        }

        return null;
    }

    /**
     * @return bool whether Slack accepted the page
     */
    public function page(Widget $widget, WidgetSession $session, string $webhookUrl, ?string $lastMessage, string $reason): bool
    {
        $session->setWaitingForHuman();
        $this->em->flush();

        return $this->slack->notifyHumanHandoff(
            widget: $widget,
            session: $session,
            webhookUrl: $webhookUrl,
            takeoverUrl: self::takeoverUrl($widget->getWidgetId(), $session->getSessionId()),
            lastUserMessage: $lastMessage,
            triggerReason: $reason,
            customFieldValues: $session->getCustomFieldValues() ?? [],
        );
    }

    /**
     * Where the operator's "Take over" button lands: the widget's chats in
     * the app, opened on this session (`/channels/widgets/:widgetId/chats`).
     */
    private static function takeoverUrl(string $widgetId, string $sessionId): string
    {
        $frontendBase = rtrim((string) ($_ENV['FRONTEND_URL'] ?? $_ENV['APP_URL'] ?? 'https://app.synaplan.com'), '/');

        return sprintf('%s/channels/widgets/%s/chats?session=%s', $frontendBase, rawurlencode($widgetId), rawurlencode($sessionId));
    }
}
