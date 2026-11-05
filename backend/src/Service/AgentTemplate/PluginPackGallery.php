<?php

declare(strict_types=1);

namespace App\Service\AgentTemplate;

use App\Bundle\BundleEnvelopeValidator;
use App\Entity\Agent;
use App\Entity\User;
use App\Repository\AgentRepository;
use App\Service\Agent\AgentAccess;
use App\Service\AgentTemplate\Exception\AgentTemplateForbiddenException;
use App\Service\AgentTemplate\Exception\AgentTemplateNotFoundException;
use App\Service\Iam\IamConfig;
use App\Service\Iam\Permission;
use App\Service\Plugin\PluginAgentInstaller;
use App\Service\Plugin\PluginManager;
use App\Service\Plugin\PluginManifest;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lists agent packs a plugin already declares, for the template gallery.
 *
 * Plugins still install their own assistants. This class only reads those
 * packs and, for an administrator, asks the existing installer to add one.
 * A pack that is not installed yet is hidden from everyone else, so the
 * gallery does not offer a control they cannot use.
 */
final readonly class PluginPackGallery
{
    private const PACK_KINDS = ['prompts', 'agents'];

    /** Must match the id pattern on the template routes (1 + 63). */
    public const MAX_ID_LENGTH = 64;

    private const MAX_TITLE = 80;

    private const MAX_SUMMARY = 280;

    private const MAX_PROMPTS = 4;

    public function __construct(
        private PluginManager $plugins,
        private AgentRepository $agents,
        private AgentAccess $access,
        private PluginAgentInstaller $installer,
        private IamConfig $iam,
        private BundleEnvelopeValidator $envelope,
        private LoggerInterface $logger,
        #[Autowire('%plugins_dir%')]
        private string $pluginsDir,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(User $user): array
    {
        $rows = [];
        foreach ($this->packs() as $pack) {
            $agent = $this->usable($user, $pack['pluginId'], $pack['key']);
            if (!$agent instanceof Agent && !$user->isAdmin()) {
                continue;
            }
            $rows[] = $this->row($user, $pack, $agent);
        }

        return $rows;
    }

    public function knows(string $id): bool
    {
        return null !== $this->pack($id);
    }

    public function add(User $user, string $id): AgentTemplateInstallResult
    {
        $pack = $this->pack($id);
        if (null === $pack) {
            throw AgentTemplateNotFoundException::forId($id);
        }
        if (!$user->isAdmin()) {
            throw AgentTemplateForbiddenException::everyone();
        }
        $existing = $this->usable($user, $pack['pluginId'], $pack['key']);
        if ($existing instanceof Agent && null !== $existing->getId()) {
            return new AgentTemplateInstallResult($existing->getId(), true, false, false, false);
        }

        $source = Agent::sourceForPlugin($pack['pluginId']);
        $slugs = $this->installer->enable($user, $pack['pluginId']);
        if (!in_array($pack['key'], $slugs, true)) {
            throw new \InvalidArgumentException('The plugin pack could not be added.');
        }
        $created = $this->agents->findByOwnerSlugAndSource((int) $user->getId(), $pack['key'], $source);
        $createdId = $created?->getId();
        if (null === $createdId) {
            throw new \InvalidArgumentException('The plugin pack could not be added.');
        }
        $sharingOff = !$this->iam->isSharingEnabled((int) $user->getId());

        return new AgentTemplateInstallResult($createdId, false, !$sharingOff, $sharingOff, false);
    }

    /**
     * @param array{id: string, pluginId: string, pluginName: string, version: string, key: string, title: string, summary: string, tryIt: list<string>} $pack
     *
     * @return array<string, mixed>
     */
    private function row(User $user, array $pack, ?Agent $agent): array
    {
        return [
            'id' => $pack['id'],
            'version' => $pack['version'],
            'titleKey' => '',
            'summaryKey' => '',
            'title' => $pack['title'],
            'summary' => $pack['summary'],
            'pluginName' => $pack['pluginName'],
            'category' => 'plugin',
            'tryIt' => $pack['tryIt'],
            'requires' => [],
            'usedBy' => 0,
            'installedAgentId' => $agent?->getId(),
            'installedVersion' => null,
            'updateAvailable' => false,
            'publishReview' => null,
            'publishReviewNote' => null,
            'canEdit' => $agent instanceof Agent && $this->access->can($user, $agent, Permission::Edit),
            'sharingOff' => !$this->iam->isSharingEnabled((int) $user->getId()),
        ];
    }

    private function usable(User $user, string $pluginId, string $key): ?Agent
    {
        $own = null;
        $shared = null;
        foreach ($this->agents->findBySource(Agent::sourceForPlugin($pluginId)) as $agent) {
            if ($agent->getSlug() !== $key || $agent->isArchived()) {
                continue;
            }
            if (!$this->access->can($user, $agent, Permission::Use)) {
                continue;
            }
            if ($agent->getOwnerId() === (int) $user->getId()) {
                $own = $agent;
                break;
            }
            $shared ??= $agent;
        }

        return $own ?? $shared;
    }

    /**
     * @return array{id: string, pluginId: string, pluginName: string, version: string, key: string, title: string, summary: string, tryIt: list<string>}|null
     */
    private function pack(string $id): ?array
    {
        foreach ($this->packs() as $pack) {
            if ($pack['id'] === $id) {
                return $pack;
            }
        }

        return null;
    }

    /**
     * @return list<array{id: string, pluginId: string, pluginName: string, version: string, key: string, title: string, summary: string, tryIt: list<string>}>
     */
    private function packs(): array
    {
        $packs = [];
        $seen = [];
        foreach ($this->plugins->listAvailablePlugins() as $manifest) {
            if ([] === $manifest->agentPacks) {
                continue;
            }
            $files = $this->bundleFiles($manifest);
            if ([] === $files) {
                $this->logger->warning('Plugin declares agent packs but none could be read', ['plugin' => $manifest->name]);
                continue;
            }
            foreach ($files as $path) {
                foreach ($this->items($manifest, $path) as $pack) {
                    if (isset($seen[$pack['id']])) {
                        $this->logger->warning('Plugin pack id already used', ['id' => $pack['id']]);
                        continue;
                    }
                    $seen[$pack['id']] = true;
                    $packs[] = $pack;
                }
            }
        }

        return $packs;
    }

    /**
     * @return list<array{id: string, pluginId: string, pluginName: string, version: string, key: string, title: string, summary: string, tryIt: list<string>}>
     */
    private function items(PluginManifest $manifest, string $path): array
    {
        $json = file_get_contents($path);
        if (false === $json) {
            $this->logger->warning('Plugin agent pack could not be read', ['path' => $path]);

            return [];
        }
        try {
            $envelope = $this->envelope->parse($json, self::PACK_KINDS);
        } catch (\Throwable $e) {
            $this->logger->warning('Plugin agent pack rejected', ['path' => $path, 'error' => $e->getMessage()]);

            return [];
        }

        $packs = [];
        foreach ($envelope['sections'] as $section) {
            if ('agents' !== $section['kind']) {
                continue;
            }
            foreach ($section['items'] as $item) {
                $pack = $this->packFromItem($manifest, $item);
                if (null !== $pack) {
                    $packs[] = $pack;
                }
            }
        }

        return $packs;
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array{id: string, pluginId: string, pluginName: string, version: string, key: string, title: string, summary: string, tryIt: list<string>}|null
     */
    private function packFromItem(PluginManifest $manifest, array $item): ?array
    {
        $key = is_string($item['key'] ?? null) ? trim($item['key']) : '';
        $id = $this->galleryId($manifest->name, $key);
        if (null === $id) {
            if ('' !== $key) {
                $this->logger->warning('Plugin pack id does not fit the gallery', ['plugin' => $manifest->name, 'key' => $key]);
            }

            return null;
        }
        $name = is_string($item['name'] ?? null) ? trim($item['name']) : '';
        $summary = is_string($item['description'] ?? null) ? trim($item['description']) : '';

        return [
            'id' => $id,
            'pluginId' => $manifest->name,
            'pluginName' => $this->label($manifest->name),
            'version' => $manifest->version,
            'key' => $key,
            'title' => $this->clip('' !== $name ? $name : $key, self::MAX_TITLE),
            'summary' => $this->clip($summary, self::MAX_SUMMARY),
            'tryIt' => $this->starterPrompts($item),
        ];
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return list<string>
     */
    private function starterPrompts(array $item): array
    {
        $definition = is_array($item['definition'] ?? null) ? $item['definition'] : [];
        $behaviour = is_array($definition['behaviour'] ?? null) ? $definition['behaviour'] : [];
        $prompts = is_array($behaviour['starterPrompts'] ?? null) ? $behaviour['starterPrompts'] : [];
        $out = [];
        foreach ($prompts as $prompt) {
            if (!is_string($prompt)) {
                continue;
            }
            $prompt = trim($prompt);
            if ('' === $prompt || mb_strlen($prompt) > self::MAX_SUMMARY) {
                continue;
            }
            $out[] = $prompt;
            if (count($out) >= self::MAX_PROMPTS) {
                break;
            }
        }

        return $out;
    }

    private function galleryId(string $pluginId, string $key): ?string
    {
        $plugin = str_replace('_', '-', strtolower($pluginId));
        $agent = str_replace('_', '-', strtolower($key));
        if (1 !== preg_match('/^[a-z0-9][a-z0-9-]*$/', $plugin) || 1 !== preg_match('/^[a-z0-9][a-z0-9-]*$/', $agent)) {
            return null;
        }
        $id = 'plugin-'.$plugin.'-'.$agent;
        if (strlen($id) > self::MAX_ID_LENGTH) {
            return null;
        }

        return $id;
    }

    private function label(string $pluginId): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $pluginId));
    }

    private function clip(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $max - 1)).'…';
    }

    /**
     * @return list<string>
     */
    private function bundleFiles(PluginManifest $manifest): array
    {
        $root = rtrim($this->pluginsDir, '/').'/'.$manifest->name;
        $realRoot = realpath($root);
        if (false === $realRoot || !is_dir($realRoot)) {
            return [];
        }
        $files = [];
        foreach ($manifest->agentPacks as $glob) {
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
}
