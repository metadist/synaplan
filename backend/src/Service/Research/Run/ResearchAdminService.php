<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Service\Iam\AuditLogWriter;
use App\Service\Model\ModelListingService;
use Psr\Clock\ClockInterface;

/**
 * The research tab on AI infrastructure: the switch, the two model slots,
 * the limits an administrator can change, and one sample run.
 *
 * A save is checked as a whole first, so a refused value never leaves the
 * other half of the form stored.
 */
final readonly class ResearchAdminService
{
    private const SAMPLE_QUESTION = 'What is worth knowing about technology this week?';
    /** Sample runs are told apart from other runs by this idempotency-key prefix. */
    private const SAMPLE_KEY_PREFIX = 'admin-sample.';
    private const MODEL_SLOTS = ['readingModelId' => 'READ', 'checkingModelId' => 'VERIFY'];
    private const UNSET_MODEL = '-1';

    public function __construct(
        private ResearchConfig $config,
        private ResearchBudgetStore $budgets,
        private ConfigRepository $configRepository,
        private ModelListingService $models,
        private ResearchRunService $runs,
        private ResearchStateStore $store,
        private AuditLogWriter $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(User $admin): array
    {
        return [
            'enabled' => $this->config->isEnabled(),
            'envLocked' => null !== $this->config->envForces(),
            'readingModelId' => $this->storedModel('READ'),
            'checkingModelId' => $this->storedModel('VERIFY'),
            'models' => $this->chatOptions($admin),
            'limits' => [
                'maxActiveRuns' => $this->config->maxActiveRuns(),
                'maxActiveThorough' => $this->config->maxActiveThorough(),
            ],
            'budgets' => $this->currentBudgets(),
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
        $input = ResearchSettingsInput::fromBody($body);
        $wasEnabled = $this->config->isEnabled();
        if (null !== $input->enabled && null !== $this->config->envForces()) {
            throw new ResearchRejected(409, 'env_locked', 'enabled', 'This switch is set by the server and cannot be changed here.');
        }
        $modelWrites = $this->modelWrites($admin, $body);
        $budgetsBefore = $this->currentBudgets();
        $changed = [];

        if (null !== $input->enabled && $input->enabled !== $wasEnabled) {
            $this->configRepository->setValue(0, ResearchConfig::GROUP, ResearchConfig::ENABLED, $input->enabled ? '1' : '0');
            $changed[] = 'enabled';
        }
        $changed = [...$changed, ...$this->storeLimits($input)];
        foreach ($input->budgets as $depth => $row) {
            $this->budgets->save(ResearchDepth::from($depth), $row['searches'], $row['sources'], $row['minutes'], $row['costCap']);
        }
        foreach ($this->currentBudgets() as $depth => $row) {
            if ($row !== $budgetsBefore[$depth]) {
                $changed[] = 'budgets.'.$depth;
            }
        }
        foreach ($modelWrites as $field => $value) {
            $this->configRepository->setValue(0, 'DEFAULTMODEL', self::MODEL_SLOTS[$field], $value);
            $changed[] = $field;
        }

        if ([] !== $changed) {
            $this->audit->record(
                (int) $admin->getId(),
                'research.settings.update',
                'research',
                'workspace',
                ['changed' => $changed, 'enabledBefore' => $wasEnabled, 'enabled' => $this->config->isEnabled()],
                $ip,
            );
        }

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
        if ('running' === ($this->latestSample($admin)['status'] ?? null)) {
            throw new ResearchRejected(409, 'sample_running', null, 'A sample is already running. Wait until it is done.');
        }
        $state = $this->runs->start($admin, [
            'question' => self::SAMPLE_QUESTION,
            'depth' => ResearchDepth::Quick->value,
            'sources' => ['web' => true, 'files' => false],
        ], false, self::SAMPLE_KEY_PREFIX.$this->clock->now()->format('YmdHis'));
        $this->audit->record(
            (int) $admin->getId(),
            'research.sample.start',
            'research',
            $state->ref->id,
            ['depth' => ResearchDepth::Quick->value, 'costCap' => $this->budgets->forDepth(ResearchDepth::Quick)->costCap],
            $ip,
        );

        return [
            'started' => true,
            'id' => $state->ref->id,
            'sentence' => 'The sample has started. This page shows the result when it is done.',
        ];
    }

    /**
     * @return list<string> the limits that changed
     */
    private function storeLimits(ResearchSettingsInput $input): array
    {
        $changed = [];
        $limits = [
            'limits.maxActiveRuns' => [ResearchConfig::MAX_ACTIVE_RUNS, $input->maxActiveRuns, $this->config->maxActiveRuns()],
            'limits.maxActiveThorough' => [ResearchConfig::MAX_ACTIVE_THOROUGH, $input->maxActiveThorough, $this->config->maxActiveThorough()],
        ];
        foreach ($limits as $field => [$setting, $next, $current]) {
            if (null === $next || $next === $current) {
                continue;
            }
            $this->configRepository->setValue(0, ResearchConfig::GROUP, $setting, (string) $next);
            $changed[] = $field;
        }

        return $changed;
    }

    /**
     * Checks both model fields before anything is stored.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, string> field => value to store, only for fields that change
     */
    private function modelWrites(User $admin, array $body): array
    {
        $writes = [];
        $options = null;
        foreach (self::MODEL_SLOTS as $field => $slot) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $stored = $this->storedModel($slot);
            $modelId = $body[$field];
            if (null === $modelId || 0 === $modelId) {
                if (null !== $stored) {
                    $writes[$field] = self::UNSET_MODEL;
                }
                continue;
            }
            if (!is_int($modelId) || $modelId < 1) {
                throw new ResearchRejected(400, 'invalid', $field, 'Choose a model from the list.');
            }
            if ($modelId === $stored) {
                continue;
            }
            $options ??= array_column($this->chatOptions($admin), null, 'id');
            $option = $options[$modelId] ?? null;
            if (null === $option) {
                throw new ResearchRejected(400, 'invalid', $field, 'That model is not available for this.');
            }
            if (!$option['available']) {
                throw new ResearchRejected(400, 'model_unavailable', $field, 'That model is not ready yet. Choose one that is.');
            }
            $writes[$field] = (string) $modelId;
        }

        return $writes;
    }

    /**
     * @return array<string, array{searches: int, sources: int, minutes: int, costCap: float}>
     */
    private function currentBudgets(): array
    {
        $budgets = [];
        foreach (ResearchDepth::cases() as $depth) {
            $budgets[$depth->value] = $this->budgets->human($depth);
        }

        return $budgets;
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
     * The newest sample, running or done. Null when there is none or the
     * research store does not answer, so the rest of the tab still loads.
     *
     * @return array{id: string, status: 'running'|'done', sentence: string|null}|null
     */
    private function latestSample(User $admin): ?array
    {
        try {
            $runs = $this->store->runsFor((int) $admin->getId());
        } catch (ResearchStoreUnavailable) {
            return null;
        }
        $samples = array_filter(
            $runs,
            static fn (ResearchRunState $run): bool => str_starts_with((string) $run->idempotencyKey, self::SAMPLE_KEY_PREFIX),
        );
        usort($samples, static fn (ResearchRunState $a, ResearchRunState $b): int => $b->ref->createdAt <=> $a->ref->createdAt);
        $latest = $samples[0] ?? null;
        if (null === $latest) {
            return null;
        }
        $done = $latest->status->isTerminal();

        return [
            'id' => $latest->ref->id,
            'status' => $done ? 'done' : 'running',
            'sentence' => $done ? $latest->outcome?->text() : null,
        ];
    }
}
