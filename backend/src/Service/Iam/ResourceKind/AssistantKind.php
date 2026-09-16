<?php

declare(strict_types=1);

namespace App\Service\Iam\ResourceKind;

use App\Entity\Agent;
use App\Entity\Prompt;
use App\Repository\PromptRepository;
use App\Service\Iam\Exception\ShareNotAllowedException;
use App\Service\Iam\Permission;

/**
 * Instruction prompts: identity is BPROMPTS.BID. System prompts
 * (BOWNERID = 0) are never shareable; everyone may already read and use them.
 *
 * The kind split, settled in Agent Builder S3.5: `assistant` stays bound to
 * BPROMPTS (instruction prompts), `agent` ({@see AgentKind}) to BAGENTS. An
 * assistant's own instruction row (`agent:*` topic) is an implementation
 * detail of the agent — it is neither listed nor shareable through this kind;
 * recipients get it by being granted the agent.
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
            if (self::belongsToAgent($prompt)) {
                continue;
            }
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

    public function assertShareable(string $resourceId): void
    {
        $prompt = $this->findPrompt($resourceId);
        if (null !== $prompt && self::belongsToAgent($prompt)) {
            throw new ShareNotAllowedException('An assistant\'s instruction cannot be shared on its own - share the assistant instead.');
        }
    }

    public static function knowledgeFolder(string $topic): string
    {
        return 'TASKPROMPT:'.$topic;
    }

    public static function belongsToAgent(Prompt $prompt): bool
    {
        return str_starts_with($prompt->getTopic(), Agent::TOPIC_PREFIX);
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
