<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\AI\Exception\ChatFailureReason;
use App\Service\DiscordNotificationService;
use App\Service\Message\ChatErrorNotifier;
use App\Service\Message\ChatErrorView;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * A single broken credential fails every turn of every user. The operator
 * channel must learn about it once, not once per message.
 */
final class ChatErrorNotifierTest extends TestCase
{
    public function testRepeatedFailuresOfTheSameKindNotifyOnlyOnce(): void
    {
        $discord = $this->createMock(DiscordNotificationService::class);
        $discord->method('isEnabled')->willReturn(true);
        $discord->expects(self::once())->method('notifyChatError');

        $notifier = $this->buildNotifier($discord);
        $view = $this->view(ChatFailureReason::AuthFailed);

        $notifier->notify($view, 'groq', 1);
        $notifier->notify($view, 'groq', 2);
        $notifier->notify($view, 'groq', 3);
    }

    public function testDifferentReasonsAndProvidersAreReportedSeparately(): void
    {
        $discord = $this->createMock(DiscordNotificationService::class);
        $discord->method('isEnabled')->willReturn(true);
        $discord->expects(self::exactly(3))->method('notifyChatError');

        $notifier = $this->buildNotifier($discord);

        $notifier->notify($this->view(ChatFailureReason::AuthFailed), 'groq', 1);
        $notifier->notify($this->view(ChatFailureReason::RateLimited), 'groq', 1);
        $notifier->notify($this->view(ChatFailureReason::AuthFailed), 'openai', 1);
    }

    public function testDisabledWebhookIsNotCalled(): void
    {
        $discord = $this->createMock(DiscordNotificationService::class);
        $discord->method('isEnabled')->willReturn(false);
        $discord->expects(self::never())->method('notifyChatError');

        $this->buildNotifier($discord)->notify($this->view(ChatFailureReason::Timeout), 'groq', 1);
    }

    public function testDiscordFailureDoesNotBubbleIntoTheChatTurn(): void
    {
        $discord = $this->createMock(DiscordNotificationService::class);
        $discord->method('isEnabled')->willReturn(true);
        $discord->method('notifyChatError')->willThrowException(new \RuntimeException('webhook down'));

        $this->buildNotifier($discord)->notify($this->view(ChatFailureReason::Timeout), 'groq', 1);

        $this->expectNotToPerformAssertions();
    }

    private function buildNotifier(DiscordNotificationService $discord): ChatErrorNotifier
    {
        return new ChatErrorNotifier($discord, new ArrayAdapter(), new NullLogger());
    }

    private function view(ChatFailureReason $reason): ChatErrorView
    {
        return new ChatErrorView(
            $reason,
            'user text',
            null,
            $reason->suggestsOtherModel(),
            'raw provider message',
        );
    }
}
