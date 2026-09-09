<?php

declare(strict_types=1);

namespace App\Bundle\Section;

use App\Bundle\BundleScope;
use App\Bundle\BundleSectionInterface;
use App\Bundle\ChecklistItem;
use App\Bundle\ImportOptions;
use App\Bundle\SectionPreview;
use App\Bundle\SectionResult;
use App\Entity\Agent;
use App\Entity\User;
use App\Model\ModelCatalog;
use App\Repository\AgentRepository;
use App\Repository\McpServerConfigRepository;
use App\Repository\UserRepository;
use App\Service\Agent\AgentConfig;
use App\Service\Agent\AgentService;
use App\Service\Agent\Definition\AgentDefinition;
use App\Service\Agent\Definition\AgentDefinitionValidator;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.bundle.section')]
final readonly class AgentBundleSection implements BundleSectionInterface
{
    public function __construct(
        private AgentRepository $agents,
        private AgentService $agentService,
        private AgentConfig $agentConfig,
        private AgentDefinitionValidator $validator,
        private McpServerConfigRepository $mcpServers,
        private UserRepository $users,
    ) {
    }

    public function kind(): string
    {
        return 'agents';
    }

    public function version(): int
    {
        return 1;
    }

    public function isAvailable(?int $userId, BundleScope $scope): bool
    {
        return $this->agentConfig->isEnabled($userId);
    }

    public function dependsOn(): array
    {
        return ['prompts'];
    }

    public function export(int $userId, BundleScope $scope, array $include = []): array
    {
        $slugFilter = [];
        $raw = $include['agents'] ?? null;
        if (is_array($raw)) {
            foreach ($raw as $slug) {
                if (is_string($slug) && '' !== $slug) {
                    $slugFilter[$slug] = true;
                }
            }
        }

        $items = [];
        $exportedSlugs = [];
        foreach ($this->agents->findPublishedByOwner($userId) as $agent) {
            if ([] !== $slugFilter && !isset($slugFilter[$agent->getSlug()])) {
                continue;
            }
            $published = $this->agentService->publishedVersion($agent);
            if (null === $published) {
                continue;
            }
            $exportedSlugs[$agent->getSlug()] = true;
            $parent = null;
            if (null !== $agent->getParentId()) {
                $parentAgent = $this->agents->find($agent->getParentId());
                if ($parentAgent instanceof Agent && $parentAgent->getOwnerId() === $userId) {
                    $parent = $parentAgent->getSlug();
                }
            }
            $items[] = [
                'key' => $agent->getSlug(),
                'name' => $agent->getName(),
                'prompt' => $agent->getSlug(),
                'parent' => $parent,
                'icon' => $agent->getIcon(),
                'description' => $agent->getDescription(),
                'definition' => $this->stripForExport($published->getDefinition(), $userId),
                'instruction' => $published->getPromptText(),
            ];
        }

        foreach ($items as &$item) {
            $parent = $item['parent'];
            if (is_string($parent) && !isset($exportedSlugs[$parent])) {
                $item['parent'] = null;
            }
        }
        unset($item);

        return $items;
    }

    public function preview(array $items, int $userId): SectionPreview
    {
        $rows = [];
        foreach ($items as $item) {
            $key = (string) ($item['key'] ?? '');
            $definition = is_array($item['definition'] ?? null) ? $item['definition'] : [];
            $models = is_array($definition['models'] ?? null) ? $definition['models'] : [];
            foreach (['chat', 'vision', 'vectorize'] as $capability) {
                $modelKey = $models[$capability] ?? null;
                if (is_string($modelKey) && '' !== $modelKey && null === ModelCatalog::findBidByKey($modelKey)) {
                    $rows[] = new ChecklistItem('needsModel', $key, $capability);
                }
            }
            $tools = is_array($definition['tools'] ?? null) ? $definition['tools'] : [];
            $mcpNames = is_array($tools['mcpServers'] ?? null) ? $tools['mcpServers'] : [];
            $known = $this->mcpNames($userId);
            foreach ($mcpNames as $name) {
                if (is_string($name) && !isset($known[strtolower($name)])) {
                    $rows[] = new ChecklistItem('needsMcpServer', $key, $name);
                }
            }
            $triggers = is_array($definition['triggers'] ?? null) ? $definition['triggers'] : [];
            $events = is_array($triggers['events'] ?? null) ? $triggers['events'] : [];
            foreach ($events as $event) {
                if (!is_array($event)) {
                    continue;
                }
                $kind = (string) ($event['kind'] ?? '');
                if ('mail' === $kind) {
                    $rows[] = new ChecklistItem('needsMailbox', $key, null);
                }
                if ('widget' === $kind) {
                    $rows[] = new ChecklistItem('needsWidget', $key, null);
                }
                if ('whatsapp' === $kind) {
                    $rows[] = new ChecklistItem('needsWhatsapp', $key, null);
                }
            }
            $schedules = is_array($triggers['schedules'] ?? null) ? $triggers['schedules'] : [];
            if ([] !== $schedules) {
                $rows[] = new ChecklistItem('schedulesOff', $key, null);
            }
            $knowledge = is_array($definition['knowledge'] ?? null) ? $definition['knowledge'] : [];
            if (!empty($knowledge['droppedFolders'])) {
                $rows[] = new ChecklistItem('droppedFolders', $key, null);
            }
        }

        return new SectionPreview($this->kind(), $rows, count($items));
    }

    public function apply(array $items, int $userId, ImportOptions $options): SectionResult
    {
        $owner = $this->users->find($userId);
        if (!$owner instanceof User) {
            return new SectionResult($this->kind(), failed: [['key' => 'owner', 'reason' => 'user not found']]);
        }
        $created = [];
        $skipped = [];
        $failed = [];
        foreach ($items as $item) {
            $key = (string) ($item['key'] ?? '');
            try {
                $name = is_string($item['name'] ?? null) && '' !== trim($item['name']) ? trim($item['name']) : $key;
                $definition = is_array($item['definition'] ?? null) ? $item['definition'] : AgentDefinition::defaults()->toArray();
                $prepared = $this->prepareForImport($definition, $userId);
                $validated = $this->validator->validate($prepared)->toArray();
                $instruction = is_string($item['instruction'] ?? null) ? $item['instruction'] : null;
                $this->agentService->importDraft(
                    $owner,
                    $name,
                    $key,
                    $validated,
                    $instruction,
                    is_string($item['description'] ?? null) ? $item['description'] : null,
                    is_string($item['icon'] ?? null) ? $item['icon'] : null,
                );
                $created[] = $key;
            } catch (\Throwable $e) {
                $failed[] = ['key' => $key, 'reason' => $e->getMessage()];
            }
        }

        return new SectionResult($this->kind(), $created, $skipped, $failed);
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    public function stripForExport(array $definition, int $userId): array
    {
        $knowledge = is_array($definition['knowledge'] ?? null) ? $definition['knowledge'] : [];
        $folders = is_array($knowledge['folders'] ?? null) ? $knowledge['folders'] : [];
        $dropped = [] !== $folders;
        $knowledge['folders'] = [];
        unset($knowledge['droppedFolders']);
        if ($dropped) {
            $knowledge['droppedFolders'] = true;
        }
        $definition['knowledge'] = $knowledge;

        $tools = is_array($definition['tools'] ?? null) ? $definition['tools'] : [];
        $ids = is_array($tools['mcpServers'] ?? null) ? $tools['mcpServers'] : [];
        $names = [];
        $idToName = $this->mcpIdToName($userId);
        foreach ($ids as $id) {
            if (is_int($id) && isset($idToName[$id])) {
                $names[] = $idToName[$id];
            } elseif (is_string($id) && !is_numeric($id)) {
                $names[] = $id;
            } elseif (is_numeric($id) && isset($idToName[(int) $id])) {
                $names[] = $idToName[(int) $id];
            }
        }
        $tools['mcpServers'] = $names;
        $definition['tools'] = $tools;

        $triggers = is_array($definition['triggers'] ?? null) ? $definition['triggers'] : ['events' => [], 'schedules' => []];
        $events = [];
        foreach (is_array($triggers['events'] ?? null) ? $triggers['events'] : [] as $event) {
            if (!is_array($event)) {
                continue;
            }
            unset($event['mailbox'], $event['widget'], $event['number']);
            $events[] = $event;
        }
        $schedules = [];
        foreach (is_array($triggers['schedules'] ?? null) ? $triggers['schedules'] : [] as $schedule) {
            if (!is_array($schedule)) {
                continue;
            }
            $schedule['enabled'] = false;
            $schedules[] = $schedule;
        }
        $definition['triggers'] = ['events' => $events, 'schedules' => $schedules];

        return $definition;
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    private function prepareForImport(array $definition, int $userId): array
    {
        $knowledge = is_array($definition['knowledge'] ?? null) ? $definition['knowledge'] : [];
        $knowledge['folders'] = [];
        unset($knowledge['droppedFolders']);
        $definition['knowledge'] = $knowledge;

        $tools = is_array($definition['tools'] ?? null) ? $definition['tools'] : [];
        $names = is_array($tools['mcpServers'] ?? null) ? $tools['mcpServers'] : [];
        $ids = [];
        $nameToId = $this->mcpNameToId($userId);
        foreach ($names as $name) {
            if (is_string($name) && isset($nameToId[strtolower($name)])) {
                $ids[] = $nameToId[strtolower($name)];
            }
        }
        $tools['mcpServers'] = $ids;
        $definition['tools'] = $tools;

        $triggers = is_array($definition['triggers'] ?? null) ? $definition['triggers'] : ['events' => [], 'schedules' => []];
        $events = [];
        foreach (is_array($triggers['events'] ?? null) ? $triggers['events'] : [] as $event) {
            if (!is_array($event)) {
                continue;
            }
            $kind = (string) ($event['kind'] ?? '');
            if (in_array($kind, ['mail', 'widget'], true)) {
                continue;
            }
            unset($event['mailbox'], $event['widget'], $event['number']);
            $events[] = $event;
        }
        $schedules = [];
        foreach (is_array($triggers['schedules'] ?? null) ? $triggers['schedules'] : [] as $schedule) {
            if (!is_array($schedule)) {
                continue;
            }
            $schedule['enabled'] = false;
            $schedules[] = $schedule;
        }
        $definition['triggers'] = ['events' => $events, 'schedules' => $schedules];

        $models = is_array($definition['models'] ?? null) ? $definition['models'] : [];
        foreach (['chat', 'vision', 'vectorize'] as $capability) {
            $key = $models[$capability] ?? null;
            if (is_string($key) && '' !== $key && null === ModelCatalog::findBidByKey($key)) {
                $models[$capability] = null;
            }
        }
        $definition['models'] = $models;
        $definition['schema'] = $definition['schema'] ?? 'agent.v1';

        return $definition;
    }

    /**
     * @return array<int, string>
     */
    private function mcpIdToName(int $userId): array
    {
        $map = [];
        foreach ($this->mcpServers->findByUser($userId) as $server) {
            $id = $server->getId();
            if (null !== $id && '' !== $server->getName()) {
                $map[$id] = $server->getName();
            }
        }

        return $map;
    }

    /**
     * @return array<string, true>
     */
    private function mcpNames(int $userId): array
    {
        $map = [];
        foreach ($this->mcpServers->findByUser($userId) as $server) {
            if ('' !== $server->getName()) {
                $map[strtolower($server->getName())] = true;
            }
        }

        return $map;
    }

    /**
     * @return array<string, int>
     */
    private function mcpNameToId(int $userId): array
    {
        $map = [];
        foreach ($this->mcpServers->findByUser($userId) as $server) {
            $id = $server->getId();
            if (null !== $id && '' !== $server->getName()) {
                $map[strtolower($server->getName())] = $id;
            }
        }

        return $map;
    }
}
