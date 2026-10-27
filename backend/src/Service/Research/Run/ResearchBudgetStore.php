<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Repository\ConfigRepository;

/**
 * The four numbers an administrator can change for each depth.
 * Anything else stays on the built-in limit.
 */
final readonly class ResearchBudgetStore
{
    public function __construct(
        private ConfigRepository $configRepository,
    ) {
    }

    public function forDepth(ResearchDepth $depth): ResearchBudgetConfig
    {
        $base = ResearchBudgetConfig::defaults($depth);
        $stored = $this->configRepository->getValue(0, ResearchConfig::GROUP, self::setting($depth));
        $decoded = self::decode($stored);
        if (null === $decoded) {
            return $base;
        }

        return $base->withHumanLimits(
            self::intOrNull($decoded['searches'] ?? null),
            self::intOrNull($decoded['sources'] ?? null),
            self::intOrNull($decoded['minutes'] ?? null),
            self::floatOrNull($decoded['cost_cap'] ?? null),
        );
    }

    /**
     * @return array{searches: int, sources: int, minutes: int, costCap: float}
     */
    public function human(ResearchDepth $depth): array
    {
        $config = $this->forDepth($depth);

        return [
            'searches' => $config->searches,
            'sources' => $config->sources,
            'minutes' => max(1, (int) round($config->wallSeconds / 60)),
            'costCap' => $config->costCap,
        ];
    }

    public function save(ResearchDepth $depth, int $searches, int $sources, int $minutes, float $costCap): void
    {
        $applied = ResearchBudgetConfig::defaults($depth)->withHumanLimits($searches, $sources, $minutes, $costCap);
        $payload = json_encode([
            'searches' => $applied->searches,
            'sources' => $applied->sources,
            'minutes' => max(1, (int) round($applied->wallSeconds / 60)),
            'cost_cap' => $applied->costCap,
        ], JSON_THROW_ON_ERROR);
        $this->configRepository->setValue(0, ResearchConfig::GROUP, self::setting($depth), $payload);
    }

    public static function setting(ResearchDepth $depth): string
    {
        return 'BUDGET_'.strtoupper($depth->value);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decode(?string $stored): ?array
    {
        if (!is_string($stored) || '' === trim($stored)) {
            return null;
        }
        try {
            $decoded = json_decode($stored, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
