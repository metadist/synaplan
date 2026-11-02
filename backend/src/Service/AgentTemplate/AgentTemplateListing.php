<?php

declare(strict_types=1);

namespace App\Service\AgentTemplate;

use App\Entity\Agent;
use App\Entity\User;
use App\Repository\AgentRepository;
use App\Repository\UserRepository;

final readonly class AgentTemplateListing
{
    public function __construct(
        private AgentTemplateCatalog $catalog,
        private AgentTemplateRequirementChecker $requirements,
        private AgentRepository $agents,
        private UserRepository $users,
    ) {
    }

    /**
     * @return array{peopleCount: int|null, templates: list<array<string, mixed>>}
     */
    public function forUser(User $user): array
    {
        $rows = [];
        foreach ($this->catalog->all() as $template) {
            $installed = $this->agents->findInstalledTemplate((int) $user->getId(), AgentTemplateRef::prefix($template->id));
            $installedVersion = $installed instanceof Agent
                ? AgentTemplateRef::versionFromSource($installed->getSource())
                : null;
            $rows[] = [
                'id' => $template->id,
                'version' => $template->version,
                'titleKey' => $template->titleKey,
                'summaryKey' => $template->summaryKey,
                'category' => $template->category,
                'tryIt' => $template->tryIt,
                'requires' => $this->requirements->check($template->requires, $user, $installed),
                'usedBy' => $this->agents->countTemplateOwners(AgentTemplateRef::prefix($template->id)),
                'installedAgentId' => $installed?->getId(),
                'installedVersion' => $installedVersion,
                'updateAvailable' => null !== $installedVersion && $installedVersion !== $template->version,
            ];
        }

        return [
            'peopleCount' => $user->isAdmin() ? $this->users->count([]) : null,
            'templates' => $rows,
        ];
    }
}
