<?php

namespace App\Service;

use App\Entity\Widget;
use App\Entity\WidgetSession;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Slack Incoming-Webhook notifier for the widget human-handoff flow.
 *
 * Sister service to {@see DiscordNotificationService} (admin-side WhatsApp /
 * email / embedding monitoring), but scoped at the **widget** level: every
 * widget owner can wire their own Slack channel via the widget config, and
 * the URL never leaves the backend — the embedded widget only sees a derived
 * `humanHandoffEnabled` boolean on /widget/{id}/config.
 *
 * Why a separate service:
 *   - Discord's webhook expects an `embeds[]` schema; Slack expects
 *     `blocks[]` + a fallback `text`. Mixing both in one class would force
 *     callers to know which provider their channel uses.
 *   - The Discord notifier is bound to a single env-level URL
 *     (DISCORD_WEBHOOK_URL); the Slack notifier takes the URL per call
 *     because it's tenant data, not infrastructure.
 *   - Failure semantics differ: Slack returns 200 with `ok` plain-text body
 *     on success and bubbles 4xx for revoked / disabled webhooks. We log and
 *     swallow either way — the user-facing widget click must NEVER fail
 *     because of a misconfigured Slack channel.
 *
 * @see https://api.slack.com/messaging/webhooks
 */
final readonly class SlackNotificationService
{
    // Slack accepts up to 50 blocks per message and 3000 chars per text
    // section. We stay well below both — the goal is a glanceable card with
    // a clear takeover CTA, not a transcript dump.
    private const MAX_LAST_MESSAGE_PREVIEW = 500;
    private const MAX_TRIGGER_PREVIEW = 120;
    private const HTTP_TIMEOUT_SECONDS = 5;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {
    }

    public function isWebhookUrlValid(string $webhookUrl): bool
    {
        $url = trim($webhookUrl);
        if ('' === $url) {
            return false;
        }

        return 1 === preg_match('#^https://hooks\.slack\.com/services/[A-Za-z0-9/_-]+$#', $url);
    }

    /**
     * Send a "human help requested" notification to the widget owner's Slack.
     *
     * @param array<string, string|bool|int|float> $customFieldValues snapshot of WidgetSession::customFieldValues
     *
     * @return bool True if Slack accepted the payload, false on any error
     *              (logged, never thrown — caller's UI flow must keep going)
     */
    public function notifyHumanHandoff(
        Widget $widget,
        WidgetSession $session,
        string $webhookUrl,
        string $takeoverUrl,
        ?string $lastUserMessage = null,
        ?string $triggerReason = null,
        array $customFieldValues = [],
    ): bool {
        if (!$this->isWebhookUrlValid($webhookUrl)) {
            $this->logger->warning('SlackNotificationService: invalid webhook URL skipped', [
                'widget_id' => $widget->getWidgetId(),
                'session_id' => $session->getSessionId(),
            ]);

            return false;
        }

        $payload = $this->buildHandoffPayload(
            $widget,
            $session,
            $takeoverUrl,
            $lastUserMessage,
            $triggerReason,
            $customFieldValues,
        );

        return $this->postToWebhook($webhookUrl, $payload, [
            'widget_id' => $widget->getWidgetId(),
            'session_id' => $session->getSessionId(),
            'reason' => $triggerReason ?? 'manual',
        ]);
    }

    /**
     * Post a one-off test message so admins can verify their webhook before
     * relying on it for real customer escalations.
     */
    public function sendTestMessage(string $webhookUrl, string $widgetName): bool
    {
        if (!$this->isWebhookUrlValid($webhookUrl)) {
            return false;
        }

        $payload = [
            'text' => sprintf(':white_check_mark: Synaplan test ping for *%s*', $widgetName),
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => sprintf(
                            ":white_check_mark: *Synaplan webhook test*\nYour widget *%s* can now post human-handoff notifications to this channel.",
                            self::escapeMrkdwn($widgetName),
                        ),
                    ],
                ],
                [
                    'type' => 'context',
                    'elements' => [
                        [
                            'type' => 'mrkdwn',
                            'text' => sprintf('Sent at %s UTC', (new \DateTimeImmutable())->format('Y-m-d H:i:s')),
                        ],
                    ],
                ],
            ],
        ];

        return $this->postToWebhook($webhookUrl, $payload, [
            'kind' => 'test_message',
            'widget_name' => $widgetName,
        ]);
    }

    /**
     * @param array<string, string|bool|int|float> $customFieldValues
     *
     * @return array<string, mixed> Slack incoming-webhook payload (text + blocks)
     */
    private function buildHandoffPayload(
        Widget $widget,
        WidgetSession $session,
        string $takeoverUrl,
        ?string $lastUserMessage,
        ?string $triggerReason,
        array $customFieldValues,
    ): array {
        $widgetName = $widget->getName() ?: '(unnamed widget)';
        $sessionId = $session->getSessionId();
        $messageCount = $session->getMessageCount();
        $country = $session->getCountry();
        $sessionTitle = $session->getTitle();

        $fallback = sprintf(
            ':bell: Human help requested — widget "%s" (session %s)',
            $widgetName,
            $sessionId,
        );

        $headerBlock = [
            'type' => 'header',
            'text' => [
                'type' => 'plain_text',
                'text' => ':bell: Human help requested',
                'emoji' => true,
            ],
        ];

        $contextLines = [
            sprintf('*Widget:* %s', self::escapeMrkdwn($widgetName)),
            sprintf('*Session:* `%s`', $sessionId),
            sprintf('*Messages so far:* %d', $messageCount),
        ];
        if (null !== $country) {
            $contextLines[] = sprintf('*Country:* %s', $country);
        }
        if (null !== $sessionTitle && '' !== $sessionTitle) {
            $contextLines[] = sprintf('*Title:* %s', self::escapeMrkdwn($sessionTitle));
        }

        $blocks = [
            $headerBlock,
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => implode("\n", $contextLines),
                ],
            ],
        ];

        if (null !== $triggerReason && '' !== $triggerReason) {
            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => sprintf(
                        '*Trigger:* %s',
                        self::escapeMrkdwn(mb_substr($triggerReason, 0, self::MAX_TRIGGER_PREVIEW)),
                    ),
                ],
            ];
        }

        if (null !== $lastUserMessage && '' !== trim($lastUserMessage)) {
            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => sprintf(
                        "*Last visitor message:*\n>%s",
                        self::escapeMrkdwn($this->truncate($lastUserMessage, self::MAX_LAST_MESSAGE_PREVIEW)),
                    ),
                ],
            ];
        }

        $fieldLines = $this->renderCustomFields($customFieldValues);
        if ([] !== $fieldLines) {
            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*Visitor info:*\n".implode("\n", $fieldLines),
                ],
            ];
        }

        // CTA block: a single button that takes the operator straight to the
        // session view in the Synaplan dashboard. Slack renders this as a
        // tappable button on mobile too, which is the fastest path from
        // "ping fired" to "operator typing".
        $blocks[] = [
            'type' => 'actions',
            'elements' => [
                [
                    'type' => 'button',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => 'Take over the chat',
                        'emoji' => true,
                    ],
                    'url' => $takeoverUrl,
                    'style' => 'primary',
                ],
            ],
        ];

        return [
            'text' => $fallback,
            'blocks' => $blocks,
        ];
    }

    /**
     * @param array<string, string|bool|int|float> $values
     *
     * @return list<string>
     */
    private function renderCustomFields(array $values): array
    {
        $lines = [];
        foreach ($values as $key => $value) {
            if (is_bool($value)) {
                $rendered = $value ? 'yes' : 'no';
            } else {
                // Remaining branch is float|int|string per @param doc; all
                // scalar (no further runtime guard needed — PHPStan flags it
                // as `function.alreadyNarrowedType`). Operator-defined
                // custom fields are bounded to scalar values by
                // WidgetSession::setCustomFieldValues anyway.
                $rendered = self::escapeMrkdwn($this->truncate((string) $value, 200));
            }
            $lines[] = sprintf('• *%s:* %s', self::escapeMrkdwn((string) $key), $rendered);
            if (count($lines) >= 10) {
                break;
            }
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $logContext
     */
    private function postToWebhook(string $webhookUrl, array $payload, array $logContext): bool
    {
        try {
            $response = $this->httpClient->request('POST', $webhookUrl, [
                'json' => $payload,
                'timeout' => self::HTTP_TIMEOUT_SECONDS,
            ]);

            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                return true;
            }

            $this->logger->warning('SlackNotificationService: webhook rejected payload', $logContext + [
                'status' => $status,
                'body_preview' => mb_substr($response->getContent(false), 0, 200),
            ]);

            return false;
        } catch (\Throwable $e) {
            $this->logger->warning('SlackNotificationService: webhook request failed', $logContext + [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Slack mrkdwn only treats `&`, `<`, `>` as control characters — escape
     * those so visitor copy can never break the layout or smuggle a fake
     * link. The `*`, `_`, `~` formatting chars stay raw on purpose because
     * we use them in our own templates.
     */
    private static function escapeMrkdwn(string $text): string
    {
        return strtr($text, [
            '&' => '&amp;',
            '<' => '&lt;',
            '>' => '&gt;',
        ]);
    }

    private function truncate(string $text, int $maxLength): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength - 1).'…';
    }
}
