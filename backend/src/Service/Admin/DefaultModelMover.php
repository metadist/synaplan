<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\Config;
use App\Entity\GroupConfig;
use App\Entity\Model;
use App\Model\ModelCatalog;
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
 */
final readonly class DefaultModelMover
{
    public function __construct(
        private ModelRepository $models,
        private ConfigRepository $configs,
        private GroupConfigRepository $groupConfigs,
        private ModelHealthRepository $health,
        private ModelConfigService $usable,
        private EntityManagerInterface $em,
        private ?GroupPolicyService $groupPolicy = null,
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

        foreach ($bindings as $binding) {
            $setting = $binding['setting'];
            $ownerId = $binding['ownerId'];
            $scope = $binding['scope'];
            if ('VECTORIZE' === $setting) {
                $this->em->remove($binding['row']);
                $cleared[] = ['setting' => $setting, 'scope' => $scope, 'ownerId' => $ownerId];
                continue;
            }

            $replacement = $this->replacement($candidates, $model, $setting, $ownerId, $scope, $offline, $id);
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
            if ('VECTORIZE' !== $binding['setting']) {
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
        $models = $this->models->findBy(['active' => 1, 'selectable' => 1]);

        return $models;
    }

    /**
     * @param list<Model>       $candidates
     * @param array<int, mixed> $offline
     */
    private function replacement(array $candidates, Model $deleted, string $setting, int $ownerId, string $scope, array $offline, int $deletedId): ?Model
    {
        $same = [];
        $other = [];
        foreach ($candidates as $candidate) {
            $candidateId = (int) $candidate->getId();
            if ($candidateId === $deletedId || $candidateId < 1 || !$this->fits($candidate, $setting)) {
                continue;
            }
            if (1 !== $candidate->getSelectable() || 1 !== $candidate->getActive()) {
                continue;
            }
            if ($candidate->isRetired() || $candidate->isHiddenBecauseFree() || isset($offline[$candidateId])) {
                continue;
            }
            if (!$this->usable->isConfiguredModelUsable($candidateId)) {
                continue;
            }
            if ('global' === $scope && $ownerId > 0 && null !== $this->groupPolicy && !$this->groupPolicy->isModelAllowed($ownerId, $candidateId, $setting)) {
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

    private function fits(Model $candidate, string $setting): bool
    {
        $expected = ModelCatalog::CAPABILITY_TAGS[$setting] ?? null;
        if (null === $expected || strtolower($candidate->getTag()) !== $expected) {
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
