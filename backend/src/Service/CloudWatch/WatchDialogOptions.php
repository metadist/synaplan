<?php

declare(strict_types=1);

namespace App\Service\CloudWatch;

use App\Entity\User;
use App\Repository\FileRepository;
use App\Repository\SavedPromptRepository;

/**
 * What the watch dialog can offer this person: their tasks, their shortcuts,
 * their knowledge folders, and the languages a translation can use.
 */
final readonly class WatchDialogOptions
{
    /** @var list<string> */
    private const LANGUAGES = ['en', 'de', 'es', 'fr', 'tr'];

    public function __construct(
        private WatchTasks $tasks,
        private SavedPromptRepository $prompts,
        private FileRepository $files,
    ) {
    }

    /**
     * @return array{
     *     tasks: list<array{id: int, name: string}>,
     *     languages: list<string>,
     *     shortcuts: list<array{id: int, name: string, command: string, body: string}>,
     *     folders: list<array{group_key: string, file_count: int}>
     * }
     */
    public function forUser(User $user): array
    {
        $ownerId = (int) $user->getId();
        $shortcuts = [];
        foreach ($this->prompts->findByUser($ownerId) as $prompt) {
            $shortcuts[] = [
                'id' => (int) $prompt->getId(),
                'name' => $prompt->getName(),
                'command' => $prompt->getCommand(),
                'body' => $prompt->getBody(),
            ];
        }
        $folders = [];
        foreach ($this->files->getGroupCountsByUser($ownerId) as $groupKey => $count) {
            $folders[] = ['group_key' => $groupKey, 'file_count' => $count];
        }

        return [
            'tasks' => $this->tasks->manual($ownerId),
            'languages' => self::LANGUAGES,
            'shortcuts' => $shortcuts,
            'folders' => $folders,
        ];
    }
}
