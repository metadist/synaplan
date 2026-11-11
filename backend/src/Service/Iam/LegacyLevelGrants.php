<?php

declare(strict_types=1);

namespace App\Service\Iam;

use App\Entity\User;
use App\Repository\AuditLogEntryRepository;

/**
 * Reconstructs grants for an account that predates the table. The same
 * rules run from the deploy command and, lazily, on the first grant change
 * the command has not reached yet.
 */
final readonly class LegacyLevelGrants
{
    private const STRIPE_OWNED_STATUSES = ['active', 'trialing', 'canceling', 'past_due', 'paused'];
    private const IAP_OWNED_STATUSES = ['active', 'grace_period', 'on_hold'];

    public function __construct(
        private AuditLogEntryRepository $audit,
        private string $oidcAdminRoles = '',
    ) {
    }

    public function plan(User $user, bool $withAudit): LegacyGrantPlan
    {
        $level = strtoupper($user->getUserLevel());
        $subscription = $user->getSubscriptionData();
        $status = (string) ($subscription['status'] ?? '');
        $settled = false !== ($subscription['settled'] ?? true);
        $source = $user->getSubscriptionSource();
        $planLevel = $this->billingLevel($subscription, $level);
        $created = $this->timestamp($subscription['subscription_start'] ?? null);
        $grants = [];

        if (LevelGrantSource::STRIPE === $source && $settled && in_array($status, self::STRIPE_OWNED_STATUSES, true) && null !== $planLevel) {
            $ref = $subscription['stripe_subscription_id'] ?? null;
            $grants[] = $this->grant(LevelGrantSource::STRIPE, $planLevel, is_scalar($ref) ? (string) $ref : null, $created);
        }
        if (in_array($source, ['apple', 'google'], true) && in_array($status, self::IAP_OWNED_STATUSES, true) && null !== $planLevel) {
            // The store token stays in the payment record. The grant does not copy it.
            $grants[] = $this->grant(LevelGrantSource::IAP, $planLevel, null, $created);
        }

        $directory = $this->directoryAdmin($user);
        if ('ADMIN' === $level) {
            $grants[] = $this->grant(
                $directory ? LevelGrantSource::DIRECTORY : LevelGrantSource::BOOTSTRAP,
                'ADMIN',
                null,
                time(),
            );
        } else {
            $billingHighest = AccountLevel::highest(array_column($grants, 'level'));
            $auditLevel = $withAudit ? $this->auditLevel($user, $subscription) : null;
            $adminLevel = $this->adminLevel($level, [] !== $grants, $billingHighest, $auditLevel);
            if (null !== $adminLevel) {
                $grants[] = $this->grant(LevelGrantSource::ADMIN, $adminLevel, null, time());
            }
        }

        $rule1Only = [] !== $grants;
        foreach ($grants as $grant) {
            if (!in_array($grant['source'], [LevelGrantSource::STRIPE, LevelGrantSource::IAP], true)) {
                $rule1Only = false;
            }
        }

        return new LegacyGrantPlan($grants, $rule1Only);
    }

    /**
     * @param array<string, mixed> $subscription
     */
    private function auditLevel(User $user, array $subscription): ?string
    {
        $start = $subscription['subscription_start'] ?? null;
        if (!is_numeric($start) || null === $user->getId()) {
            return null;
        }

        return $this->audit->levelSetAfter((int) $user->getId(), (int) $start);
    }

    private function adminLevel(string $level, bool $billing, ?string $billingHighest, ?string $auditLevel): ?string
    {
        $fromLevel = $this->protects($level, $billing, $billingHighest) ? $level : null;
        if (null !== $auditLevel && AccountLevel::known($auditLevel) && 'NEW' !== $auditLevel) {
            if (null !== $fromLevel && AccountLevel::rank($fromLevel) > AccountLevel::rank($auditLevel)) {
                return $fromLevel;
            }

            return strtoupper($auditLevel);
        }

        return $fromLevel;
    }

    private function protects(string $level, bool $billing, ?string $billingHighest): bool
    {
        if (!AccountLevel::known($level) || 'NEW' === $level) {
            return false;
        }
        if (!$billing || null === $billingHighest) {
            return true;
        }

        return AccountLevel::rank($level) > AccountLevel::rank($billingHighest);
    }

    private function directoryAdmin(User $user): bool
    {
        $roles = $user->getUserDetails()['oidc_roles'] ?? null;
        if (!is_array($roles)) {
            return false;
        }
        $admins = array_map('strtolower', array_map('trim', explode(',', $this->oidcAdminRoles)));
        $present = array_map(static fn (mixed $role): string => strtolower((string) $role), $roles);

        return [] !== array_intersect($present, array_filter($admins, static fn (string $role): bool => '' !== $role));
    }

    /**
     * The tier a live billing record grants. A record that names no plan
     * owns the account's current paid tier; it never owns Admin.
     *
     * @param array<string, mixed> $subscription
     */
    private function billingLevel(array $subscription, string $accountLevel): ?string
    {
        $named = $this->paidPlan($subscription['plan'] ?? null);
        if (null !== $named) {
            return $named;
        }

        return in_array($accountLevel, ['PRO', 'TEAM', 'BUSINESS'], true) ? $accountLevel : null;
    }

    private function paidPlan(mixed $plan): ?string
    {
        if (!is_string($plan)) {
            return null;
        }
        $plan = strtoupper($plan);

        return in_array($plan, ['PRO', 'TEAM', 'BUSINESS'], true) ? $plan : null;
    }

    private function timestamp(mixed $value): int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : time();
    }

    /**
     * @return array{source: string, level: string, ref: ?string, created: int}
     */
    private function grant(string $source, string $level, ?string $ref, int $created): array
    {
        return [
            'source' => $source,
            'level' => $level,
            'ref' => null !== $ref && '' !== $ref ? $ref : null,
            'created' => $created,
        ];
    }
}
