<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Widget;

use App\Entity\Widget;
use App\Entity\WidgetSession;
use App\Service\Notification\SlackNotificationService;
use App\Service\Widget\WidgetHandoff;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class WidgetHandoffTest extends TestCase
{
    public function testTheFirstTriggerPhraseInTheMessageMatchesInAnyCase(): void
    {
        self::assertSame('Talk to a human', WidgetHandoff::matchedTrigger('Please let me TALK TO A HUMAN now', ['  ', 'refund', 'Talk to a human', 'human']));
        self::assertNull(WidgetHandoff::matchedTrigger('Hello', ['refund']));
        self::assertNull(WidgetHandoff::matchedTrigger('', ['refund']));
        self::assertNull(WidgetHandoff::matchedTrigger('Hello', []));
    }

    public function testTheSessionWaitsForAPersonBeforeSlackIsPaged(): void
    {
        $steps = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush')->willReturnCallback(static function () use (&$steps): void {
            $steps[] = 'flush';
        });
        $slack = $this->createMock(SlackNotificationService::class);
        $slack->expects(self::once())->method('notifyHumanHandoff')->willReturnCallback(
            static function (Widget $widget, WidgetSession $session, string $webhookUrl, string $takeoverUrl, ?string $lastUserMessage, ?string $triggerReason, array $customFieldValues) use (&$steps): bool {
                $steps[] = [$session->getMode(), $webhookUrl, str_ends_with($takeoverUrl, '/channels/widgets/wdg_1/chats?session=sess%201'), $lastUserMessage, $triggerReason, $customFieldValues];

                return true;
            },
        );
        $session = (new WidgetSession())->setWidgetId('wdg_1')->setSessionId('sess 1')->setCustomFieldValues(['name' => 'Ada']);

        $paged = (new WidgetHandoff($slack, $em))->page((new Widget())->setWidgetId('wdg_1'), $session, 'https://hooks.slack.com/x', 'help me', 'manual button click');

        self::assertTrue($paged);
        self::assertSame(['flush', [WidgetSession::MODE_WAITING, 'https://hooks.slack.com/x', true, 'help me', 'manual button click', ['name' => 'Ada']]], $steps);
    }
}
