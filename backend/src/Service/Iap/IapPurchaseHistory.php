<?php

declare(strict_types=1);

namespace App\Service\Iap;

use App\Entity\User;

/**
 * Remembers every store purchase an account has redeemed, next to the
 * subscription record in `BPAYMENTDETAILS`.
 *
 * The subscription record alone forgets: a later Stripe subscription replaces
 * it, and with it the only trace of which account a store purchase belongs to
 * and whether the store took it back. This list survives that. Its entries
 * use the same key names as the subscription record, so
 * {@see \App\Repository\UserRepository::findByIapPurchaseId()} finds the owner
 * through either.
 */
final readonly class IapPurchaseHistory
{
    public const KEY = 'iap_purchases';

    public const STATUS_REFUNDED = 'refunded';

    /** Oldest entries are dropped beyond this; one account holds a handful at most. */
    private const MAX_ENTRIES = 50;

    /**
     * Record the state the store reported for this purchase.
     */
    public function remember(User $user, IapEntitlement $entitlement, string $status): void
    {
        $idKey = $entitlement->platform->purchaseIdKey();
        $details = $user->getPaymentDetails();

        $entries = [];
        foreach ($this->entries($details) as $entry) {
            if (($entry[$idKey] ?? null) !== $entitlement->purchaseId) {
                $entries[] = $entry;
            }
        }
        $entries[] = [
            $idKey => $entitlement->purchaseId,
            'status' => $status,
            'at' => $entitlement->signedAt ?? time(),
        ];

        $details[self::KEY] = array_slice($entries, -self::MAX_ENTRIES);
        $user->setPaymentDetails($details);
    }

    /**
     * True when the store has taken this purchase back and the presented
     * receipt is not newer than that. An Apple transaction held by a device
     * still verifies after a refund; only its signing time gives it away.
     * A purchase made after the refund is signed later and passes.
     */
    public function predatesRefund(User $user, IapEntitlement $entitlement): bool
    {
        $idKey = $entitlement->platform->purchaseIdKey();
        foreach ($this->entries($user->getPaymentDetails()) as $entry) {
            if (($entry[$idKey] ?? null) !== $entitlement->purchaseId) {
                continue;
            }
            if (self::STATUS_REFUNDED !== ($entry['status'] ?? null)) {
                return false;
            }
            $refundedAt = is_int($entry['at'] ?? null) ? $entry['at'] : PHP_INT_MAX;

            return null === $entitlement->signedAt || $entitlement->signedAt <= $refundedAt;
        }

        return $this->predatesRefundOnRecord($user->getSubscriptionData(), $idKey, $entitlement);
    }

    /**
     * Refunds recorded before this history existed carry no signing time.
     * The subscription record then still names the refunded billing period:
     * a receipt that ends with that period is the refunded one, a purchase
     * made afterwards runs longer.
     *
     * @param array<string, mixed> $record
     */
    private function predatesRefundOnRecord(array $record, string $idKey, IapEntitlement $entitlement): bool
    {
        if (($record[$idKey] ?? null) !== $entitlement->purchaseId || self::STATUS_REFUNDED !== ($record['status'] ?? null)) {
            return false;
        }

        $refundedPeriodEnd = $record['subscription_end'] ?? null;
        if (null === $entitlement->expiresAt || !is_int($refundedPeriodEnd)) {
            return true;
        }

        return $entitlement->expiresAt <= $refundedPeriodEnd;
    }

    /**
     * @param array<string, mixed> $details
     *
     * @return list<array<string, mixed>>
     */
    private function entries(array $details): array
    {
        $stored = $details[self::KEY] ?? [];
        if (!is_array($stored)) {
            return [];
        }

        return array_values(array_filter($stored, is_array(...)));
    }
}
