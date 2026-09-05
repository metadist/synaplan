<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SavedTask;

use App\Entity\Prompt;
use App\Entity\SavedTask;
use App\Entity\User;
use App\Repository\PromptRepository;
use App\Repository\SavedTaskRepository;
use App\Repository\SavedTaskRunRepository;
use App\Service\Iam\AccessGate;
use App\Service\Iam\Exception\AssistantNotSharedException;
use App\Service\Iam\Permission;
use App\Service\Iam\ResourceKind\SavedTaskKind;
use App\Service\SavedTask\Graph\SavedTaskGraphValidator;
use App\Service\SavedTask\SavedTaskService;
use App\Service\SavedTask\Schedule\ScheduleParser;
use PHPUnit\Framework\TestCase;

final class SavedTaskServiceCopyTest extends TestCase
{
    public function testCopyResetsTriggerAndUnattended(): void
    {
        $source = new SavedTask(9, 5, 'Weekly');
        $source->setTrigger(SavedTask::TRIGGER_SCHEDULE, ['kind' => 'daily', 'at' => '07:00']);
        $source->setAllowUnattended(true);
        $source->setGraph(['nodes' => []]);
        (new \ReflectionProperty(SavedTask::class, 'id'))->setValue($source, 11);

        $prompt = new Prompt();
        $prompt->setOwnerId(9);
        $prompt->setTopic('sales');
        $prompt->setShortDescription('Sales');
        $prompt->setPrompt('Help sales.');

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(3);

        $gate = $this->createMock(AccessGate::class);
        $gate->method('decide')->willReturn(true);

        $prompts = $this->createMock(PromptRepository::class);
        $prompts->expects(self::atLeastOnce())
            ->method('find')
            ->with(5)
            ->willReturn($prompt);

        $saved = null;
        $tasks = $this->createMock(SavedTaskRepository::class);
        $tasks->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (SavedTask $copy) use (&$saved): void {
                $saved = $copy;
            });

        $service = new SavedTaskService(
            $tasks,
            $this->createStub(SavedTaskRunRepository::class),
            $prompts,
            $this->createStub(SavedTaskGraphValidator::class),
            $this->createStub(ScheduleParser::class),
            $gate,
        );

        $copy = $service->copyForOwner($source, $user);

        self::assertSame($saved, $copy);
        self::assertSame(SavedTask::TRIGGER_MANUAL, $copy->getTriggerType());
        self::assertFalse($copy->allowsUnattended());
        self::assertNull($copy->getChatId());
        self::assertSame(3, $copy->getOwnerId());
        self::assertSame(['nodes' => []], $copy->getGraph());
    }

    public function testCopyWithoutAssistantAccessThrowsConflict(): void
    {
        $source = new SavedTask(9, 5, 'Weekly');
        (new \ReflectionProperty(SavedTask::class, 'id'))->setValue($source, 11);

        $prompt = new Prompt();
        $prompt->setOwnerId(9);
        $prompt->setTopic('sales');
        $prompt->setPrompt('Help.');

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(3);

        $gate = $this->createMock(AccessGate::class);
        $gate->method('decide')->willReturnCallback(
            static function (User $_user, string $kind, string $_id, Permission $_perm): bool {
                return SavedTaskKind::KEY === $kind;
            }
        );

        $prompts = $this->createMock(PromptRepository::class);
        $prompts->expects(self::atLeastOnce())
            ->method('find')
            ->with(5)
            ->willReturn($prompt);

        $service = new SavedTaskService(
            $this->createStub(SavedTaskRepository::class),
            $this->createStub(SavedTaskRunRepository::class),
            $prompts,
            $this->createStub(SavedTaskGraphValidator::class),
            $this->createStub(ScheduleParser::class),
            $gate,
        );

        $this->expectException(AssistantNotSharedException::class);
        $this->expectExceptionMessage('iam.assistantNotShared');
        $service->copyForOwner($source, $user);
    }

    public function testCopyRequiresUseOnTheTask(): void
    {
        $source = new SavedTask(9, 5, 'Weekly');
        (new \ReflectionProperty(SavedTask::class, 'id'))->setValue($source, 11);
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(3);

        $gate = $this->createMock(AccessGate::class);
        $gate->expects(self::once())
            ->method('decide')
            ->with($user, SavedTaskKind::KEY, '11', Permission::Use)
            ->willReturn(false);

        $service = new SavedTaskService(
            $this->createStub(SavedTaskRepository::class),
            $this->createStub(SavedTaskRunRepository::class),
            $this->createStub(PromptRepository::class),
            $this->createStub(SavedTaskGraphValidator::class),
            $this->createStub(ScheduleParser::class),
            $gate,
        );

        $this->expectException(\App\Service\SavedTask\SavedTaskNotFoundException::class);
        $service->copyForOwner($source, $user);
    }
}
