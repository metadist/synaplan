<?php

declare(strict_types=1);

namespace App\Service\Chat;

use App\Repository\ConfigRepository;

/**
 * Platform-wide switches for how much the chat narrates while a turn runs.
 *
 * The backend streams a status event per pipeline phase ("understanding",
 * "searching the web", "sent to <model> by <provider>", …). How much of that
 * the web chat shows is the operator's call: a public instance may want the
 * full story, a white-label deployment may prefer to hide which vendor the
 * request goes to. Three independent switches (BCONFIG group
 * {@see self::CONFIG_GROUP}, ownerId 0), all ON by default:
 *
 *  - {@see self::KEY_STEPS}   — the ordered step list with finished phases
 *                               (OFF: only the current phase, as before)
 *  - {@see self::KEY_MODELS}  — model and provider names in the copy
 *                               (OFF: generic wording, "Sending your request…")
 *  - {@see self::KEY_TIMINGS} — per-step durations and the live elapsed counter
 *
 * Mirrors {@see \App\Service\UsageTaximeterConfig}: read once at the public
 * runtime-config seam; the frontend does the rest. The SSE payload itself is
 * not filtered — API consumers keep the full metadata either way.
 */
final readonly class ProgressNarrationConfig
{
    public const CONFIG_GROUP = 'PROGRESS_NARRATION';
    public const KEY_STEPS = 'SHOW_STEPS';
    public const KEY_MODELS = 'SHOW_MODELS';
    public const KEY_TIMINGS = 'SHOW_TIMINGS';

    private const DEFAULT_ENABLED = true;

    public function __construct(
        private ConfigRepository $configRepository,
    ) {
    }

    public function showSteps(): bool
    {
        return $this->flag(self::KEY_STEPS);
    }

    public function showModels(): bool
    {
        return $this->flag(self::KEY_MODELS);
    }

    public function showTimings(): bool
    {
        return $this->flag(self::KEY_TIMINGS);
    }

    /**
     * Shape used by the public runtime config.
     *
     * @return array{steps: bool, models: bool, timings: bool}
     */
    public function toRuntimeConfig(): array
    {
        return [
            'steps' => $this->showSteps(),
            'models' => $this->showModels(),
            'timings' => $this->showTimings(),
        ];
    }

    /**
     * Accepts the seeder convention ('1'/'0') and the admin UI convention
     * ('true'/'false'); a garbage value falls back to ON rather than hiding
     * information from the user.
     */
    private function flag(string $key): bool
    {
        $value = $this->configRepository->getValue(0, self::CONFIG_GROUP, $key);
        if (null === $value) {
            return self::DEFAULT_ENABLED;
        }

        return filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? self::DEFAULT_ENABLED;
    }
}
