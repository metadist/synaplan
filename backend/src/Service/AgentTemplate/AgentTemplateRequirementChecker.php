<?php

declare(strict_types=1);

namespace App\Service\AgentTemplate;

use App\Entity\Agent;
use App\Entity\Connection;
use App\Entity\User;
use App\Repository\ConnectionRepository;
use App\Repository\InboundEmailHandlerRepository;
use App\Repository\ModelRepository;

/**
 * Says what a template still needs on this install, for this person.
 */
final readonly class AgentTemplateRequirementChecker
{
    public function __construct(
        private ModelRepository $models,
        private ConnectionRepository $connections,
        private InboundEmailHandlerRepository $mailboxes,
    ) {
    }

    /**
     * @param list<array{kind: string, value: string|null, hintKey: string|null, optional: bool}> $requires
     *
     * @return list<array{kind: string, value: string|null, hintKey: string|null, optional: bool, state: string, action: string|null}>
     */
    public function check(array $requires, User $user, ?Agent $agent = null): array
    {
        $rows = [];
        foreach ($requires as $requirement) {
            $rows[] = $this->one($requirement, $user, $agent);
        }

        return $rows;
    }

    /**
     * @param array{kind: string, value: string|null, hintKey: string|null, optional: bool} $requirement
     *
     * @return array{kind: string, value: string|null, hintKey: string|null, optional: bool, state: string, action: string|null}
     */
    private function one(array $requirement, User $user, ?Agent $agent): array
    {
        $met = match ($requirement['kind']) {
            'capability' => $this->hasCapability((string) ($requirement['value'] ?? '')),
            'knowledgeFolder' => $this->hasKnowledge($agent),
            'connection' => $this->hasConnection((string) ($requirement['value'] ?? ''), (int) $user->getId()),
            default => false,
        };
        $unavailable = 'capability' === $requirement['kind'] && !$met;
        $state = $met ? 'ready' : ($unavailable ? 'unavailable' : 'needsYou');
        $action = $met ? null : $this->action($requirement['kind'], (string) ($requirement['value'] ?? ''), $unavailable);

        return [
            'kind' => $requirement['kind'],
            'value' => $requirement['value'],
            'hintKey' => $requirement['hintKey'],
            'optional' => $requirement['optional'],
            'state' => $state,
            'action' => $action,
        ];
    }

    private function hasCapability(string $value): bool
    {
        $tag = strtolower($value);
        if (!in_array($tag, ['chat', 'pic2text', 'vectorize'], true)) {
            return false;
        }

        return [] !== $this->models->findByTag($tag, true);
    }

    private function hasKnowledge(?Agent $agent): bool
    {
        if (!$agent instanceof Agent) {
            return false;
        }
        $knowledge = $agent->getDraft()['knowledge'] ?? [];
        if (!is_array($knowledge)) {
            return false;
        }
        $folders = $knowledge['folders'] ?? [];
        $files = $knowledge['fileIds'] ?? [];

        return (is_array($folders) && [] !== $folders) || (is_array($files) && [] !== $files);
    }

    private function hasConnection(string $value, int $userId): bool
    {
        if ('mail' !== $value) {
            return false;
        }
        if ([] !== $this->mailboxes->findByUser($userId)) {
            return true;
        }

        return $this->connections->findOneByOwnerAndType($userId, 'mailbox') instanceof Connection
            || $this->connections->findOneByOwnerAndType($userId, Connection::TYPE_M365) instanceof Connection;
    }

    private function action(string $kind, string $value, bool $unavailable): ?string
    {
        if ($unavailable) {
            return null;
        }

        return match ($kind) {
            'knowledgeFolder' => 'chooseFolder',
            'connection' => 'mail' === $value ? 'connectMail' : null,
            default => null,
        };
    }
}
