<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Widget;
use App\Entity\WidgetSession;
use App\Service\SlackNotificationService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Unit tests for {@see SlackNotificationService}.
 *
 * Three things must NEVER regress:
 *   1. Only `hooks.slack.com` URLs over HTTPS are accepted — otherwise a
 *      typo'd config could turn the widget into an exfiltration vector
 *      (visitor data + session ids land at an attacker's URL).
 *   2. The Slack payload always carries the takeover URL as a `button`
 *      element so the dashboard is one tap away on mobile.
 *   3. Webhook failures (network, 4xx, exceptions) are caught — the manual
 *      "Talk to a human" button click MUST never bubble an exception that
 *      would break the visitor's chat session.
 */
final class SlackNotificationServiceTest extends TestCase
{
    private HttpClientInterface&MockObject $httpClient;
    private LoggerInterface&MockObject $logger;
    private SlackNotificationService $service;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new SlackNotificationService($this->httpClient, $this->logger);
    }

    #[Test]
    public function isWebhookUrlValidAcceptsCanonicalSlackUrl(): void
    {
        // Token segments deliberately use obviously-non-real placeholders so
        // GitHub's push-protection scanner does not flag this as a leaked
        // Slack webhook. The format still matches our isWebhookUrlValid()
        // regex (^https://hooks\.slack\.com/services/[A-Za-z0-9/_-]+$).
        $this->assertTrue(
            $this->service->isWebhookUrlValid('https://hooks.slack.com/services/EXAMPLE_TEAM/EXAMPLE_BOT/EXAMPLE_SECRET_VALUE')
        );
    }

    #[Test]
    public function isWebhookUrlValidRejectsHttpScheme(): void
    {
        $this->assertFalse(
            $this->service->isWebhookUrlValid('http://hooks.slack.com/services/T01/B01/secret')
        );
    }

    #[Test]
    public function isWebhookUrlValidRejectsForeignHost(): void
    {
        $this->assertFalse(
            $this->service->isWebhookUrlValid('https://attacker.example/services/T01/B01/secret')
        );
    }

    #[Test]
    public function isWebhookUrlValidRejectsEmptyString(): void
    {
        $this->assertFalse($this->service->isWebhookUrlValid(''));
        $this->assertFalse($this->service->isWebhookUrlValid('   '));
    }

    #[Test]
    public function notifyHumanHandoffPostsPayloadWithTakeoverButton(): void
    {
        $captured = null;

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://hooks.slack.com/services/T/B/secret',
                $this->callback(function (array $options) use (&$captured): bool {
                    $captured = $options['json'] ?? null;

                    return true;
                })
            )
            ->willReturn($response);

        $widget = $this->makeWidget('Acme Support', 'wdg_test');
        $session = $this->makeSession('wdg_test', 'sess_abc');

        $delivered = $this->service->notifyHumanHandoff(
            widget: $widget,
            session: $session,
            webhookUrl: 'https://hooks.slack.com/services/T/B/secret',
            takeoverUrl: 'https://app.example/channels/widgets/wdg_test/chats?session=sess_abc',
            lastUserMessage: 'I need a real person please',
            triggerReason: 'manual button click',
            customFieldValues: ['email' => 'visitor@example.com'],
        );

        $this->assertTrue($delivered);
        $this->assertIsArray($captured);
        $this->assertArrayHasKey('text', $captured, 'Slack uses `text` as fallback in mobile push notifications');
        $this->assertArrayHasKey('blocks', $captured);

        // Locate the actions block + its primary button. Loose loop instead
        // of asserting by index so future block additions don't break this.
        $takeoverButton = null;
        foreach ($captured['blocks'] as $block) {
            if (($block['type'] ?? null) === 'actions') {
                foreach ($block['elements'] ?? [] as $element) {
                    if (($element['type'] ?? null) === 'button') {
                        $takeoverButton = $element;
                        break 2;
                    }
                }
            }
        }
        $this->assertNotNull($takeoverButton, 'Slack payload MUST include a takeover button');
        $this->assertSame('https://app.example/channels/widgets/wdg_test/chats?session=sess_abc', $takeoverButton['url']);
        $this->assertSame('primary', $takeoverButton['style']);
    }

    #[Test]
    public function notifyHumanHandoffShortCircuitsOnInvalidWebhook(): void
    {
        $this->httpClient->expects($this->never())->method('request');
        $this->logger->expects($this->once())->method('warning');

        $widget = $this->makeWidget('Acme', 'wdg_x');
        $session = $this->makeSession('wdg_x', 'sess_x');

        $delivered = $this->service->notifyHumanHandoff(
            widget: $widget,
            session: $session,
            webhookUrl: 'https://attacker.example/exfil',
            takeoverUrl: 'https://app.example/channels/widgets/wdg_x/chats?session=sess_x',
        );

        $this->assertFalse($delivered);
    }

    #[Test]
    public function notifyHumanHandoffSwallowsHttpErrors(): void
    {
        $this->httpClient
            ->method('request')
            ->willThrowException(new \RuntimeException('connection refused'));

        $widget = $this->makeWidget('Acme', 'wdg_x');
        $session = $this->makeSession('wdg_x', 'sess_x');

        $this->logger->expects($this->atLeastOnce())->method('warning');

        $delivered = $this->service->notifyHumanHandoff(
            widget: $widget,
            session: $session,
            webhookUrl: 'https://hooks.slack.com/services/T/B/secret',
            takeoverUrl: 'https://app.example/channels/widgets/wdg_x/chats?session=sess_x',
        );

        // MUST NOT throw — caller is the live widget button click flow.
        $this->assertFalse($delivered);
    }

    #[Test]
    public function notifyHumanHandoffEscapesVisitorCopyInMrkdwn(): void
    {
        $captured = null;
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient
            ->expects(self::any())
            ->method('request')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(function (array $options) use (&$captured): bool {
                    $captured = $options['json'] ?? null;

                    return true;
                })
            )
            ->willReturn($response);

        $widget = $this->makeWidget('Evil <script> Co', 'wdg_e');
        $session = $this->makeSession('wdg_e', 'sess_e');

        $this->service->notifyHumanHandoff(
            widget: $widget,
            session: $session,
            webhookUrl: 'https://hooks.slack.com/services/T/B/secret',
            takeoverUrl: 'https://app.example/x',
            lastUserMessage: 'How do I <script>alert(1)</script> & escape stuff?',
        );

        // Slack's `text` is the plaintext fallback for mobile push notifications
        // and is rendered as-is by Slack (no HTML, no mrkdwn interpretation), so
        // leaving < and > raw there is fine. Escaping matters in mrkdwn blocks
        // because `<`/`>` can otherwise be used to forge fake links like
        // `<https://evil/|Click here>`. Verify each block text individually.
        $mrkdwnBlocks = [];
        foreach ($captured['blocks'] as $block) {
            if (($block['type'] ?? null) === 'section'
                && ($block['text']['type'] ?? null) === 'mrkdwn'
            ) {
                $mrkdwnBlocks[] = $block['text']['text'];
            }
        }
        $combined = implode("\n", $mrkdwnBlocks);
        $this->assertStringNotContainsString('<script>', $combined, 'mrkdwn blocks must escape < to prevent forged-link smuggling');
        $this->assertStringContainsString('&lt;script&gt;', $combined);
        $this->assertStringContainsString('Evil &lt;script&gt; Co', $combined);
    }

    #[Test]
    public function sendTestMessageDeliversToValidWebhook(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $this->assertTrue(
            $this->service->sendTestMessage('https://hooks.slack.com/services/T/B/secret', 'Acme')
        );
    }

    #[Test]
    public function sendTestMessageReturnsFalseOnNon2xx(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(403);
        $response->method('getContent')->willReturn('invalid_token');

        $this->httpClient->method('request')->willReturn($response);
        $this->logger->expects($this->atLeastOnce())->method('warning');

        $this->assertFalse(
            $this->service->sendTestMessage('https://hooks.slack.com/services/T/B/secret', 'Acme')
        );
    }

    private function makeWidget(string $name, string $widgetId): Widget
    {
        $widget = new Widget();
        $widget->setName($name);
        $widget->setWidgetId($widgetId);

        return $widget;
    }

    private function makeSession(string $widgetId, string $sessionId): WidgetSession
    {
        $session = new WidgetSession();
        $session->setWidgetId($widgetId);
        $session->setSessionId($sessionId);

        return $session;
    }
}
