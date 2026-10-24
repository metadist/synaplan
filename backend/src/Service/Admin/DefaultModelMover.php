<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\Config;
use App\Entity\Model;
use App\Repository\ConfigRepository;
use App\Repository\ModelHealthRepository;
use App\Repository\ModelRepository;
use App\Service\Iam\Policy\GroupPolicyService;
use App\Service\Model\PickerCapabilities;
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
        private ModelHealthRepository $health,
        private EntityManagerInterface $em,
        private ?GroupPolicyService $groupPolicy = null,
    ) {
    }

    /**
     * @return array{
     *     moved: list<array{setting: string, ownerId: int, modelId: int, modelName: string}>,
     *     cleared: list<array{setting: string, ownerId: int}>
     * }
     */
    public function release(Model $model): array
    {
        $id = (int) $model->getId();
        /** @var list<Config> $rows */
        $rows = $this->configs->findBy([
            'group' => 'DEFAULTMODEL',
            'value' => (string) $id,
        ]);

        $moved = [];
        $cleared = [];
        $candidates = $this->needsReplacement($rows) ? $this->candidates() : [];
        $offline = [] === $candidates ? [] : array_flip($this->offlineIds());

        foreach ($rows as $row) {
            $setting = $row->getSetting();
            $ownerId = $row->getOwnerId();
            if ('VECTORIZE' === $setting) {
                $this->em->remove($row);
                $cleared[] = ['setting' => $setting, 'ownerId' => $ownerId];
                continue;
            }

            $replacement = $this->replacement($candidates, $model, $setting, $ownerId, $offline, $id);
            if (null === $replacement) {
                $this->em->remove($row);
                $cleared[] = ['setting' => $setting, 'ownerId' => $ownerId];
                continue;
            }

            $row->setValue((string) $replacement->getId());
            $moved[] = [
                'setting' => $setting,
                'ownerId' => $ownerId,
                'modelId' => (int) $replacement->getId(),
                'modelName' => $replacement->getName(),
            ];
        }

        return ['moved' => $moved, 'cleared' => $cleared];
    }

    /**
     * @param list<Config> $rows
     */
    private function needsReplacement(array $rows): bool
    {
        foreach ($rows as $row) {
            if ('VECTORIZE' !== $row->getSetting()) {
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
    private function replacement(array $candidates, Model $deleted, string $setting, int $ownerId, array $offline, int $deletedId): ?Model
    {
        $same = [];
        $other = [];
        foreach ($candidates as $candidate) {
            $candidateId = (int) $candidate->getId();
            if ($candidateId === $deletedId || $candidateId < 1) {
                continue;
            }
            if (1 !== $candidate->getSelectable() || 1 !== $candidate->getActive()) {
                continue;
            }
            if ($candidate->isRetired() || $candidate->isHiddenBecauseFree() || isset($offline[$candidateId])) {
                continue;
            }
            $accepted = PickerCapabilities::forTag(strtoupper($candidate->getTag()), $candidate->getFeatures());
            if (!in_array($setting, $accepted, true)) {
                continue;
            }
            if ($ownerId > 0 && null !== $this->groupPolicy && !$this->groupPolicy->isModelAllowed($ownerId, $candidateId, $setting)) {
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
