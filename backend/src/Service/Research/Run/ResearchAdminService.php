<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Service\Iam\AuditLogWriter;
use App\Service\Model\ModelListingService;

/**
 * The research tab on AI infrastructure: the switch, the two model slots,
 * the limits an administrator can change, and one sample run.
 */
final readonly class ResearchAdminService
{
    private const SAMPLE_QUESTION = 'What is worth knowing about technology this week?';

    public function __construct(
        private ResearchConfig $config,
        private ResearchBudgetStore $budgets,
        private ConfigRepository $configRepository,
        private ModelListingService $models,
        private ResearchRunService $runs,
        private ResearchStateStore $store,
        private AuditLogWriter $audit,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(User $admin): array
    {
        $options = $this->chatOptions($admin);

        return [
            'enabled' => $this->config->isEnabled(),
            'envLocked' => null !== $this->config->envForces(),
            'readingModelId' => $this->storedModel('READ'),
            'checkingModelId' => $this->storedModel('VERIFY'),
            'models' => $options,
            'limits' => [
                'maxActiveRuns' => $this->config->maxActiveRuns(),
                'maxActiveThorough' => $this->config->maxActiveThorough(),
            ],
            'budgets' => [
                'quick' => $this->budgets->human(ResearchDepth::Quick),
                'standard' => $this->budgets->human(ResearchDepth::Standard),
                'thorough' => $this->budgets->human(ResearchDepth::Thorough),
            ],
            'sample' => $this->latestSample($admin),
        ];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function update(User $admin, array $body, string $ip): array
    {
        $this->applyEnabled($body);
        $this->applyLimits($body);
        $this->applyBudgets($body);
        if (array_key_exists('readingModelId', $body)) {
            $this->applyModel($admin, 'READ', $body['readingModelId']);
        }
        if (array_key_exists('checkingModelId', $body)) {
            $this->applyModel($admin, 'VERIFY', $body['checkingModelId']);
        }
        $this->audit->record(
            (int) $admin->getId(),
            'research.settings.update',
            'research',
            'workspace',
            ['enabled' => $this->config->isEnabled()],
            $ip,
        );

        return $this->describe($admin);
    }

    /**
     * @return array<string, mixed>
     */
    public function startSample(User $admin, string $ip): array
    {
        if (!$this->config->isEnabled()) {
            throw new ResearchRejected(409, 'research_off', null, 'Research is off. Turn it on before running a sample.');
        }
        $state = $this->runs->start($admin, [
            'question' => self::SAMPLE_QUESTION,
            'depth' => ResearchDepth::Quick->value,
            'sources' => ['web' => true, 'files' => false],
        ], false, 'admin-sample-'.$this->runsClockDay());
        $this->audit->record(
            (int) $admin->getId(),
            'research.sample.start',
            'research',
            $state->ref->id,
            ['depth' => 'quick'],
            $ip,
        );

        return [
            'started' => true,
            'id' => $state->ref->id,
            'sentence' => 'The sample has started. This page shows the result when it is done.',
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function applyEnabled(array $body): void
    {
        if (!array_key_exists('enabled', $body)) {
            return;
        }
        if (null !== $this->config->envForces()) {
            throw new ResearchRejected(409, 'env_locked', 'enabled', 'This switch is set by the server and cannot be changed here.');
        }
        if (!is_bool($body['enabled'])) {
            throw new ResearchRejected(400, 'invalid', 'enabled', 'Choose on or off.');
        }
        $this->configRepository->setValue(0, ResearchConfig::GROUP, ResearchConfig::ENABLED, $body['enabled'] ? '1' : '0');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function applyLimits(array $body): void
    {
        $limits = $body['limits'] ?? null;
        if (!is_array($limits)) {
            return;
        }
        $this->storeLimit($limits, 'maxActiveRuns', ResearchConfig::MAX_ACTIVE_RUNS, 1, 100);
        $this->storeLimit($limits, 'maxActiveThorough', ResearchConfig::MAX_ACTIVE_THOROUGH, 0, 10);
    }

    /**
     * @param array<string, mixed> $limits
     */
    private function storeLimit(array $limits, string $key, string $setting, int $min, int $max): void
    {
        if (!array_key_exists($key, $limits) || !is_numeric($limits[$key])) {
            return;
        }
        $value = max($min, min($max, (int) $limits[$key]));
        $this->configRepository->setValue(0, ResearchConfig::GROUP, $setting, (string) $value);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function applyBudgets(array $body): void
    {
        $budgets = $body['budgets'] ?? null;
        if (!is_array($budgets)) {
            return;
        }
        foreach (ResearchDepth::cases() as $depth) {
            $row = $budgets[$depth->value] ?? null;
            if (!is_array($row)) {
                continue;
            }
            $this->budgets->save(
                $depth,
                self::number($row, 'searches', 0),
                self::number($row, 'sources', 1),
                self::number($row, 'minutes', 1),
                self::decimal($row, 'costCap'),
            );
        }
    }

    private function applyModel(User $admin, string $slot, mixed $modelId): void
    {
        if (null === $modelId || '' === $modelId || (is_numeric($modelId) && (int) $modelId < 1)) {
            $this->configRepository->setValue(0, 'DEFAULTMODEL', $slot, '-1');

            return;
        }
        if (!is_numeric($modelId)) {
            throw new ResearchRejected(400, 'invalid', $slot, 'Choose a model from the list.');
        }
        $id = (int) $modelId;
        $allowed = array_column($this->chatOptions($admin), 'id');
        if (!in_array($id, $allowed, true)) {
            throw new ResearchRejected(400, 'invalid', $slot, 'That model is not available for this.');
        }
        $this->configRepository->setValue(0, 'DEFAULTMODEL', $slot, (string) $id);
    }

    /**
     * @return list<array{id: int, name: string, available: bool, reason: string|null}>
     */
    private function chatOptions(User $admin): array
    {
        $listed = $this->models->forUser($admin, true, false);
        $rows = $listed['models']['CHAT'] ?? [];
        $options = [];
        foreach ($rows as $row) {
            if (!is_numeric($row['id'] ?? null)) {
                continue;
            }
            $options[] = [
                'id' => (int) $row['id'],
                'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
                'available' => true === ($row['available'] ?? false),
                'reason' => is_string($row['unavailableReason'] ?? null) ? $row['unavailableReason'] : null,
            ];
        }

        return $options;
    }

    private function storedModel(string $slot): ?int
    {
        $stored = $this->configRepository->getValue(0, 'DEFAULTMODEL', $slot);
        if (!is_string($stored) || !is_numeric($stored)) {
            return null;
        }
        $id = (int) $stored;

        return $id > 0 ? $id : null;
    }

    /**
     * @return array{id: string, sentence: string}|null
     */
    private function latestSample(User $admin): ?array
    {
        $runs = $this->store->runsFor((int) $admin->getId());
        usort($runs, static fn (ResearchRunState $a, ResearchRunState $b): int => $b->ref->createdAt <=> $a->ref->createdAt);
        foreach ($runs as $run) {
            if (self::SAMPLE_QUESTION !== $run->ref->question || null === $run->outcome) {
                continue;
            }

            return ['id' => $run->ref->id, 'sentence' => $run->outcome->text()];
        }

        return null;
    }

    private function runsClockDay(): string
    {
        return (new \DateTimeImmutable('now'))->format('Ymd');
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function number(array $row, string $key, int $fallback): int
    {
        return is_numeric($row[$key] ?? null) ? (int) $row[$key] : $fallback;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function decimal(array $row, string $key): float
    {
        return is_numeric($row[$key] ?? null) ? (float) $row[$key] : 0.0;
    }
}
