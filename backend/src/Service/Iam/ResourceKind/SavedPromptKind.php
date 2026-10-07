<?php

declare(strict_types=1);

namespace App\Service\Iam\ResourceKind;

use App\Entity\SavedPrompt;
use App\Repository\SavedPromptRepository;
use App\Service\Iam\Permission;

final readonly class SavedPromptKind implements ShareableResourceKindInterface
{
    use DescribesResourcesIndividually;

    public const KEY = 'prompt';

    public function __construct(
        private SavedPromptRepository $prompts,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function ownerId(string $resourceId): ?int
    {
        return $this->find($resourceId)?->getUserId();
    }

    public function describe(string $resourceId): ResourceCard
    {
        $prompt = $this->find($resourceId);
        if (!$prompt instanceof SavedPrompt) {
            return new ResourceCard($resourceId, $resourceId, 'prompt');
        }

        return new ResourceCard((string) $prompt->getId(), $prompt->getName(), 'prompt', [
            'ownerId' => $prompt->getUserId(),
            'command' => $prompt->getCommand(),
        ]);
    }

    public function listOwnedBy(int $userId): iterable
    {
        foreach ($this->prompts->findByUser($userId) as $prompt) {
            yield new ResourceCard((string) $prompt->getId(), $prompt->getName(), 'prompt', [
                'ownerId' => $prompt->getUserId(),
                'command' => $prompt->getCommand(),
            ]);
        }
    }

    public function onShareChanged(string $resourceId, ?array $revokedSubject = null): void
    {
    }

    public function supportedPermissions(): array
    {
        return [Permission::Read, Permission::Use, Permission::Edit];
    }

    public function assertShareable(string $resourceId): void
    {
    }

    private function find(string $resourceId): ?SavedPrompt
    {
        if ('' === $resourceId || !ctype_digit($resourceId)) {
            return null;
        }
        $prompt = $this->prompts->find((int) $resourceId);

        return $prompt instanceof SavedPrompt ? $prompt : null;
    }
}
