<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\Entity\Message;
use App\Service\Message\MessageTurnView;
use App\Service\Message\MessageVersionService;
use PHPUnit\Framework\TestCase;

final class MessageVersionServiceTest extends TestCase
{
    public function testAgainKeepsTheNewAnswerAndDropsTheOldOneFromContext(): void
    {
        $service = new MessageVersionService();
        $older = $this->message(10);
        $newer = $this->message(11);

        $service->linkAgain($newer, $older);

        self::assertSame('10', $older->getMeta(MessageVersionService::VERSION_GROUP));
        self::assertSame('0', $older->getMeta(MessageVersionService::VERSION_SELECTED));
        self::assertSame('1', $newer->getMeta(MessageVersionService::VERSION_SELECTED));
        self::assertSame('2', $newer->getMeta(MessageVersionService::VERSION_INDEX));
        self::assertSame([$newer], $service->activeForContext([$older, $newer]));
    }

    public function testAThirdAgainExtendsTheSameGroup(): void
    {
        $service = new MessageVersionService();
        $first = $this->message(10);
        $second = $this->message(11);
        $third = $this->message(12);
        $service->linkAgain($second, $first);
        $service->linkAgain($third, $second);

        self::assertSame('10', $third->getMeta(MessageVersionService::VERSION_GROUP));
        self::assertSame('3', $third->getMeta(MessageVersionService::VERSION_INDEX));
        self::assertSame('0', $second->getMeta(MessageVersionService::VERSION_SELECTED));
        self::assertSame([$third], $service->activeForContext([$first, $second, $third]));
    }

    public function testSelectingAnOlderAnswerRestoresIt(): void
    {
        $service = new MessageVersionService();
        $first = $this->message(10);
        $second = $this->message(11);
        $service->linkAgain($second, $first);
        $service->selectAnswer($first, [$first, $second]);

        self::assertSame([$first], $service->activeForContext([$first, $second]));
    }

    public function testEditHidesThePreviousBranchUntilItIsChosenAgain(): void
    {
        $service = new MessageVersionService();
        $oldUser = $this->message(1);
        $oldAnswer = $this->message(2);
        $newUser = $this->message(3);
        $newAnswer = $this->message(4);

        $service->linkEdit($newUser, $oldUser, $newAnswer, [$oldUser, $oldAnswer]);

        $all = [$oldUser, $oldAnswer, $newUser, $newAnswer];
        self::assertSame([$newUser, $newAnswer], $service->activeForContext($all));

        $service->selectEdit($oldUser, $all, $all);
        $active = $service->activeForContext($all);
        self::assertContains($oldUser, $active);
        self::assertContains($oldAnswer, $active);
        self::assertNotContains($newUser, $active);
        self::assertNotContains($newAnswer, $active);
    }

    public function testPresentKeepsOneAnswerAndListsBothVersions(): void
    {
        $rows = MessageTurnView::present([
            [
                'id' => 10,
                'versionGroup' => 10,
                'versionIndex' => 1,
                'versionSelected' => false,
                'aiModels' => ['chat' => ['model' => 'alpha']],
            ],
            [
                'id' => 11,
                'versionGroup' => 10,
                'versionIndex' => 2,
                'versionSelected' => true,
                'aiModels' => ['chat' => ['model' => 'beta']],
            ],
        ]);

        self::assertCount(1, $rows);
        self::assertSame(11, $rows[0]['id']);
        self::assertSame(
            [
                ['id' => 10, 'index' => 1, 'selected' => false, 'model' => 'alpha'],
                ['id' => 11, 'index' => 2, 'selected' => true, 'model' => 'beta'],
            ],
            $rows[0]['versions'],
        );
    }

    private function message(int $id): Message
    {
        $message = new Message();
        $property = new \ReflectionProperty(Message::class, 'id');
        $property->setValue($message, $id);

        return $message;
    }
}
