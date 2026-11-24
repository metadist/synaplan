<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Stream;

use App\Entity\Chat;
use App\Entity\Project;
use App\Entity\User;
use App\Entity\WidgetSession;
use App\Repository\MessageRepository;
use App\Repository\ProjectRepository;
use App\Service\Chat\Stream\StreamAttachmentGate;
use App\Service\Chat\Stream\StreamCaller;
use App\Service\Chat\Stream\StreamMessageMeta;
use App\Service\Chat\Stream\StreamTurnOptions;
use App\Service\Chat\Stream\StreamTurnPreparer;
use App\Service\Chat\Stream\StreamTurnRequest;
use App\Service\Chat\Stream\StreamTurnState;
use App\Service\Chat\Stream\StreamUserInput;
use App\Service\Infrastructure\PerfTimer;
use App\Service\Message\MessageForwardingService;
use App\Service\Project\ProjectChatScope;
use App\Service\Project\ProjectsConfig;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The knowledge-base folder picked in the chat composer (`ragGroupKey`,
 * PR #1036) scopes the turn's retrieval.
 *
 * The contract pinned here:
 *   - A non-empty key from a normal (non-widget) chat is forwarded into
 *     `processingOptions['rag_group_key']`.
 *   - Widget mode must NEVER honour a caller-supplied key — an embedded
 *     widget is locked to its own configuration and cannot widen or
 *     redirect retrieval.
 *   - An empty/null key is a no-op (default, unscoped retrieval).
 */
final class StreamRagGroupKeyTest extends TestCase
{
    public function testForwardsKeyForNormalChat(): void
    {
        $options = $this->processingOptions(false, 'kb-research');

        $this->assertSame('kb-research', $options['rag_group_key']);
        $this->assertTrue($options['defer_memory_extraction'], 'the other options must be preserved');
    }

    public function testWidgetModeCannotOverrideRagGroup(): void
    {
        $options = $this->processingOptions(true, 'kb-research');

        $this->assertArrayNotHasKey('rag_group_key', $options);
        $this->assertTrue($options['is_widget_mode']);
    }

    public function testEmptyKeyIsNoOp(): void
    {
        $this->assertArrayNotHasKey('rag_group_key', $this->processingOptions(false, ''));
        $this->assertArrayNotHasKey('rag_group_key', $this->processingOptions(false, null));
    }

    public function testProjectFolderIsPinnedAgainstTheRequestParameter(): void
    {
        $project = new Project(0, 'Kitchen', 'u-1', 'project:u-1');
        $chat = (new Chat())->setUserId(0)->setProjectId(5);
        $options = $this->processingOptions(false, 'someone-elses-folder', $chat, true, $project);

        self::assertSame('project:u-1', $options['rag_group_key']);
    }

    public function testProjectChatWithFlagOffKeepsThePlainBehaviour(): void
    {
        $project = new Project(0, 'Kitchen', 'u-1', 'project:u-1');
        $chat = (new Chat())->setUserId(0)->setProjectId(5);
        $options = $this->processingOptions(false, null, $chat, false, $project);

        self::assertArrayNotHasKey('rag_group_key', $options);
        self::assertArrayNotHasKey('project_instructions', $options);
    }

    /**
     * @return array<string, mixed>
     */
    private function processingOptions(bool $widget, ?string $ragGroupKey, ?Chat $chat = null, bool $projectsOn = false, ?Project $project = null): array
    {
        $caller = new StreamCaller(new User(), 'en', widgetSession: $widget ? new WidgetSession() : null);
        $turn = new StreamTurnRequest(
            caller: $caller,
            chatId: '7',
            trackId: '4242',
            input: new StreamUserInput('Hello'),
            options: new StreamTurnOptions(ragGroupKey: $ragGroupKey),
        );

        $config = $this->createStub(ProjectsConfig::class);
        $config->method('isEnabled')->willReturn($projectsOn);
        $config->method('instructionsMaxChars')->willReturn(4000);
        $repo = $this->createStub(ProjectRepository::class);
        $repo->method('findById')->willReturn($project);

        $preparer = new StreamTurnPreparer(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(MessageForwardingService::class),
            new StreamMessageMeta(new NullLogger()),
            new NullLogger(),
            new ProjectChatScope($config, $repo),
            new StreamAttachmentGate($this->createStub(MessageRepository::class)),
        );

        $state = new StreamTurnState($turn, new PerfTimer(), ['id' => null, 'name' => null, 'provider' => null]);
        $state->chat = $chat;

        return $preparer->processingOptions($turn, $state);
    }
}
