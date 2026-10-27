<?php

declare(strict_types=1);

namespace App\Service\CloudWatch;

use App\Entity\CloudWatch;
use App\Entity\ExternalIdentity;
use App\Entity\PlatformInstance;
use App\Entity\SavedTask;
use App\Entity\User;
use App\Repository\CloudWatchItemRepository;
use App\Repository\CloudWatchRepository;
use App\Repository\ExternalIdentityRepository;
use App\Repository\SavedTaskRepository;
use App\Service\File\CloudFilesConfig;
use App\Service\PlatformLink\SourceInstanceResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The person's watches on the cloud that their key belongs to.
 */
final readonly class CloudWatchService
{
    public function __construct(
        private CloudWatchRepository $watches,
        private CloudWatchItemRepository $items,
        private ExternalIdentityRepository $identities,
        private SavedTaskRepository $tasks,
        private SourceInstanceResolver $instances,
        private CloudFilesConfig $config,
        private WatchLimits $limits,
        private WatchSentence $sentences,
        private EntityManagerInterface $entityManager,
        private WatchPublisher $publisher,
    ) {
    }

    /** @return array{items: list<array<string, mixed>>, paused_count: int} */
    public function list(User $user, Request $request): array
    {
        $identity = $this->identity($user, $request);
        $rows = $this->watches->forOwnerInstance((int) $user->getId(), $identity->getInstanceId());
        $paused = 0;
        $items = [];
        foreach ($rows as $watch) {
            if (CloudWatch::REASON_IDENTITY_OFF === $watch->getReason()) {
                ++$paused;
            }
            $items[] = $this->row($watch);
        }

        return ['items' => $items, 'paused_count' => $paused];
    }

    /** @return array{tasks: list<array{id: int, name: string}>, languages: list<string>, shortcuts: list<array<string, mixed>>, folders: list<array<string, mixed>>} */
    public function options(User $user): array
    {
        $tasks = [];
        foreach ($this->tasks->findManual((int) $user->getId()) as $task) {
            $tasks[] = ['id' => (int) $task->getId(), 'name' => $task->getName()];
        }

        return [
            'tasks' => $tasks,
            'languages' => ['en', 'de', 'es', 'fr', 'tr'],
            'shortcuts' => [],
            'folders' => [],
        ];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function create(User $user, Request $request, array $body): array
    {
        $identity = $this->identity($user, $request);
        $kind = $this->text($body, 'target_kind');
        $targetId = $this->text($body, 'target_id');
        if (!in_array($kind, [CloudWatch::KIND_FOLDER, CloudWatch::KIND_FILE], true) || '' === $targetId || strlen($targetId) > 255) {
            throw new CloudWatchException(400, 'Choose a folder or a file.');
        }
        $this->guardCaps((int) $user->getId(), $identity);
        $preset = $this->text($body, 'preset');
        $watch = new CloudWatch((int) $user->getId(), $kind, $targetId);
        $watch->setIdentityId((int) $identity->getId());
        $watch->setTargetPath(substr($this->text($body, 'target_path'), 0, 255));
        $watch->setRecursive(true === ($body['recursive'] ?? false));
        $this->applyPreset($watch, $user, $preset, $body);
        $this->entityManager->persist($watch);
        $this->entityManager->flush();
        if (null !== $watch->getTaskId()) {
            $task = $this->tasks->findByIdAndOwner((int) $watch->getTaskId(), (int) $user->getId());
            if ($task instanceof SavedTask) {
                $task->setTrigger(SavedTask::TRIGGER_FILE_EVENT, ['watch_id' => $watch->getId()]);
                $this->entityManager->flush();
            }
        }
        $this->publisher->changed($watch, $identity);

        return $this->row($watch);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function update(User $user, Request $request, int $id, array $body): array
    {
        $identity = $this->identity($user, $request);
        $watch = $this->owned($user, $id);
        $state = $this->text($body, 'state');
        if (CloudWatch::STATE_PAUSED === $state) {
            if (CloudWatch::STATE_STOPPED === $watch->getState()) {
                throw new CloudWatchException(409, 'This watch has stopped.');
            }
            $watch->mark(CloudWatch::STATE_PAUSED, CloudWatch::REASON_BY_OWNER);
        } elseif (CloudWatch::STATE_ACTIVE === $state) {
            if (CloudWatch::REASON_BY_OWNER !== $watch->getReason() || CloudWatch::STATE_PAUSED !== $watch->getState()) {
                throw new CloudWatchException(409, $this->sentences->for($watch));
            }
            $watch->mark(CloudWatch::STATE_ACTIVE, null);
        } else {
            throw new CloudWatchException(400, 'Say whether the watch is paused or active.');
        }
        $this->entityManager->flush();
        $this->publisher->changed($watch, $identity);
        $row = $this->row($watch);
        $row['waiting'] = $this->items->countWaiting((int) $watch->getId());

        return $row;
    }

    /** @return array<string, mixed> */
    public function stop(User $user, Request $request, int $id): array
    {
        $identity = $this->identity($user, $request);
        $watch = $this->owned($user, $id);
        if (CloudWatch::STATE_STOPPED === $watch->getState()) {
            return $this->row($watch);
        }
        $watch->mark(CloudWatch::STATE_STOPPED, CloudWatch::REASON_BY_OWNER);
        $this->releaseTask($watch);
        $this->entityManager->flush();
        $this->publisher->changed($watch, $identity);

        return $this->row($watch);
    }

    public function open(User $user, Request $request, int $id): CloudWatch
    {
        $this->identity($user, $request);

        return $this->owned($user, $id);
    }

    /** @return list<int> */
    public function instanceWatchIds(ExternalIdentity $identity): array
    {
        return $this->watches->openIds($identity->getInstanceId());
    }

    public function platformFor(ExternalIdentity $identity): PlatformInstance
    {
        $platform = $this->entityManager->getRepository(PlatformInstance::class)->findByInstanceId($identity->getInstanceId());
        if ($platform instanceof PlatformInstance) {
            return $platform;
        }

        throw new CloudWatchException(403, 'This key is not linked to a cloud.', 'platform_disconnected');
    }

    public function identity(User $user, Request $request): ExternalIdentity
    {
        $instanceId = $this->instances->fromRequest($request) ?? '';
        foreach ($this->identities->findByUserId((int) $user->getId()) as $identity) {
            if ($identity->getInstanceId() === $instanceId && '' !== $instanceId) {
                if ($identity->isOff()) {
                    throw new CloudWatchException(403, 'Synaplan is turned off for this cloud.', 'platform_disconnected');
                }

                return $identity;
            }
        }

        throw new CloudWatchException(403, 'This key is not linked to a cloud.', 'platform_disconnected');
    }

    private function host(ExternalIdentity $identity): string
    {
        $platform = $this->entityManager->getRepository(PlatformInstance::class)->findByInstanceId($identity->getInstanceId());

        return $platform instanceof PlatformInstance ? $platform->getHost() : $identity->getInstanceId();
    }

    private function owned(User $user, int $id): CloudWatch
    {
        $watch = $this->watches->findForOwner($id, (int) $user->getId());
        if (!$watch instanceof CloudWatch) {
            throw new CloudWatchException(404, 'That watch does not exist.');
        }

        return $watch;
    }

    private function guardCaps(int $ownerId, ExternalIdentity $identity): void
    {
        $person = $this->config->watchesPerPerson();
        if ($this->watches->countOpenForOwner($ownerId) >= $person) {
            throw new CloudWatchException(409, $this->limits->personSentence($person), 'watch_cap');
        }
        $instanceCap = $this->config->watchesPerInstance();
        if ($this->watches->countOpenForInstance($identity->getInstanceId()) >= $instanceCap) {
            throw new CloudWatchException(409, $this->limits->instanceSentence($this->host($identity), $instanceCap), 'watch_cap');
        }
    }

    /** @param array<string, mixed> $body */
    private function applyPreset(CloudWatch $watch, User $user, string $preset, array $body): void
    {
        $fileSet = $this->text($body, 'file_set');
        if ('' === $fileSet) {
            $fileSet = CloudWatch::SET_NEW_FILE;
        }
        if (!in_array($fileSet, [CloudWatch::SET_NEW_FILE, CloudWatch::SET_TOGETHER, CloudWatch::SET_WHOLE_FOLDER], true)) {
            throw new CloudWatchException(400, 'Say what each run should get.');
        }
        $watch->setFileSet($fileSet);
        $watch->setWindowSec($this->window($fileSet, $body));
        $watch->setMaxFiles($this->maxFiles($body));
        if (CloudWatch::PRESET_SEARCHABLE === $preset) {
            $watch->setAction(CloudWatch::ACTION_SEARCHABLE);
            $watch->setPreset($preset);
            $watch->setEvents(['created', 'changed']);
            $path = '' !== $watch->getTargetPath() ? $watch->getTargetPath() : 'Folder';
            $watch->setGroupKey(substr($this->text($body, 'group_key') ?: 'Nextcloud · '.$path, 0, 64));

            return;
        }
        if (CloudWatch::PRESET_TASK !== $preset) {
            throw new CloudWatchException(400, 'Choose keep searchable, or one of your tasks.');
        }
        $taskId = $body['task_id'] ?? 0;
        if (!is_int($taskId) && !(is_string($taskId) && ctype_digit($taskId))) {
            throw new CloudWatchException(400, 'Choose one of your tasks.');
        }
        $task = $this->tasks->findByIdAndOwner((int) $taskId, (int) $user->getId());
        if (!$task instanceof SavedTask || SavedTask::TRIGGER_MANUAL !== $task->getTriggerType()) {
            throw new CloudWatchException(422, 'That task cannot be used. Pick a task you start yourself.');
        }
        $watch->setAction(CloudWatch::ACTION_TASK);
        $watch->setPreset($preset);
        $watch->setTaskId((int) $task->getId());
        $watch->setEvents(['created']);
    }

    /** @param array<string, mixed> $body */
    private function window(string $fileSet, array $body): int
    {
        $given = $body['window_sec'] ?? null;
        $value = is_int($given) ? $given : (CloudWatch::SET_WHOLE_FOLDER === $fileSet ? 3600 : 600);
        $min = CloudWatch::SET_WHOLE_FOLDER === $fileSet ? 600 : 60;
        if (CloudWatch::SET_NEW_FILE === $fileSet) {
            return 0;
        }
        if ($value < $min || $value > 86400) {
            throw new CloudWatchException(400, 'The collection window is out of range.');
        }

        return $value;
    }

    /** @param array<string, mixed> $body */
    private function maxFiles(array $body): int
    {
        $given = $body['max_files'] ?? 50;
        $value = is_int($given) ? $given : 50;
        if ($value < 1 || $value > 50) {
            throw new CloudWatchException(400, 'A run can take at most 50 files.');
        }

        return $value;
    }

    private function releaseTask(CloudWatch $watch): void
    {
        $taskId = $watch->getTaskId();
        if (null === $taskId) {
            return;
        }
        $task = $this->tasks->findByIdAndOwner($taskId, $watch->getOwnerId());
        if (!$task instanceof SavedTask) {
            return;
        }
        if (CloudWatch::PRESET_TASK === $watch->getPreset()) {
            $task->setTrigger(SavedTask::TRIGGER_MANUAL, null);

            return;
        }
        $task->setEnabled(false);
    }

    /** @return array<string, mixed> */
    private function row(CloudWatch $watch): array
    {
        $taskName = null;
        if (null !== $watch->getTaskId()) {
            $task = $this->tasks->findByIdAndOwner((int) $watch->getTaskId(), $watch->getOwnerId());
            $taskName = $task instanceof SavedTask ? $task->getName() : null;
        }

        return [
            'id' => (int) $watch->getId(),
            'target_kind' => $watch->getTargetKind(),
            'target_id' => $watch->getTargetId(),
            'name' => $watch->getTargetPath(),
            'preset' => $watch->getPreset(),
            'file_set' => $watch->getFileSet(),
            'state' => $watch->getState(),
            'reason' => $watch->getReason(),
            'counts' => $watch->getCounts(),
            'last_run_at' => $watch->getLastRunAt(),
            'sentence' => $this->sentences->for($watch, $taskName),
        ];
    }

    /** @param array<string, mixed> $body */
    private function text(array $body, string $key): string
    {
        $value = $body[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }
}
