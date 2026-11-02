<?php

declare(strict_types=1);

namespace App\Service\AgentTemplate;

use App\Entity\Agent;
use App\Entity\Connection;
use App\Entity\User;
use App\Repository\ConnectionRepository;
use App\Repository\FileRepository;
use App\Repository\InboundEmailHandlerRepository;
use App\Repository\ModelRepository;
use App\Repository\UrlWatchRepository;
use App\Repository\WidgetRepository;
use App\Service\Agent\AgentKnowledgeFolders;
use App\Service\Multitask\MultitaskRoutingConfig;
use App\Service\Research\Run\ResearchConfig;

/**
 * Says what a template still needs on this install, for this person.
 */
final readonly class AgentTemplateRequirementChecker
{
    public function __construct(
        private ModelRepository $models,
        private ConnectionRepository $connections,
        private InboundEmailHandlerRepository $mailboxes,
        private ?FileRepository $files = null,
        private ?UrlWatchRepository $watches = null,
        private ?WidgetRepository $widgets = null,
        private ?ResearchConfig $research = null,
        private ?MultitaskRoutingConfig $routing = null,
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
        $value = (string) ($requirement['value'] ?? '');
        $met = match ($requirement['kind']) {
            'capability' => $this->hasCapability($value),
            'knowledgeFolder' => $this->hasKnowledge($agent),
            'connection' => $this->hasConnection($value, (int) $user->getId()),
            'watch' => 'url' === $value && $this->hasWatchedPage((int) $user->getId()),
            'feature' => $this->hasFeature($value, $agent),
            default => false,
        };
        $unavailable = $this->isUnavailable($requirement['kind'], $value, $user, $met);
        if ($unavailable) {
            $met = false;
        }
        $state = $met ? 'ready' : ($unavailable ? 'unavailable' : 'needsYou');
        $action = $met ? null : $this->action($requirement['kind'], $value, $unavailable);

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

        foreach ($this->models->findByTag($tag, true) as $model) {
            if (1 === $model->getActive()) {
                return true;
            }
        }

        return false;
    }

    private function isUnavailable(string $kind, string $value, User $user, bool $met): bool
    {
        if ('capability' === $kind) {
            return !$met;
        }
        if ('watch' === $kind && 'url' === $value) {
            return null !== $this->routing
                && !$this->routing->isFeatureEnabled(
                    MultitaskRoutingConfig::KEY_URL_FETCH_ENABLED,
                    (int) $user->getId(),
                    true,
                );
        }
        if ('feature' === $kind && 'research' === $value) {
            return !$met;
        }

        return false;
    }

    private function hasWatchedPage(int $userId): bool
    {
        return null !== $this->watches && $this->watches->countForOwner($userId) > 0;
    }

    private function hasFeature(string $value, ?Agent $agent): bool
    {
        if ('research' === $value) {
            return null !== $this->research && $this->research->isEnabled();
        }
        if ('widget' !== $value || !$agent instanceof Agent || null === $this->widgets) {
            return false;
        }
        $agentId = $agent->getId();
        if (null === $agentId) {
            return false;
        }

        return $agent->hasPublishedVersion()
            && $this->widgets->countForOwnerAndAgent($agent->getOwnerId(), $agentId) > 0;
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
        if ((is_array($folders) && [] !== $folders) || (is_array($files) && [] !== $files)) {
            return true;
        }
        if (false !== ($knowledge['ownFolder'] ?? true) && null !== $this->files) {
            return $this->files->existsForUserAndGroupKey(
                $agent->getOwnerId(),
                AgentKnowledgeFolders::ownFolder($agent),
            );
        }

        return false;
    }

    private function hasConnection(string $value, int $userId): bool
    {
        if ('mail' !== $value) {
            return false;
        }
        if ([] !== $this->mailboxes->findActiveByUser($userId)) {
            return true;
        }
        foreach ($this->connections->findByOwner($userId) as $connection) {
            if (Connection::TYPE_M365 !== $connection->getType()) {
                continue;
            }
            if (in_array($connection->getStatus(), [Connection::STATUS_DISCONNECTED, Connection::STATUS_REAUTH_REQUIRED], true)) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function action(string $kind, string $value, bool $unavailable): ?string
    {
        if ($unavailable) {
            return null;
        }

        return match ($kind) {
            'knowledgeFolder' => 'chooseFolder',
            'connection' => 'mail' === $value ? 'connectMail' : null,
            'watch' => 'url' === $value ? 'addPage' : null,
            'feature' => 'widget' === $value ? 'createWidget' : null,
            default => null,
        };
    }
}
