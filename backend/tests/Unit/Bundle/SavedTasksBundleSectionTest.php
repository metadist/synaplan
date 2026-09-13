<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bundle;

use App\Bundle\BundleScope;
use App\Bundle\ImportOptions;
use App\Bundle\Section\SavedTasksBundleSection;
use App\Entity\Prompt;
use App\Entity\SavedTask;
use App\Entity\User;
use App\Repository\McpServerConfigRepository;
use App\Repository\PromptRepository;
use App\Repository\SavedTaskRepository;
use App\Repository\UserRepository;
use App\Service\SavedTask\Graph\SavedTaskGraphPortability;
use App\Service\SavedTask\SavedTaskConfig;
use PHPUnit\Framework\TestCase;

final class SavedTasksBundleSectionTest extends TestCase
{
    public function testExportStripsWebhookSecrets(): void
    {
        $task = new SavedTask(4, 12, 'Monday digest');
        $task->setTrigger(SavedTask::TRIGGER_WEBHOOK, ['token' => 'secret-token', 'hmacSecret' => 'hmac']);
        (new \ReflectionProperty(SavedTask::class, 'id'))->setValue($task, 3);

        $prompt = new Prompt();
        $prompt->setTopic('support');

        $tasks = $this->createMock(SavedTaskRepository::class);
        $tasks->expects(self::any())->method('findByOwner')->with(4)->willReturn([$task]);

        $prompts = $this->createMock(PromptRepository::class);
        $prompts->expects(self::any())->method('find')->with(12)->willReturn($prompt);

        $section = $this->section($tasks, $prompts);
        $items = $section->export(4, BundleScope::User);

        self::assertSame('monday-digest', $items[0]['key']);
        self::assertSame('support', $items[0]['prompt']);
        self::assertArrayNotHasKey('token', $items[0]['triggerConfig'] ?? []);
        self::assertArrayNotHasKey('hmacSecret', $items[0]['triggerConfig'] ?? []);
        self::assertFalse($items[0]['settings']['allowUnattended']);
    }

    public function testApplyRejectsUnknownKeysAndImportsPaused(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(4);
        $users = $this->createMock(UserRepository::class);
        $users->method('find')->willReturn($user);

        $prompt = new Prompt();
        $prompt->setTopic('support');
        (new \ReflectionProperty(Prompt::class, 'id'))->setValue($prompt, 12);
        $prompts = $this->createMock(PromptRepository::class);
        $prompts->method('findByTopicAndUser')->willReturn($prompt);

        $saved = null;
        $tasks = $this->createMock(SavedTaskRepository::class);
        $tasks->method('save')->willReturnCallback(static function (SavedTask $task) use (&$saved): void {
            $saved = $task;
        });

        $section = $this->section($tasks, $prompts, $users);

        $rejected = $section->apply([
            ['key' => 'bad', 'name' => 'Bad', 'token' => 'nope'],
        ], 4, new ImportOptions());
        self::assertNotEmpty($rejected->toArray()['failed']);

        $ok = $section->apply([
            [
                'key' => 'monday-digest',
                'name' => 'Monday digest',
                'prompt' => 'support',
                'triggerType' => SavedTask::TRIGGER_SCHEDULE,
                'triggerConfig' => ['kind' => 'weekly', 'at' => '08:00'],
                'graph' => [
                    'version' => 1,
                    'nodes' => [['id' => 'n1', 'capability' => 'chat', 'depends_on' => []]],
                ],
                'settings' => ['allowUnattended' => true],
            ],
        ], 4, new ImportOptions());

        self::assertSame(['monday-digest'], $ok->toArray()['created']);
        self::assertInstanceOf(SavedTask::class, $saved);
        self::assertFalse($saved->isEnabled());
        self::assertFalse($saved->allowsUnattended());
        self::assertSame(SavedTask::TRIGGER_SCHEDULE, $saved->getTriggerType());
        self::assertSame(12, $saved->getPromptId());
    }

    private function section(
        SavedTaskRepository $tasks,
        PromptRepository $prompts,
        ?UserRepository $users = null,
    ): SavedTasksBundleSection {
        $config = $this->createMock(SavedTaskConfig::class);
        $config->method('isEnabled')->willReturn(true);

        return new SavedTasksBundleSection(
            $tasks,
            $config,
            new SavedTaskGraphPortability($prompts, $this->createStub(McpServerConfigRepository::class)),
            $prompts,
            $users ?? $this->createStub(UserRepository::class),
        );
    }
}
