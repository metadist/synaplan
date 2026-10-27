<?php

declare(strict_types=1);

namespace App\Service\Mcp\Notion;

use App\Entity\NotionWatch;
use App\Entity\SavedTask;
use App\Entity\User;
use App\Repository\NotionWatchRepository;
use App\Repository\SavedTaskRepository;
use App\Repository\UserRepository;
use App\Service\Mcp\McpClientException;
use App\Service\SavedTask\SavedTaskRunner;
use Psr\Log\LoggerInterface;

/**
 * Notion pages a person follows. A change either refreshes the searchable
 * copy in a knowledge folder or starts one of their saved tasks.
 */
final readonly class NotionWatchService
{
    public const MAX_PER_USER = 20;
    public const INTERVAL_SECONDS = 900;
    public const DEFAULT_FOLDER = 'Notion';
    private const MAX_TASK_TEXT = 20000;
    private const MAX_FOLDER = 64;

    public function __construct(
        private NotionWatchRepository $watches,
        private NotionGateway $gateway,
        private NotionPageIndexer $indexer,
        private SavedTaskRepository $tasks,
        private SavedTaskRunner $runner,
        private UserRepository $users,
        private LoggerInterface $logger,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function list(int $userId): array
    {
        return array_map(fn (NotionWatch $watch): array => $this->present($watch), $this->watches->findByUser($userId));
    }

    /** @return list<array{id: int, name: string}> */
    public function tasks(int $userId): array
    {
        $out = [];
        foreach ($this->tasks->findManual($userId) as $task) {
            $out[] = ['id' => (int) $task->getId(), 'name' => $task->getName()];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function create(int $userId, string $page, string $action, ?string $folder, ?int $taskId): array
    {
        if (null === $this->gateway->readable($userId)) {
            throw new NotionActionException('Connect Notion first. Nothing is followed yet.');
        }
        $pageId = NotionGateway::pageId($page);
        if (null === $pageId) {
            throw new NotionActionException('Paste the link of a Notion page. Nothing is followed yet.');
        }
        if (!in_array($action, NotionWatch::ACTIONS, true)) {
            throw new NotionActionException('Choose what Synaplan does when the page changes. Nothing is followed yet.');
        }
        if (null !== $this->watches->findOneByPage($userId, $pageId)) {
            throw new NotionActionException('You already follow this page. Change it in the list below.');
        }
        if ($this->watches->countByUser($userId) >= self::MAX_PER_USER) {
            throw new NotionActionException(sprintf('You can follow up to %d pages. Remove one first. Nothing is followed yet.', self::MAX_PER_USER));
        }

        $watch = new NotionWatch($userId, $pageId, NotionGateway::pageUrl($pageId), $action);
        if (NotionWatch::ACTION_TASK === $action) {
            $watch->setTaskId((int) $this->requireTask($userId, (int) $taskId)->getId());
        } else {
            $watch->setGroupKey($this->folder($folder));
        }
        $this->watches->save($watch);
        $this->check($watch, time());

        return $this->present($watch);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function setEnabled(int $userId, int $id, bool $enabled): ?array
    {
        $watch = $this->watches->findOneForUser($id, $userId);
        if (null === $watch) {
            return null;
        }
        $watch->setEnabled($enabled);
        $this->watches->save($watch);
        if ($enabled) {
            $this->check($watch, time());
        }

        return $this->present($watch);
    }

    public function delete(int $userId, int $id): bool
    {
        $watch = $this->watches->findOneForUser($id, $userId);
        if (null === $watch) {
            return false;
        }
        $this->watches->remove($watch);

        return true;
    }

    public function pollDue(int $now, int $limit): int
    {
        $checked = 0;
        foreach ($this->watches->findDue($now, $limit) as $watch) {
            $this->check($watch, $now);
            ++$checked;
        }

        return $checked;
    }

    /**
     * Always ends in a recorded check or a recorded reason, so the list never
     * shows a page that is checked "forever".
     */
    public function check(NotionWatch $watch, int $now): void
    {
        $next = $now + self::INTERVAL_SECONDS;
        try {
            $changed = $this->checkOnce($watch);
            $watch->recordCheck($now, $next, $changed);
        } catch (NotionActionException $e) {
            $watch->recordFailure($now, $next, $e->getMessage());
        } catch (McpClientException $e) {
            $this->logger->info('Notion watch check failed', ['watch' => $watch->getId(), 'error' => $e->getMessage()]);
            $watch->recordFailure($now, $next, 'Synaplan could not open this page in Notion. It tries again in 15 minutes.');
        } catch (\Throwable $e) {
            $this->logger->warning('Notion watch check crashed', ['watch' => $watch->getId(), 'error' => $e->getMessage()]);
            $watch->recordFailure($now, $next, 'Something went wrong while checking this page. It tries again in 15 minutes.');
        }
        $this->watches->save($watch);
    }

    private function checkOnce(NotionWatch $watch): bool
    {
        $server = $this->gateway->readable($watch->getUserId());
        if (null === $server) {
            throw new NotionActionException('Notion is not connected any more. Connect it again to keep following this page.');
        }
        $page = $this->gateway->call($server, 'fetch', ['id' => $watch->getPageId()]);
        $text = is_string($page['text'] ?? null) ? $page['text'] : '';
        $title = is_string($page['title'] ?? null) && '' !== trim($page['title']) ? trim($page['title']) : 'Notion page';
        $version = is_string($page['page_last_edited_at'] ?? null) && '' !== $page['page_last_edited_at']
            ? $page['page_last_edited_at']
            : hash('sha256', NotionPageIndexer::body($text));
        $watch->setTitle(mb_substr($title, 0, 255));

        $previous = $watch->getVersion();
        if ($previous === $version && (NotionWatch::ACTION_TASK === $watch->getAction() || null !== $watch->getFileId())) {
            return false;
        }

        if (NotionWatch::ACTION_SEARCHABLE === $watch->getAction()) {
            $watch->setFileId($this->indexer->index($this->owner($watch), $watch->getPageId(), $title, $text, $version, $watch->getGroupKey() ?? self::DEFAULT_FOLDER));
        } elseif (null !== $previous) {
            $this->runTask($watch, $title, $text);
        }
        $watch->setVersion($version);

        return null !== $previous;
    }

    private function runTask(NotionWatch $watch, string $title, string $text): void
    {
        $task = $this->tasks->findByIdAndOwner((int) $watch->getTaskId(), $watch->getUserId());
        if (!$task instanceof SavedTask) {
            throw new NotionActionException('The saved task for this page was deleted. Pick another one or remove this page.');
        }
        try {
            $this->runner->run($watch->getUserId(), (int) $task->getId(), mb_substr(NotionPageIndexer::body($text), 0, self::MAX_TASK_TEXT), SavedTask::TRIGGER_FILE_EVENT, [
                'notion' => ['page_id' => $watch->getPageId(), 'title' => $title, 'url' => $watch->getUrl()],
            ]);
        } catch (\RuntimeException $e) {
            $this->logger->info('Notion watch task did not start', ['watch' => $watch->getId(), 'error' => $e->getMessage()]);
            throw new NotionActionException('The page changed, but the saved task did not start. Open Saved Tasks to check it.');
        }
    }

    private function requireTask(int $userId, int $taskId): SavedTask
    {
        $task = $taskId > 0 ? $this->tasks->findByIdAndOwner($taskId, $userId) : null;
        if (!$task instanceof SavedTask || SavedTask::TRIGGER_MANUAL !== $task->getTriggerType()) {
            throw new NotionActionException('Pick a saved task you start yourself. Nothing is followed yet.');
        }

        return $task;
    }

    private function folder(?string $folder): string
    {
        $folder = trim((string) $folder);

        return '' === $folder ? self::DEFAULT_FOLDER : mb_substr($folder, 0, self::MAX_FOLDER);
    }

    private function owner(NotionWatch $watch): User
    {
        $user = $this->users->find($watch->getUserId());
        if (!$user instanceof User) {
            throw new NotionActionException('This account no longer exists.');
        }

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(NotionWatch $watch): array
    {
        $task = null === $watch->getTaskId() ? null : $this->tasks->findByIdAndOwner($watch->getTaskId(), $watch->getUserId());

        return [
            'id' => (int) $watch->getId(),
            'page_url' => $watch->getUrl(),
            'title' => '' === $watch->getTitle() ? null : $watch->getTitle(),
            'action' => $watch->getAction(),
            'folder' => $watch->getGroupKey(),
            'task_id' => $watch->getTaskId(),
            'task_name' => $task?->getName(),
            'file_id' => $watch->getFileId(),
            'enabled' => $watch->isEnabled(),
            'checked_at' => $watch->getCheckedAt(),
            'changed_at' => $watch->getChangedAt(),
            'last_error' => $watch->getLastError(),
        ];
    }
}
