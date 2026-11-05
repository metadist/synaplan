<?php

declare(strict_types=1);

namespace App\Service\AgentTemplate;

use App\Entity\Agent;
use App\Entity\User;
use App\Repository\AgentRepository;
use App\Repository\UserRepository;
use App\Service\SavedTask\SavedTaskConfig;

final readonly class AgentTemplateListing
{
    public function __construct(
        private AgentTemplateCatalog $catalog,
        private AgentTemplateRequirementChecker $requirements,
        private AgentRepository $agents,
        private UserRepository $users,
        private AgentPublishReview $reviews,
        private PluginPackGallery $packs,
        private SavedTaskConfig $tasks,
    ) {
    }

    /**
     * @return array{peopleCount: int|null, publishReviewRequired: bool, templates: list<array<string, mixed>>}
     */
    public function forUser(User $user): array
    {
        $rows = [];
        foreach ($this->catalog->all() as $template) {
            $installed = $this->agents->findInstalledTemplate((int) $user->getId(), AgentTemplateRef::prefix($template->id));
            $installedVersion = $installed instanceof Agent
                ? AgentTemplateRef::versionFromSource($installed->getSource())
                : null;
            $review = $this->reviews->stateFor($user, $installed);
            $rows[] = [
                'id' => $template->id,
                'version' => $template->version,
                'titleKey' => $template->titleKey,
                'summaryKey' => $template->summaryKey,
                'category' => $template->category,
                'tryIt' => $template->tryIt,
                'creates' => [
                    'assistants' => $template->creates->assistants,
                    'prompts' => $template->creates->prompts,
                    'tasks' => $this->tasks->isEnabled((int) $user->getId()) ? $template->creates->tasks : 0,
                ],
                'requires' => $this->requirements->check($template->requires, $user, $installed),
                'usedBy' => $this->agents->countTemplateOwners(AgentTemplateRef::prefix($template->id)),
                'installedAgentId' => $installed?->getId(),
                'installedVersion' => $installedVersion,
                'updateAvailable' => null !== $installedVersion && $installedVersion !== $template->version,
                'publishReview' => $review['status'] ?? null,
                'publishReviewNote' => $review['note'] ?? null,
            ];
        }
        foreach ($this->packs->rows($user) as $row) {
            $rows[] = $row;
        }

        return [
            'peopleCount' => $user->isAdmin() ? $this->users->count([]) : null,
            'publishReviewRequired' => $this->reviews->required($user),
            'templates' => $rows,
        ];
    }
}
