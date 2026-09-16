<?php

declare(strict_types=1);

namespace App\Service\Plugin;

use App\Bundle\BundleEnvelopeValidator;
use App\Entity\Agent;
use App\Entity\Prompt;
use App\Entity\Share;
use App\Entity\User;
use App\Repository\AgentRepository;
use App\Repository\PromptRepository;
use App\Service\Agent\AgentPublisher;
use App\Service\Agent\AgentService;
use App\Service\Agent\Definition\AgentDefinition;
use App\Service\Agent\Definition\AgentDefinitionValidator;
use App\Service\Agent\Exception\AgentNothingChangedException;
use App\Service\Iam\Exception\ShareNotAllowedException;
use App\Service\Iam\IamConfig;
use App\Service\Iam\Permission;
use App\Service\Iam\ResourceKind\AgentKind;
use App\Service\Iam\ShareService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Installs `provides.agents` packs when an administrator enables a plugin.
 *
 * Each pack file is a `synaplan-bundle.v1` document restricted to the
 * `agents` and `prompts` sections. Items are imported as the enabling admin
 * with `BSOURCE = plugin:<id>`, published, and shared with everyone (`use`)
 * — the one import path that does create a share, because the admin
 * explicitly enabled the plugin. Re-enabling with a changed file publishes
 * a new version of the same assistant instead of creating another one.
 */
final readonly class PluginAgentInstaller
{
    /** Section kinds a plugin pack may carry. */
    private const PACK_KINDS = ['prompts', 'agents'];

    private const PUBLISH_CHANGELOG = 'Plugin pack';

    public function __construct(
        private PluginManager $plugins,
        private AgentRepository $agents,
        private AgentService $agentService,
        private AgentPublisher $publisher,
        private AgentDefinitionValidator $validator,
        private PromptRepository $prompts,
        private ShareService $shares,
        private IamConfig $iam,
        private EntityManagerInterface $em,
        private BundleEnvelopeValidator $envelope,
        private LoggerInterface $logger,
        #[Autowire('%plugins_dir%')]
        private string $pluginsDir,
    ) {
    }

    /**
     * @return list<string> slugs created or updated
     */
    public function enable(User $admin, string $pluginId): array
    {
        if (!$admin->isAdmin()) {
            return [];
        }
        $pluginId = strtolower(trim($pluginId));
        $manifest = $this->manifestOf($pluginId);
        if (null === $manifest || [] === $manifest->agentPacks) {
            return [];
        }

        $source = Agent::sourceForPlugin($pluginId);
        $slugs = [];
        foreach ($this->bundleFiles($pluginId, $manifest->agentPacks) as $path) {
            $slugs = array_merge($slugs, $this->installFile($admin, $source, $path));
        }

        return array_values(array_unique($slugs));
    }

    public function disable(User $admin, string $pluginId): void
    {
        if (!$admin->isAdmin() || !$this->iam->isSharingEnabled((int) $admin->getId())) {
            return;
        }
        $source = Agent::sourceForPlugin(strtolower(trim($pluginId)));
        foreach ($this->agents->findByOwnerAndSource((int) $admin->getId(), $source) as $agent) {
            $id = $agent->getId();
            if (null === $id) {
                continue;
            }
            try {
                $this->shares->revoke($admin, AgentKind::KEY, (string) $id, Share::SUBJECT_EVERYONE, 0);
            } catch (ShareNotAllowedException $e) {
                $this->logger->info('Plugin pack unshare skipped', ['plugin' => $pluginId, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * @param list<string> $globs
     *
     * @return list<string>
     */
    private function bundleFiles(string $pluginId, array $globs): array
    {
        $root = rtrim($this->pluginsDir, '/').'/'.$pluginId;
        $realRoot = realpath($root);
        if (false === $realRoot || !is_dir($realRoot)) {
            return [];
        }
        $files = [];
        foreach ($globs as $glob) {
            foreach (glob($realRoot.'/'.$glob) ?: [] as $match) {
                $real = realpath($match);
                if (false === $real || !str_starts_with($real, $realRoot.DIRECTORY_SEPARATOR) || !is_file($real)) {
                    continue;
                }
                $files[] = $real;
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * @return list<string>
     */
    private function installFile(User $admin, string $source, string $path): array
    {
        $json = (string) file_get_contents($path);
        try {
            $envelope = $this->envelope->parse($json, self::PACK_KINDS);
        } catch (\Throwable $e) {
            $this->logger->warning('Plugin agent pack rejected', ['path' => $path, 'error' => $e->getMessage()]);

            return [];
        }

        $items = [];
        foreach ($envelope['sections'] as $section) {
            if ('agents' === $section['kind']) {
                $items = $section['items'];
                break;
            }
        }

        $slugs = [];
        foreach ($items as $item) {
            $slug = $this->installItem($admin, $source, $item);
            if (null !== $slug) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function installItem(User $admin, string $source, array $item): ?string
    {
        $key = (string) ($item['key'] ?? '');
        if ('' === $key) {
            return null;
        }
        $name = is_string($item['name'] ?? null) && '' !== trim($item['name']) ? trim($item['name']) : $key;
        $definition = is_array($item['definition'] ?? null) ? $item['definition'] : AgentDefinition::defaults()->toArray();
        $instruction = is_string($item['instruction'] ?? null) ? $item['instruction'] : null;
        $description = is_string($item['description'] ?? null) ? $item['description'] : null;
        $icon = is_string($item['icon'] ?? null) ? $item['icon'] : null;
        $ownerId = (int) $admin->getId();
        $existing = $this->agents->findByOwnerSlugAndSource($ownerId, $key, $source);

        try {
            $validated = $this->validator->validate($definition)->toArray();
            if ($existing instanceof Agent) {
                $this->agentService->update($existing, [
                    'name' => $name,
                    'description' => $description,
                    'icon' => $icon ?? '',
                    'draft' => $validated,
                ]);
                $this->replaceInstruction($existing, $instruction);
                $agent = $existing;
            } else {
                $agent = $this->agentService->importDraft(
                    $admin,
                    $name,
                    $key,
                    $validated,
                    $instruction,
                    $description,
                    $icon,
                    $source,
                );
            }
            try {
                $this->publisher->publish($agent, $admin, self::PUBLISH_CHANGELOG);
            } catch (AgentNothingChangedException) {
                // Re-enable with an unchanged pack: the published version already matches.
            }
            $this->shareEveryone($admin, $agent);

            return $agent->getSlug();
        } catch (\Throwable $e) {
            $this->logger->warning('Plugin agent pack item failed', ['key' => $key, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function replaceInstruction(Agent $agent, ?string $instruction): void
    {
        if (null === $instruction || '' === trim($instruction)) {
            return;
        }
        $prompt = $this->prompts->find($agent->getPromptId());
        if ($prompt instanceof Prompt) {
            $prompt->setPrompt($instruction);
            $this->em->persist($prompt);
            $this->em->flush();
        }
    }

    private function shareEveryone(User $admin, Agent $agent): void
    {
        $id = $agent->getId();
        if (null === $id || !$this->iam->isSharingEnabled((int) $admin->getId())) {
            return;
        }
        try {
            $this->shares->grant($admin, AgentKind::KEY, (string) $id, Share::SUBJECT_EVERYONE, 0, Permission::Use->value);
        } catch (ShareNotAllowedException|\InvalidArgumentException $e) {
            $this->logger->info('Plugin pack share skipped', ['agent' => $id, 'error' => $e->getMessage()]);
        }
    }

    private function manifestOf(string $pluginId): ?PluginManifest
    {
        foreach ($this->plugins->listAvailablePlugins() as $manifest) {
            if ($manifest->name === $pluginId) {
                return $manifest;
            }
        }

        return null;
    }
}
