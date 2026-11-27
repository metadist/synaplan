<?php

declare(strict_types=1);

namespace App\Service\Project\Item;

use App\Entity\Project;
use App\Entity\ProjectItem;
use App\Entity\ProjectItemLog;
use App\Repository\ProjectItemRepository;
use App\Service\Project\ProjectException;
use App\Service\Project\ProjectsConfig;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Tasks, dates, decisions, costs and notes of a project, changed by hand
 * through the API. Every change writes one log row. The caller has already
 * resolved the project for the signed-in person; an item is only ever looked
 * up inside that project and among the items that person may see.
 */
final readonly class ProjectItemService
{
    /** The kind an item may belong to, per kind (a task to its date, a cost to its decision). */
    private const PARENT_KIND = [
        ProjectItem::KIND_TASK => ProjectItem::KIND_DATE,
        ProjectItem::KIND_COST => ProjectItem::KIND_DECISION,
    ];

    /** Allowed state changes and the log action each one writes. */
    private const TRANSITIONS = [
        ProjectItem::STATE_PROPOSED => [ProjectItem::STATE_OPEN => ProjectItemLog::ACTION_ACCEPTED, ProjectItem::STATE_DROPPED => ProjectItemLog::ACTION_REJECTED],
        ProjectItem::STATE_OPEN => [ProjectItem::STATE_DONE => ProjectItemLog::ACTION_DONE, ProjectItem::STATE_DROPPED => ProjectItemLog::ACTION_DROPPED],
        ProjectItem::STATE_DONE => [ProjectItem::STATE_OPEN => ProjectItemLog::ACTION_RESTORED, ProjectItem::STATE_DROPPED => ProjectItemLog::ACTION_DROPPED],
        ProjectItem::STATE_DROPPED => [ProjectItem::STATE_OPEN => ProjectItemLog::ACTION_RESTORED],
    ];

    public function __construct(
        private ProjectItemRepository $items,
        private ProjectItemInput $input,
        private ProjectItemChanger $changer,
        private ProjectZone $zone,
        private ProjectsConfig $config,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @return list<ProjectItem>
     */
    public function list(Project $project, int $viewerId, ?string $kind, ?string $state, bool $waitingOnly): array
    {
        if (null !== $kind && !\in_array($kind, ProjectItem::KINDS, true)) {
            throw ProjectException::invalid('kind must be one of: '.implode(', ', ProjectItem::KINDS));
        }
        if (null !== $state && !\in_array($state, ProjectItem::STATES, true)) {
            throw ProjectException::invalid('state must be one of: '.implode(', ', ProjectItem::STATES));
        }
        if ($waitingOnly) {
            [$kind, $state] = [ProjectItem::KIND_TASK, ProjectItem::STATE_OPEN];
        }

        return $this->items->listVisible((int) $project->getId(), $viewerId, $kind, $state, $waitingOnly);
    }

    public function get(Project $project, string $uuid, int $viewerId): ProjectItem
    {
        $item = $this->items->findVisible((int) $project->getId(), strtolower($uuid), $viewerId);
        if (null === $item) {
            throw ProjectException::notFound('Item');
        }

        return $item;
    }

    /**
     * Something the person adds by hand is written at once (`open`).
     *
     * @param array<string, mixed> $body
     */
    public function create(Project $project, int $userId, array $body): ProjectItem
    {
        $kind = $body['kind'] ?? null;
        unset($body['kind']);
        $fields = $this->input->read($body, \is_string($kind) ? $kind : '', $this->zone->of($project), true);
        $projectId = (int) $project->getId();
        if ($this->items->countInProject($projectId) >= $this->config->maxItems()) {
            throw ProjectException::invalid('This project holds the most items it can ('.$this->config->maxItems().')');
        }
        \assert(\is_string($kind) && \is_string($fields['title']));

        $item = new ProjectItem(Uuid::v4()->toRfc4122(), $projectId, $kind, ProjectItem::STATE_OPEN, $fields['title'], ProjectItem::SOURCE_MANUAL, $userId);
        $fields['parentId'] = $this->parent($project, $kind, $fields['parentId'] ?? null, $userId);
        $this->changer->apply($item, $fields);
        $this->changer->checkTimes($item);

        $this->em->wrapInTransaction(function () use ($item, $projectId, $userId): void {
            $this->em->persist($item);
            $this->em->flush();
            $this->em->persist(new ProjectItemLog($projectId, (int) $item->getId(), ProjectItemLog::ACTION_CREATED, null, $userId));
            $this->em->flush();
        });

        return $item;
    }

    /**
     * Changes only the fields the body sends. A state change writes its own
     * action (done, dropped, restored, accepted, rejected); anything else is
     * `updated`. Moving a date moves the tasks that belong to it.
     *
     * @param array<string, mixed> $body
     */
    public function update(Project $project, string $uuid, int $userId, array $body): ProjectItem
    {
        if (\array_key_exists('kind', $body)) {
            throw ProjectException::invalid('the kind of an item cannot be changed');
        }
        $item = $this->get($project, $uuid, $userId);
        $zone = $this->zone->of($project);
        $fields = $this->input->read($body, $item->getKind(), $zone, false);
        if ([] === $fields) {
            return $item;
        }
        $action = $this->action($item, $fields['state'] ?? null);
        if (\array_key_exists('parentId', $fields)) {
            $fields['parentId'] = $this->parent($project, $item->getKind(), $fields['parentId'], $userId);
        }
        $this->changer->assertAllDayKeepsTimes($item, $fields);

        $before = ProjectItemSnapshot::of($item);
        $oldStart = $item->getStartAt();
        $this->changer->apply($item, $fields);
        $this->changer->checkTimes($item);
        $projectId = (int) $project->getId();

        $this->em->wrapInTransaction(function () use ($item, $projectId, $userId, $action, $before, $oldStart, $zone): void {
            $this->em->persist(new ProjectItemLog($projectId, (int) $item->getId(), $action, $before, $userId));
            if (ProjectItem::KIND_DATE === $item->getKind()) {
                foreach ($this->changer->moveChildren($item, $oldStart, $zone) as $child => $childBefore) {
                    $this->em->persist(new ProjectItemLog($projectId, $child, ProjectItemLog::ACTION_UPDATED, $childBefore, $userId));
                }
            }
            $this->em->flush();
        });

        return $item;
    }

    private function action(ProjectItem $item, ?string $state): string
    {
        if (null === $state || $state === $item->getState()) {
            return ProjectItemLog::ACTION_UPDATED;
        }
        $action = self::TRANSITIONS[$item->getState()][$state] ?? null;
        if (null === $action) {
            throw ProjectException::invalid('a '.$item->getState().' item cannot become '.$state);
        }

        return $action;
    }

    /**
     * The parent must be an item of the same project that the person can
     * see, of the one kind this kind may belong to.
     */
    private function parent(Project $project, string $kind, mixed $uuid, int $userId): ?int
    {
        if (null === $uuid) {
            return null;
        }
        $parent = \is_string($uuid) ? $this->items->findVisible((int) $project->getId(), $uuid, $userId) : null;
        if (null === $parent) {
            throw ProjectException::invalid('parentId is not an item of this project');
        }
        if ((self::PARENT_KIND[$kind] ?? null) !== $parent->getKind()) {
            throw ProjectException::invalid('a '.$kind.' cannot belong to a '.$parent->getKind());
        }

        return $parent->getId();
    }
}
