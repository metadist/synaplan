<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Service\RateLimitService;

/**
 * The share of their monthly budget one chat run may spend, as the person
 * chose it (03 §1.3, owner decision of 2026-11-20). It is a share, so it
 * can never be more than the monthly budget. A plan without a monthly
 * budget has no limit per run; circle detection still ends a stuck run.
 */
final readonly class ChatRunCostLimit
{
    public const GROUP = 'LOOP';
    public const SETTING = 'RUN_COST_PERCENT';
    public const DEFAULT_PERCENT = 10;

    /** @var list<int> the shares a person can pick */
    public const CHOICES = [5, 10, 20, 25, 50, 100];

    private const MICRO = 1_000_000;

    public function __construct(
        private ConfigRepository $config,
        private RateLimitService $rateLimits,
    ) {
    }

    public function percent(User $user): int
    {
        $stored = $this->config->getValue((int) $user->getId(), self::GROUP, self::SETTING);
        $value = null !== $stored && ctype_digit($stored) ? (int) $stored : null;

        return \in_array($value, self::CHOICES, true) ? $value : self::DEFAULT_PERCENT;
    }

    /**
     * Stores the share; null goes back to the default.
     *
     * @throws \InvalidArgumentException for a share that is not offered
     */
    public function save(User $user, ?int $percent): void
    {
        $id = (int) $user->getId();
        if (null === $percent || self::DEFAULT_PERCENT === $percent) {
            $this->config->deleteValue($id, self::GROUP, self::SETTING);

            return;
        }
        if (!\in_array($percent, self::CHOICES, true)) {
            throw new \InvalidArgumentException('Choose one of: '.implode(', ', self::CHOICES).'.');
        }
        $this->config->setValue($id, self::GROUP, self::SETTING, (string) $percent);
    }

    /**
     * What the settings page shows: the share, the choices, and what they
     * mean in money for this plan.
     *
     * @return array{percent: int, default: int, choices: list<int>, monthlyBudget: ?string, perRun: ?string}
     */
    public function summary(User $user): array
    {
        $allowance = $this->allowance($user);
        $money = static fn (?int $micro): ?string => null === $micro ? null : number_format($micro / self::MICRO, 2, '.', '');

        return [
            'percent' => $this->percent($user),
            'default' => self::DEFAULT_PERCENT,
            'choices' => self::CHOICES,
            'monthlyBudget' => $money($allowance->monthlyMicro),
            'perRun' => $money($allowance->runCapMicro),
        ];
    }

    /** The payer's monthly budget, what is left of it, and this run's share, read fresh. */
    public function allowance(User $user): ChatRunAllowance
    {
        $budget = $this->rateLimits->checkCostBudget($user);
        $monthly = (float) $budget['budget'];
        if ($monthly <= 0) {
            return new ChatRunAllowance(true, null, null);
        }
        $monthlyMicro = (int) round($monthly * self::MICRO);

        return new ChatRunAllowance(
            $budget['allowed'],
            (int) round((float) $budget['remaining'] * self::MICRO),
            intdiv($monthlyMicro * $this->percent($user), 100),
            $monthlyMicro,
        );
    }
}
