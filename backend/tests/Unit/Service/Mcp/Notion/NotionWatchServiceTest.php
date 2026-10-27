<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mcp\Notion;

use App\Entity\McpServerConfig;
use App\Entity\NotionWatch;
use App\Entity\SavedTask;
use App\Entity\User;
use App\Repository\NotionWatchRepository;
use App\Repository\SavedTaskRepository;
use App\Repository\UserRepository;
use App\Service\Mcp\McpClientException;
use App\Service\Mcp\Notion\NotionActionException;
use App\Service\Mcp\Notion\NotionGateway;
use App\Service\Mcp\Notion\NotionPageIndexer;
use App\Service\Mcp\Notion\NotionWatchService;
use App\Service\SavedTask\SavedTaskRunner;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
final class NotionWatchServiceTest extends TestCase
{
    private const PAGE = 'https://www.notion.so/Roadmap-39797ad60eb3806db59eef7653838664';

    private NotionGateway&Stub $gateway;
    private NotionPageIndexer&MockObject $indexer;
    private SavedTaskRunner&MockObject $runner;
    private SavedTaskRepository&Stub $tasks;

    protected function setUp(): void
    {
        $this->gateway = $this->createStub(NotionGateway::class);
        $this->gateway->method('readable')->willReturn(new McpServerConfig());
        $this->indexer = $this->createMock(NotionPageIndexer::class);
        $this->runner = $this->createMock(SavedTaskRunner::class);
        $this->tasks = $this->createStub(SavedTaskRepository::class);
    }

    public function testFollowingForSearchIndexesThePageRightAway(): void
    {
        $this->page('Roadmap', '2026-10-27T10:00:00.000Z');
        $this->indexer->expects($this->once())->method('index')
            ->with($this->anything(), '39797ad6-0eb3-806d-b59e-ef7653838664', 'Roadmap', $this->anything(), '2026-10-27T10:00:00.000Z', 'Team docs')
            ->willReturn(42);

        $row = $this->service()->create(4, self::PAGE, NotionWatch::ACTION_SEARCHABLE, 'Team docs', null);

        self::assertSame('Roadmap', $row['title']);
        self::assertSame(42, $row['file_id']);
        self::assertSame('Team docs', $row['folder']);
        self::assertNull($row['last_error']);
        self::assertNull($row['changed_at']);
    }

    public function testATaskStartsOnlyWhenThePageChangesAfterFollowing(): void
    {
        $task = new SavedTask(4, 1, 'Summarise');
        (new \ReflectionProperty($task, 'id'))->setValue($task, 9);
        $this->tasks->method('findByIdAndOwner')->willReturn($task);
        $watch = new NotionWatch(4, '39797ad6-0eb3-806d-b59e-ef7653838664', 'https://www.notion.so/x', NotionWatch::ACTION_TASK);
        $watch->setTaskId(9);
        $this->gateway->method('call')->willReturnOnConsecutiveCalls(
            ['title' => 'Roadmap', 'text' => 'v1', 'page_last_edited_at' => 'a'],
            ['title' => 'Roadmap', 'text' => 'v1', 'page_last_edited_at' => 'a'],
            ['title' => 'Roadmap', 'text' => 'Here is the result of "fetch"'."\n".'v2', 'page_last_edited_at' => 'b'],
        );
        $this->runner->expects($this->once())->method('run')
            ->with(4, 9, 'v2', SavedTask::TRIGGER_FILE_EVENT, $this->anything());

        $service = $this->service();
        $service->check($watch, 1000);
        $service->check($watch, 2000);
        self::assertNull($watch->getChangedAt());
        $service->check($watch, 3000);

        self::assertSame(3000, $watch->getChangedAt());
        self::assertSame('b', $watch->getVersion());
    }

    public function testAFailedCheckEndsWithAReasonAndTheNextTry(): void
    {
        $watch = new NotionWatch(4, '39797ad6-0eb3-806d-b59e-ef7653838664', 'https://www.notion.so/x', NotionWatch::ACTION_SEARCHABLE);
        $this->gateway->method('call')->willThrowException(new McpClientException('401'));

        $this->service()->check($watch, 1000);

        self::assertSame('Synaplan could not open this page in Notion. It tries again in 15 minutes.', $watch->getLastError());
        self::assertSame(1000 + NotionWatchService::INTERVAL_SECONDS, $watch->getNextCheckAt());
    }

    public function testRejectsALinkThatIsNotANotionPage(): void
    {
        $this->expectException(NotionActionException::class);
        $this->expectExceptionMessage('Paste the link of a Notion page. Nothing is followed yet.');

        $this->service()->create(4, 'https://example.com/page', NotionWatch::ACTION_SEARCHABLE, null, null);
    }

    public function testATaskWatchNeedsATaskThePersonStartsThemselves(): void
    {
        $this->tasks->method('findByIdAndOwner')->willReturn(null);

        $this->expectException(NotionActionException::class);
        $this->expectExceptionMessage('Pick a saved task you start yourself. Nothing is followed yet.');

        $this->service()->create(4, self::PAGE, NotionWatch::ACTION_TASK, null, 9);
    }

    private function page(string $title, string $edited): void
    {
        $this->gateway->method('call')->willReturn(['title' => $title, 'text' => 'Body', 'page_last_edited_at' => $edited]);
    }

    private function service(): NotionWatchService
    {
        $watches = $this->createStub(NotionWatchRepository::class);
        $watches->method('findOneByPage')->willReturn(null);
        $watches->method('countByUser')->willReturn(0);
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturn(new User());

        return new NotionWatchService($watches, $this->gateway, $this->indexer, $this->tasks, $this->runner, $users, new NullLogger());
    }
}
