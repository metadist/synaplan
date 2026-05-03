<?php

declare(strict_types=1);

namespace App\Service\Embedding;

use App\Entity\RevectorizeRun;
use App\Entity\User;
use App\Repository\RevectorizeRunRepository;
use App\Service\Embedding\Exception\CooldownActiveException;
use App\Service\Embedding\Exception\PremiumRequiredException;

/**
 * EmbeddingModelChangeGuard — single decision point for "may this user
 * switch the VECTORIZE model right now?".
 *
 * Two checks:
 *   1. Subscription gate — Free / Anonymous / NEW users are blocked.
 *      Self-hosted admins (ROLE_ADMIN) are always allowed; the operator
 *      runs the costs themselves.
 *   2. Cooldown — at most one switch per scope per hour. The cooldown
 *      protects against accidental double-clicks and runaway
 *      automations. It is configurable per environment via
 *      BCONFIG[group=EMBEDDING_PRICING, setting=COOLDOWN_SECONDS].
 *
 * Throws typed exceptions so the controller can map them to specific
 * HTTP responses (`403 requires_premium`, `429 cooldown_active`).
 *
 * The `getStatus()` method is the read-only sibling of `assertCanChange`
 * — it returns a structured snapshot for the UI's "Switch model" button
 * tooltip without throwing.
 */
final class EmbeddingModelChangeGuard
{
    public const COOLDOWN_SECONDS = 3600;

    /** Plan tiers that may switch the embedding model. */
    private const PAID_LEVELS = ['PRO', 'TEAM', 'BUSINESS', 'ADMIN'];

    public function __construct(
        private readonly RevectorizeRunRepository $runRepository,
    ) {
    }

    /**
     * @throws PremiumRequiredException
     * @throws CooldownActiveException
     */
    public function assertCanChange(User $user, string $scope = RevectorizeRun::SCOPE_ALL): void
    {
        $this->assertPremium($user);
        $this->assertCooldown($scope, $user);
    }

    /**
     * Read-only status snapshot for the UI.
     *
     * @return array{
     *     canChange: bool,
     *     reason: ?string,
     *     currentLevel: string,
     *     cooldownEndsAt: ?int,
     *     cooldownSecondsRemaining: int
     * }
     */
    public function getStatus(User $user, string $scope = RevectorizeRun::SCOPE_ALL): array
    {
        $level = $user->getRateLimitLevel();
        $cooldownEndsAt = $this->cooldownEndsAt($scope);
        $remaining = max(0, $cooldownEndsAt - time());

        if (!$this->isPaidLevel($level)) {
            return [
                'canChange' => false,
                'reason' => 'requires_premium',
                'currentLevel' => $level,
                'cooldownEndsAt' => $cooldownEndsAt > time() ? $cooldownEndsAt : null,
                'cooldownSecondsRemaining' => $remaining,
            ];
        }

        if ($remaining > 0 && 'ADMIN' !== $level) {
            return [
                'canChange' => false,
                'reason' => 'cooldown_active',
                'currentLevel' => $level,
                'cooldownEndsAt' => $cooldownEndsAt,
                'cooldownSecondsRemaining' => $remaining,
            ];
        }

        return [
            'canChange' => true,
            'reason' => null,
            'currentLevel' => $level,
            'cooldownEndsAt' => null,
            'cooldownSecondsRemaining' => 0,
        ];
    }

    public function isPaidLevel(string $level): bool
    {
        return in_array(strtoupper($level), self::PAID_LEVELS, true);
    }

    /**
     * @throws PremiumRequiredException
     */
    private function assertPremium(User $user): void
    {
        $level = $user->getRateLimitLevel();
        if (!$this->isPaidLevel($level)) {
            throw new PremiumRequiredException($level);
        }
    }

    /**
     * @throws CooldownActiveException
     */
    private function assertCooldown(string $scope, User $user): void
    {
        // Admins bypass the cooldown — they're typically running scripted
        // migrations during a maintenance window where back-to-back swaps
        // are intentional.
        if ('ADMIN' === $user->getRateLimitLevel()) {
            return;
        }

        $endsAt = $this->cooldownEndsAt($scope);
        if ($endsAt <= time()) {
            return;
        }

        throw new CooldownActiveException($endsAt, $endsAt - time());
    }

    private function cooldownEndsAt(string $scope): int
    {
        $latest = $this->runRepository->findLatestForScope($scope);
        if (null === $latest) {
            return 0;
        }

        return $latest->getCreated() + self::COOLDOWN_SECONDS;
    }
}
