<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\Entity\Config;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Service\Model\Exception\InvalidModelSelectionException;
use App\Service\ModelConfigService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The two routing models chosen on the AI Models page. Both fall back to the
 * Sorting model (DEFAULTMODEL.SORT) when nothing usable is selected.
 *
 * - Planner (DEFAULTMODEL.PLAN) breaks complex requests into a task graph.
 *   Per person, then the instance row (#1143).
 * - Summary (DEFAULTMODEL.SUMMARIZE) condenses older turns of long chats and
 *   backs widget titles and document summaries. Instance-wide only: it runs
 *   server-side for every chat, so it wants one small, fast model.
 */
final readonly class RoutingModelsService
{
    private const PLANNER = 'PLAN';
    private const SUMMARY = 'SUMMARIZE';

    public function __construct(
        private EntityManagerInterface $em,
        private ConfigRepository $configRepository,
        private ModelRepository $modelRepository,
        private ModelConfigService $modelConfigService,
    ) {
    }

    /**
     * @return array{modelId: ?int, fallbackModelId: ?int}
     */
    public function plannerSelection(int $userId): array
    {
        $config = $this->storedRow($userId, self::PLANNER) ?? $this->storedRow(0, self::PLANNER);

        return [
            'modelId' => $this->activeModelId($config),
            'fallbackModelId' => $this->modelConfigService->getDefaultModel('SORT', $userId),
        ];
    }

    /**
     * Store the person's planner model; null removes the override.
     *
     * @throws InvalidModelSelectionException
     */
    public function savePlanner(int $userId, mixed $modelId): ?int
    {
        return $this->store($userId, self::PLANNER, $modelId);
    }

    /**
     * @return array{modelId: ?int, fallbackModelId: ?int}
     */
    public function summarySelection(): array
    {
        return [
            'modelId' => $this->activeModelId($this->storedRow(0, self::SUMMARY)),
            'fallbackModelId' => $this->modelConfigService->getDefaultModel('SORT', 0),
        ];
    }

    /**
     * Store the instance summary model; null removes it.
     *
     * @throws InvalidModelSelectionException
     */
    public function saveSummary(mixed $modelId): ?int
    {
        return $this->store(0, self::SUMMARY, $modelId);
    }

    /**
     * @throws InvalidModelSelectionException
     */
    private function store(int $ownerId, string $setting, mixed $raw): ?int
    {
        $existing = $this->storedRow($ownerId, $setting);

        if (null === $raw) {
            if ($existing) {
                $this->em->remove($existing);
                $this->em->flush();
            }

            return null;
        }

        if (!is_int($raw) && !(is_string($raw) && ctype_digit($raw))) {
            throw InvalidModelSelectionException::notAnId();
        }

        $modelId = (int) $raw;
        $model = $this->modelRepository->find($modelId);
        if (!$model || 1 !== $model->getActive()) {
            throw InvalidModelSelectionException::unavailable();
        }

        $config = $existing;
        if (!$config) {
            $config = new Config();
            $config->setOwnerId($ownerId);
            $config->setGroup('DEFAULTMODEL');
            $config->setSetting($setting);
        }
        $config->setValue((string) $modelId);
        $this->em->persist($config);
        $this->em->flush();

        return $modelId;
    }

    private function activeModelId(?Config $config): ?int
    {
        if (!$config) {
            return null;
        }

        $candidate = (int) $config->getValue();
        $model = $this->modelRepository->find($candidate);

        return ($model && 1 === $model->getActive()) ? $candidate : null;
    }

    private function storedRow(int $ownerId, string $setting): ?Config
    {
        return $this->configRepository->findOneBy([
            'ownerId' => $ownerId,
            'group' => 'DEFAULTMODEL',
            'setting' => $setting,
        ]);
    }
}
