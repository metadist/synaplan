<?php

declare(strict_types=1);

namespace App\Service\Iam\ResourceKind;

use App\Entity\Prompt;
use App\Repository\PromptRepository;
use App\Service\Iam\Permission;

/**
 * Assistant identity is BPROMPTS.BID. System prompts (BOWNERID = 0) are
 * never shareable; everyone may already read and use them.
 */
final readonly class AssistantKind implements ShareableResourceKindInterface
{
    public const KEY = 'assistant';

    public function __construct(
        private PromptRepository $promptRepository,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function ownerId(string $resourceId): ?int
    {
        $prompt = $this->findPrompt($resourceId);
        if (null === $prompt) {
            return null;
        }

        return $prompt->getOwnerId();
    }

    public function describe(string $resourceId): ResourceCard
    {
        $prompt = $this->findPrompt($resourceId);
        if (null === $prompt) {
            return new ResourceCard($resourceId, $resourceId, 'assistant');
        }

        $name = $prompt->getShortDescription();
        if ('' === $name) {
            $name = $prompt->getTopic();
        }

        return new ResourceCard(
            (string) $prompt->getId(),
            $name,
            'assistant',
            ['ownerId' => $prompt->getOwnerId(), 'topic' => $prompt->getTopic()],
        );
    }

    public function listOwnedBy(int $userId): iterable
    {
        foreach ($this->promptRepository->findBy(['ownerId' => $userId], ['topic' => 'ASC']) as $prompt) {
            $name = $prompt->getShortDescription();
            yield new ResourceCard(
                (string) $prompt->getId(),
                '' !== $name ? $name : $prompt->getTopic(),
                'assistant',
                ['ownerId' => $prompt->getOwnerId(), 'topic' => $prompt->getTopic()],
            );
        }
    }

    public function onShareChanged(string $resourceId): void
    {
    }

    public function supportedPermissions(): array
    {
        return [Permission::Read, Permission::Use, Permission::Edit];
    }

    public static function knowledgeFolder(string $topic): string
    {
        return 'TASKPROMPT:'.$topic;
    }

    private function findPrompt(string $resourceId): ?Prompt
    {
        if ('' === $resourceId || !ctype_digit($resourceId)) {
            return null;
        }
        $prompt = $this->promptRepository->find((int) $resourceId);

        return $prompt instanceof Prompt ? $prompt : null;
    }
}
