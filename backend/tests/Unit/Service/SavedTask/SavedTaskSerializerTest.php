<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SavedTask;

use App\Entity\Prompt;
use App\Entity\SavedTask;
use App\Repository\PromptRepository;
use App\Service\SavedTask\Graph\SavedTaskSummary;
use App\Service\SavedTask\SavedTaskSerializer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SavedTaskSerializerTest extends TestCase
{
    private PromptRepository&MockObject $prompts;
    private SavedTaskSerializer $serializer;

    protected function setUp(): void
    {
        $this->prompts = $this->createMock(PromptRepository::class);
        $this->serializer = new SavedTaskSerializer(new SavedTaskSummary(), $this->prompts);
    }

    public function testTaskIncludesTheStartOfTheInstruction(): void
    {
        $this->prompts->method('find')->willReturn(
            $this->prompt('Erstelle ein realistisches Bild einer Katze, weiches natürliches Licht, scharfe Details'),
        );

        $data = $this->serializer->task(new SavedTask(1, 12, 'Katzenbild'));

        self::assertSame(
            'Erstelle ein realistisches Bild einer Katze, weiches natürli…',
            $data['instructionPreview'],
        );
    }

    public function testShortInstructionIsNotTruncated(): void
    {
        $this->prompts->method('find')->willReturn($this->prompt("Summarize   my\ninbox"));

        $data = $this->serializer->task(new SavedTask(1, 12, 'Inbox'));

        self::assertSame('Summarize my inbox', $data['instructionPreview']);
    }

    public function testWebhookTriggerNeverExposesTheHmacSecret(): void
    {
        $this->prompts->method('find')->willReturn(null);
        $task = new SavedTask(1, 12, 'From n8n');
        $task->setTrigger(SavedTask::TRIGGER_WEBHOOK, [
            'token' => 'public-token',
            'hmacSecret' => 'super-secret',
        ]);

        $data = $this->serializer->task($task);
        $config = $data['triggerConfig'];

        self::assertIsArray($config);
        self::assertSame('public-token', $config['token']);
        self::assertTrue($config['hmacConfigured']);
        self::assertArrayNotHasKey('hmacSecret', $config);
        self::assertArrayNotHasKey('webhookSecret', $data);
    }

    public function testFreshHmacSecretIsRevealedOnlyWhenAskedFor(): void
    {
        $this->prompts->method('find')->willReturn(null);
        $task = new SavedTask(1, 12, 'From n8n');
        $task->setTrigger(SavedTask::TRIGGER_WEBHOOK, [
            'token' => 'public-token',
            'hmacSecret' => 'super-secret',
        ]);

        $revealed = $this->serializer->task($task, 'super-secret');

        self::assertSame('super-secret', $revealed['webhookSecret']);
        self::assertArrayNotHasKey('hmacSecret', $revealed['triggerConfig']);
        self::assertArrayNotHasKey('webhookSecret', $this->serializer->task($task));
    }

    public function testOutboundStepSecretsNeverLeaveTheServer(): void
    {
        $this->prompts->method('find')->willReturn(null);
        $task = new SavedTask(1, 12, 'Outbound');
        $task->setGraph(['version' => 1, 'trigger' => ['type' => 'manual'], 'nodes' => [
            ['id' => 'n1', 'capability' => 'outbound_webhook', 'depends_on' => [], 'params' => ['url' => 'https://hooks.example/in', 'secret' => 'shh']],
            ['id' => 'n2', 'capability' => 'outbound_webhook', 'depends_on' => [], 'params' => ['url' => 'https://hooks.example/open']],
            ['id' => 'n3', 'capability' => 'chat', 'depends_on' => [], 'params' => ['secret' => 'not-an-outbound-step']],
        ]]);

        $graph = $this->serializer->task($task)['graph'];

        self::assertIsArray($graph);
        self::assertSame(['url' => 'https://hooks.example/in', 'secretConfigured' => true], $graph['nodes'][0]['params']);
        self::assertSame(['url' => 'https://hooks.example/open', 'secretConfigured' => false], $graph['nodes'][1]['params']);
        self::assertSame(['secret' => 'not-an-outbound-step'], $graph['nodes'][2]['params']);
        self::assertStringNotContainsString('shh', json_encode($graph, \JSON_THROW_ON_ERROR));
    }

    public function testMissingPromptYieldsNullPreview(): void
    {
        $this->prompts->method('find')->willReturn(null);

        $data = $this->serializer->task(new SavedTask(1, 999, 'Orphan'));

        self::assertNull($data['instructionPreview']);
    }

    private function prompt(string $text): Prompt
    {
        $prompt = new Prompt();
        $prompt->setPrompt($text);

        return $prompt;
    }
}
