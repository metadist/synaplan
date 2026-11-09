<?php

declare(strict_types=1);

namespace App\Service\AgentTemplate;

use App\Entity\Agent;
use App\Entity\Prompt;
use App\Entity\Share;
use App\Entity\User;
use App\Repository\AgentRepository;
use App\Repository\PromptRepository;
use App\Service\Agent\AgentPublisher;
use App\Service\Agent\AgentService;
use App\Service\Agent\Exception\AgentNothingChangedException;
use App\Service\AgentTemplate\Exception\AgentTemplateForbiddenException;
use App\Service\AgentTemplate\Exception\AgentTemplateNotFoundException;
use App\Service\Iam\Exception\ShareNotAllowedException;
use App\Service\Iam\IamConfig;
use App\Service\Iam\Permission;
use App\Service\Iam\ResourceKind\AgentKind;
use App\Service\Iam\ShareService;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AgentTemplateInstaller
{
    private const INSTALL_CHANGELOG = 'Installed from a template';
    private const UPDATE_CHANGELOG = 'Template update';

    public function __construct(
        private AgentTemplateCatalog $catalog,
        private AgentTemplateModelBinder $models,
        private AgentRepository $agents,
        private AgentService $agentService,
        private AgentPublisher $publisher,
        private PromptRepository $prompts,
        private ShareService $shares,
        private IamConfig $iam,
        private EntityManagerInterface $em,
        private AgentPublishReview $reviews,
    ) {
    }

    public function install(User $user, string $id, AgentTemplateInstallRequest $request): AgentTemplateInstallResult
    {
        if ('everyone' === $request->audience && !$user->isAdmin()) {
            throw AgentTemplateForbiddenException::everyone();
        }
        $template = $this->catalog->get($id);
        $existing = $this->agents->findInstalledTemplate((int) $user->getId(), AgentTemplateRef::prefix($id));
        if ($existing instanceof Agent && null !== $existing->getId()) {
            $held = $this->holdForReview($user, $existing, $request, true);
            if ($held instanceof AgentTemplateInstallResult) {
                return $held;
            }
            $shared = 'everyone' === $request->audience && $this->share($user, $existing);

            return new AgentTemplateInstallResult($existing->getId(), true, $shared, 'everyone' === $request->audience && !$shared);
        }

        $definition = $this->withExamples(
            $this->models->bind($template->body->definition, $template->requires, (int) $user->getId()),
            $request->tryIt,
        );
        $agent = $this->agentService->importDraft(
            $user,
            $request->title ?? $template->body->name,
            $template->id,
            $definition,
            $template->body->instruction,
            $request->description ?? $template->body->description,
            $template->body->icon,
            AgentTemplateRef::source($template->id, $template->version),
        );
        $this->publish($agent, $user, self::INSTALL_CHANGELOG);
        $held = $this->holdForReview($user, $agent, $request, false);
        if ($held instanceof AgentTemplateInstallResult) {
            return $held;
        }
        $shared = 'everyone' === $request->audience && $this->share($user, $agent);
        $agentId = $agent->getId();
        if (null === $agentId) {
            throw new \RuntimeException('The assistant was created without an id.');
        }

        return new AgentTemplateInstallResult(
            $agentId,
            false,
            $shared,
            'everyone' === $request->audience && !$shared,
        );
    }

    /**
     * @return array{from: string, to: string, keepsName: true}
     */
    public function diff(User $user, string $id): array
    {
        $template = $this->catalog->get($id);
        $agent = $this->installed($user, $id);

        return [
            'from' => $this->instructionOf($agent),
            'to' => $template->body->instruction,
            'keepsName' => true,
        ];
    }

    public function update(User $user, string $id): AgentTemplateInstallResult
    {
        $template = $this->catalog->get($id);
        $agent = $this->installed($user, $id);
        $definition = $this->keepChoices(
            $this->models->bind($template->body->definition, $template->requires, (int) $user->getId()),
            $agent->getDraft(),
        );
        $this->replaceInstruction($agent, $template->body->instruction);
        $this->agentService->update($agent, ['draft' => $definition]);
        $agent->setSource(AgentTemplateRef::source($template->id, $template->version));
        $this->agents->save($agent);
        $this->publish($agent, $user, self::UPDATE_CHANGELOG);
        $agentId = $agent->getId();
        if (null === $agentId) {
            throw new \RuntimeException('The assistant has no id.');
        }

        return new AgentTemplateInstallResult($agentId, false, false, false);
    }

    private function installed(User $user, string $id): Agent
    {
        $this->catalog->get($id);
        $agent = $this->agents->findInstalledTemplate((int) $user->getId(), AgentTemplateRef::prefix($id));
        if (!$agent instanceof Agent) {
            throw AgentTemplateNotFoundException::forId($id);
        }

        return $agent;
    }

    /**
     * @param array<string, mixed> $definition
     * @param list<string>         $tryIt
     *
     * @return array<string, mixed>
     */
    private function withExamples(array $definition, array $tryIt): array
    {
        if ([] === $tryIt) {
            return $definition;
        }
        $behaviour = is_array($definition['behaviour'] ?? null) ? $definition['behaviour'] : [];
        $behaviour['starterPrompts'] = $tryIt;
        $definition['behaviour'] = $behaviour;

        return $definition;
    }

    /**
     * Keep the existing draft configuration intact during an instruction-only
     * template update, while filling any empty model slots from the user's
     * defaults when the template asks for that capability.
     *
     * @param array<string, mixed> $next
     * @param array<string, mixed> $current
     *
     * @return array<string, mixed>
     */
    private function keepChoices(array $next, array $current): array
    {
        $merged = [] !== $current ? $current : $next;
        $currentModels = is_array($merged['models'] ?? null) ? $merged['models'] : [];
        $nextModels = is_array($next['models'] ?? null) ? $next['models'] : [];
        foreach (['chat', 'vision', 'vectorize'] as $slot) {
            if ((!is_string($currentModels[$slot] ?? null) || '' === $currentModels[$slot])
                && is_string($nextModels[$slot] ?? null) && '' !== $nextModels[$slot]) {
                $currentModels[$slot] = $nextModels[$slot];
            }
        }
        $merged['models'] = $currentModels;

        return $merged;
    }

    private function replaceInstruction(Agent $agent, string $instruction): void
    {
        $prompt = $this->prompts->find($agent->getPromptId());
        if ($prompt instanceof Prompt) {
            $prompt->setPrompt($instruction);
            $this->em->persist($prompt);
            $this->em->flush();
        }
    }

    private function instructionOf(Agent $agent): string
    {
        $prompt = $this->prompts->find($agent->getPromptId());

        return $prompt instanceof Prompt ? (string) $prompt->getPrompt() : '';
    }

    private function publish(Agent $agent, User $user, string $changelog): void
    {
        try {
            $this->publisher->publish($agent, $user, $changelog);
        } catch (AgentNothingChangedException) {
        }
    }

    public function withdrawReview(User $user, string $id): void
    {
        $agent = $this->installed($user, $id);
        $this->reviews->withdraw($user, $agent);
    }

    private function holdForReview(User $user, Agent $agent, AgentTemplateInstallRequest $request, bool $already): ?AgentTemplateInstallResult
    {
        if ('everyone' !== $request->audience || !$this->reviews->required($user) || $this->reviews->sharedWithEveryone($agent)) {
            return null;
        }
        if (!$this->iam->isSharingEnabled((int) $user->getId()) || !$this->iam->canShareWithEveryone($user)) {
            return null;
        }
        $this->reviews->request($user, $agent);
        $agentId = $agent->getId();
        if (null === $agentId) {
            throw new \RuntimeException('The assistant was created without an id.');
        }

        return new AgentTemplateInstallResult($agentId, $already, false, false, true);
    }

    private function share(User $admin, Agent $agent): bool
    {
        $id = $agent->getId();
        if (null === $id || !$this->iam->isSharingEnabled((int) $admin->getId())) {
            return false;
        }
        try {
            $this->shares->grant($admin, AgentKind::KEY, (string) $id, Share::SUBJECT_EVERYONE, 0, Permission::Use->value);
        } catch (ShareNotAllowedException|\InvalidArgumentException) {
            return false;
        }

        return true;
    }
}
