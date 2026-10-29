<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\Entity\Config;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Service\Model\Exception\InvalidModelSelectionException;
use App\Service\ModelConfigService;
use App\Service\Research\Run\ResearchConfig;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The routing and research model overrides chosen on the AI Models page.
 *
 * - Planner (DEFAULTMODEL.PLAN) breaks complex requests into a task graph.
 *   Per person, then the instance row (#1143).
 * - Research reading (DEFAULTMODEL.READ) and checking (DEFAULTMODEL.VERIFY)
 *   let a person override the models that read sources and verify statements
 *   during a research run. When unset, research falls back to the Sorting /
 *   Text Analytics models automatically.
 * - Summary (DEFAULTMODEL.SUMMARIZE) condenses older turns of long chats and
 *   backs widget titles and document summaries. Instance-wide only: it runs
 *   server-side for every chat, so it wants one small, fast model.
 */
final readonly class RoutingModelsService
{
    private const PLANNER = 'PLAN';
    private const SUMMARY = 'SUMMARIZE';
    private const READING = 'READ';
    private const CHECKING = 'VERIFY';

    public function __construct(
        private EntityManagerInterface $em,
        private ConfigRepository $configRepository,
        private ModelRepository $modelRepository,
        private ModelConfigService $modelConfigService,
        private ?ResearchConfig $researchConfig = null,
    ) {
    }

    /**
     * @return array{
     *     modelId: ?int,
     *     fallbackModelId: ?int,
     *     researchEnabled: bool,
     *     readingModelId: ?int,
     *     checkingModelId: ?int
     * }
     */
    public function plannerSelection(int $userId): array
    {
        $config = $this->storedRow($userId, self::PLANNER) ?? $this->storedRow(0, self::PLANNER);
        $reading = $this->storedRow($userId, self::READING) ?? $this->storedRow(0, self::READING);
        $checking = $this->storedRow($userId, self::CHECKING) ?? $this->storedRow(0, self::CHECKING);

        return [
            'modelId' => $this->activeModelId($config),
            'fallbackModelId' => $this->modelConfigService->getDefaultModel('SORT', $userId),
            'researchEnabled' => $this->researchConfig?->isEnabled() ?? false,
            'readingModelId' => $this->activeModelId($reading),
            'checkingModelId' => $this->activeModelId($checking),
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
     * Store the person's planner and/or research model overrides; null removes an override.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{modelId: ?int, readingModelId: ?int, checkingModelId: ?int}
     *
     * @throws InvalidModelSelectionException
     */
    public function saveAgentModels(int $userId, array $payload): array
    {
        $slots = [
            'modelId' => self::PLANNER,
            'readingModelId' => self::READING,
            'checkingModelId' => self::CHECKING,
        ];

        $validated = [];
        foreach ($slots as $key => $setting) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }
            $validated[$setting] = $this->validateModelId($payload[$key]);
        }

        foreach ($validated as $setting => $modelId) {
            $this->writeSlot($userId, $setting, $modelId);
        }

        $selection = $this->plannerSelection($userId);

        return [
            'modelId' => array_key_exists(self::PLANNER, $validated) ? $validated[self::PLANNER] : $selection['modelId'],
            'readingModelId' => array_key_exists(self::READING, $validated) ? $validated[self::READING] : $selection['readingModelId'],
            'checkingModelId' => array_key_exists(self::CHECKING, $validated) ? $validated[self::CHECKING] : $selection['checkingModelId'],
        ];
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
        $modelId = $this->validateModelId($raw);
        $this->writeSlot($ownerId, $setting, $modelId);

        return $modelId;
    }

    /**
     * @throws InvalidModelSelectionException
     */
    private function validateModelId(mixed $raw): ?int
    {
        if (null === $raw) {
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

        return $modelId;
    }

    private function writeSlot(int $ownerId, string $setting, ?int $modelId): void
    {
        $existing = $this->storedRow($ownerId, $setting);
        if (null === $modelId) {
            if ($existing) {
                $this->em->remove($existing);
                $this->em->flush();
            }

            return;
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
