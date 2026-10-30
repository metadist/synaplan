<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\AI\Interface\ProviderMetadataInterface;
use App\AI\Service\ProviderRegistry;
use App\Entity\Config;
use App\Entity\GroupConfig;
use App\Entity\Model;
use App\Model\ModelCatalog;
use App\Plug\Rerank\Adapter\HttpRerankAdapter;
use App\Repository\ConfigRepository;
use App\Repository\GroupConfigRepository;
use App\Repository\ModelHealthRepository;
use App\Repository\ModelRepository;
use App\Service\Iam\Policy\GroupPolicyService;
use App\Service\ModelConfigService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Moves every default that points at a model an admin is about to delete.
 *
 * Embedding defaults are cleared and never reassigned: switching that model
 * rebuilds the search index, and that choice already has its own screen.
 * The same applies to the smart-search embedding slot.
 */
final readonly class DefaultModelMover
{
    /** @var list<string> */
    private const EMBEDDING_SETTINGS = ['VECTORIZE', 'EMBEDDING', 'SYNAPSE_VECTORIZE', 'SEARCH_EMBED'];

    public function __construct(
        private ModelRepository $models,
        private ConfigRepository $configs,
        private GroupConfigRepository $groupConfigs,
        private ModelHealthRepository $health,
        private ModelConfigService $usable,
        private EntityManagerInterface $em,
        private ?GroupPolicyService $groupPolicy = null,
        private ?ProviderRegistry $providers = null,
        private ?HttpRerankAdapter $rerank = null,
    ) {
    }

    /**
     * @return array{
     *     moved: list<array{setting: string, scope: string, ownerId: int, modelId: int, modelName: string}>,
     *     cleared: list<array{setting: string, scope: string, ownerId: int}>
     * }
     */
    public function release(Model $model): array
    {
        $id = (int) $model->getId();
        $bindings = $this->bindings($id);
        $moved = [];
        $cleared = [];
        $candidates = $this->needsReplacement($bindings) ? $this->candidates() : [];
        $offline = [] === $candidates ? [] : array_flip($this->offlineIds());
        $available = [] === $candidates ? null : $this->availableProviderNames();

        foreach ($bindings as $binding) {
            $setting = $binding['setting'];
            $ownerId = $binding['ownerId'];
            $scope = $binding['scope'];
            if (in_array($setting, self::EMBEDDING_SETTINGS, true)) {
                $this->em->remove($binding['row']);
                $cleared[] = ['setting' => $setting, 'scope' => $scope, 'ownerId' => $ownerId];
                continue;
            }

            $replacement = $this->replacement($candidates, $model, $setting, $ownerId, $scope, $offline, $id, $available);
            if (null === $replacement) {
                $this->em->remove($binding['row']);
                $cleared[] = ['setting' => $setting, 'scope' => $scope, 'ownerId' => $ownerId];
                continue;
            }

            $binding['row']->setValue((string) $replacement->getId());
            $moved[] = [
                'setting' => $setting,
                'scope' => $scope,
                'ownerId' => $ownerId,
                'modelId' => (int) $replacement->getId(),
                'modelName' => $replacement->getName(),
            ];
        }

        return ['moved' => $moved, 'cleared' => $cleared];
    }

    /**
     * @return list<array{setting: string, scope: string, ownerId: int, row: Config|GroupConfig}>
     */
    private function bindings(int $id): array
    {
        $bindings = [];
        /** @var list<Config> $rows */
        $rows = $this->configs->findBy(['group' => 'DEFAULTMODEL']);
        foreach ($rows as $row) {
            if ($this->storedId($row->getValue()) !== $id) {
                continue;
            }
            $bindings[] = [
                'setting' => $row->getSetting(),
                'scope' => 'global',
                'ownerId' => $row->getOwnerId(),
                'row' => $row,
            ];
        }

        /** @var list<GroupConfig> $groupRows */
        $groupRows = $this->groupConfigs->findBy(['group' => 'DEFAULTMODEL']);
        foreach ($groupRows as $row) {
            if ($this->storedId($row->getValue()) !== $id) {
                continue;
            }
            $bindings[] = [
                'setting' => $row->getSetting(),
                'scope' => 'group',
                'ownerId' => $row->getGroupId(),
                'row' => $row,
            ];
        }

        return $bindings;
    }

    private function storedId(string $raw): ?int
    {
        $raw = trim($raw);
        if ('' === $raw) {
            return null;
        }
        if (is_numeric($raw)) {
            $parsed = (int) $raw;

            return 0 !== $parsed ? $parsed : null;
        }

        return $this->groupPolicy?->modelIdFromStored($raw);
    }

    /**
     * @param list<array{setting: string, scope: string, ownerId: int, row: Config|GroupConfig}> $bindings
     */
    private function needsReplacement(array $bindings): bool
    {
        foreach ($bindings as $binding) {
            if (!in_array($binding['setting'], self::EMBEDDING_SETTINGS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Model>
     */
    private function candidates(): array
    {
        /** @var list<Model> $models */
        $models = $this->models->findBy(['active' => 1]);

        return $models;
    }

    /**
     * @param list<Model>       $candidates
     * @param array<int, mixed> $offline
     * @param list<string>|null $available  lowercased provider names, or null when the registry is not injected
     */
    private function replacement(array $candidates, Model $deleted, string $setting, int $ownerId, string $scope, array $offline, int $deletedId, ?array $available): ?Model
    {
        $same = [];
        $other = [];
        foreach ($candidates as $candidate) {
            $candidateId = (int) $candidate->getId();
            if ($candidateId === $deletedId || $candidateId < 1 || !$this->fits($candidate, $setting)) {
                continue;
            }
            if (!$this->offeredFor($candidate, $setting)) {
                continue;
            }
            if ($candidate->isRetired() || $candidate->isHiddenBecauseFree() || isset($offline[$candidateId])) {
                continue;
            }
            if (!$this->providerReady($candidate, $available) || !$this->usable->isConfiguredModelUsable($candidateId)) {
                continue;
            }
            if ('global' === $scope && $ownerId > 0 && null !== $this->groupPolicy && !$this->groupPolicy->isModelAllowed($ownerId, $candidateId, $setting)) {
                continue;
            }
            if ('group' === $scope && null !== $this->groupPolicy && !$this->groupPolicy->isModelAllowedForGroup($ownerId, $candidateId)) {
                continue;
            }

            if (0 === strcasecmp($candidate->getService(), $deleted->getService())) {
                $same[] = $candidate;
            } else {
                $other[] = $candidate;
            }
        }

        $byId = static fn (Model $a, Model $b): int => ((int) $a->getId()) <=> ((int) $b->getId());
        usort($same, $byId);
        usort($other, $byId);

        return $same[0] ?? $other[0] ?? null;
    }

    private function offeredFor(Model $candidate, string $setting): bool
    {
        if (1 !== $candidate->getActive()) {
            return false;
        }
        if (1 === $candidate->getSelectable()) {
            return true;
        }

        return 'MEM' === $setting && 'mem' === $this->canonicalTag($candidate->getTag());
    }

    /**
     * A provider that reports itself unavailable is not a replacement, even
     * when the usable-provider list is empty and the shared check cannot tell.
     * Rerank rows are outside that list; their key and endpoint decide.
     */
    /**
     * @return list<string>|null null when no registry is injected, so unit tests keep stubbing usability alone
     */
    private function availableProviderNames(): ?array
    {
        if (null === $this->providers) {
            return null;
        }

        $available = [];
        foreach ($this->providers->getUniqueProviders() as $provider) {
            if (!$provider instanceof ProviderMetadataInterface || !$provider->isAvailable()) {
                continue;
            }
            $available[] = strtolower($provider->getName());
        }

        return $available;
    }

    /**
     * A provider that reports itself unavailable is not a replacement, even
     * when the usable-provider list is empty and the shared check cannot tell.
     * Rerank rows are outside that list; their key and endpoint decide.
     *
     * @param list<string>|null $available
     */
    private function providerReady(Model $candidate, ?array $available): bool
    {
        if ('rerank' === $this->canonicalTag($candidate->getTag())) {
            return null !== $this->rerank && $this->rerank->healthForModel($candidate)->available;
        }
        if (null === $available) {
            return true;
        }
        if ([] === $available) {
            return false;
        }

        return in_array(strtolower($candidate->getService()), $available, true);
    }

    private function fits(Model $candidate, string $setting): bool
    {
        $expected = $this->expectedTag($setting);
        if (null === $expected || $this->canonicalTag($candidate->getTag()) !== $expected) {
            return false;
        }

        $features = $candidate->getFeatures();
        if ('PIC2PIC' === $setting && !in_array('pic2pic', $features, true)) {
            return false;
        }
        if ('IMG2VID' === $setting && !in_array('image2video', $features, true)) {
            return false;
        }
        if ('TEXT2VID' === $setting && in_array('image2video', $features, true)) {
            return false;
        }

        return true;
    }

    private function expectedTag(string $setting): ?string
    {
        return match ($setting) {
            'SEARCH' => 'chat',
            'MEM' => 'mem',
            default => ModelCatalog::CAPABILITY_TAGS[$setting] ?? null,
        };
    }

    /**
     * Picker aliases (VISION, IMAGE, VIDEO, AUDIO, TTS) name the same job as
     * the catalog tag the default slot stores.
     */
    private function canonicalTag(string $tag): string
    {
        return match (strtolower($tag)) {
            'vision' => 'pic2text',
            'image' => 'text2pic',
            'video' => 'text2vid',
            'audio', 'transcription' => 'sound2text',
            'tts' => 'text2sound',
            'embedding' => 'vectorize',
            default => strtolower($tag),
        };
    }

    /**
     * @return list<int>
     */
    private function offlineIds(): array
    {
        try {
            return $this->health->findOfflineModelIds();
        } catch (\Throwable) {
            return [];
        }
    }
}
