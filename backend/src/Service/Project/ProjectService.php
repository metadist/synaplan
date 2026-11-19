<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\Chat;
use App\Entity\Project;
use App\Repository\ChatRepository;
use App\Repository\ProjectRepository;
use App\Service\Chat\ChatDeletionService;
use App\Service\File\FileGroupDeletionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Create, change, list and delete the projects of one account.
 */
final readonly class ProjectService
{
    public const NAME_MAX = 120;
    public const DESCRIPTION_MAX = 500;
    public const CHATS_KEEP = 'keep';
    public const CHATS_DELETE = 'delete';

    public function __construct(
        private ProjectRepository $projects,
        private ChatRepository $chats,
        private ChatDeletionService $chatDeletion,
        private FileGroupDeletionService $folders,
        private ProjectsConfig $config,
        private EntityManagerInterface $em,
    ) {
    }

    public function create(int $userId, string $name): Project
    {
        $project = new Project(
            $userId,
            $this->cleanName($name),
            $uuid = Uuid::v4()->toRfc4122(),
            'project:'.$uuid,
        );
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    /**
     * @return list<Project>
     */
    public function list(int $userId): array
    {
        return $this->projects->findByOwner($userId);
    }

    public function get(string $uuid, int $userId): Project
    {
        $project = $this->projects->findOwned($uuid, $userId);
        if (null === $project) {
            throw ProjectException::notFound('Project');
        }

        return $project;
    }

    /**
     * Only the fields the caller sends are changed (name, description, instructions, pinned). An empty description or
     * instructions text clears the field.
     *
     * @param array<string, mixed> $changes
     */
    public function update(Project $project, array $changes): Project
    {
        if (\array_key_exists('name', $changes)) {
            $project->rename($this->cleanName(\is_string($changes['name']) ? $changes['name'] : ''));
        }
        if (\array_key_exists('description', $changes)) {
            $description = $this->cleanText($changes['description'], self::DESCRIPTION_MAX, 'description');
            $project->describe($description);
        }
        if (\array_key_exists('instructions', $changes)) {
            $project->instruct($this->cleanText($changes['instructions'], $this->config->instructionsMaxChars(), 'instructions'));
        }
        if (\array_key_exists('pinned', $changes)) {
            if (!\is_bool($changes['pinned'])) {
                throw ProjectException::invalid('pinned must be true or false');
            }
            $project->pin($changes['pinned']);
        }
        $this->em->flush();

        return $project;
    }

    /**
     * Deletes the project and its sources. Its chats stay as plain chats
     * (they lose the sources) or are deleted with it.
     */
    public function delete(Project $project, string $chats): void
    {
        if (!\in_array($chats, [self::CHATS_KEEP, self::CHATS_DELETE], true)) {
            throw ProjectException::invalid('chats must be "keep" or "delete"');
        }
        $userId = $project->getUserId();
        $projectId = (int) $project->getId();

        /** @var list<Chat> $inProject */
        $inProject = $this->chats->findBy(['userId' => $userId, 'projectId' => $projectId]);
        foreach ($inProject as $chat) {
            if (self::CHATS_DELETE === $chats) {
                $this->chatDeletion->deleteOwnedChat($userId, $chat);
            } else {
                $chat->setProjectId(null);
            }
        }
        $this->em->flush();

        $this->folders->deleteGroup($userId, $project->getGroupKey());
        $this->em->remove($project);
        $this->em->flush();
    }

    private function cleanName(string $name): string
    {
        $name = trim($name);
        if ('' === $name) {
            throw ProjectException::invalid('name must not be empty');
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            throw ProjectException::invalid('name is longer than '.self::NAME_MAX.' characters');
        }

        return $name;
    }

    private function cleanText(mixed $value, int $max, string $field): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!\is_string($value)) {
            throw ProjectException::invalid($field.' must be text');
        }
        $value = trim($value);
        if (mb_strlen($value) > $max) {
            throw ProjectException::invalid($field.' is longer than '.$max.' characters');
        }

        return '' === $value ? null : $value;
    }
}
