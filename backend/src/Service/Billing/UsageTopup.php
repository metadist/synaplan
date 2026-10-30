<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\Topup;
use App\Entity\User;
use App\Repository\TopupRepository;
use App\Repository\UserRepository;
use App\Service\Billing\PayFirst\CheckoutSessionGateway;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\StripeObject;

/**
 * One usage top-up: €100 paid once, added to this period's budget.
 *
 * Checkout is a single Stripe payment. The same credit is written from the
 * webhook and from the success page, and the session id keeps it to one row.
 */
final readonly class UsageTopup
{
    public const AMOUNT_EUR = 100;

    public function __construct(
        private CheckoutSessionGateway $gateway,
        private TopupRepository $topups,
        private UserRepository $users,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private string $frontendUrl,
        private string $stripePaymentMethods,
        private bool $stripeAutomaticTaxEnabled,
    ) {
    }

    /**
     * A request may omit the step count. Any other count is refused: the
     * screen and the charge are both this one amount.
     */
    public function assertSingleStep(?int $steps): void
    {
        if (null !== $steps && 1 !== $steps) {
            throw new UsageTopupRejected('A top-up is EUR 100.', 'TOPUP_AMOUNT_FIXED', 400);
        }
    }

    /**
     * @return array{sessionId: string, url: string, steps: int, total_eur: int}
     */
    public function start(User $user, string $customerId): array
    {
        $paymentMethods = array_filter(array_map('trim', explode(',', $this->stripePaymentMethods)));
        $created = $this->gateway->create([
            'customer' => $customerId,
            'allowed_payment_method_types' => $paymentMethods,
            'line_items' => [[
                'price_data' => [
                    'currency' => 'eur',
                    'product_data' => [
                        'name' => 'Usage top-up',
                        'description' => sprintf('Adds EUR %d to your usage until this period ends.', self::AMOUNT_EUR),
                    ],
                    'unit_amount' => self::AMOUNT_EUR * 100,
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'billing_address_collection' => 'required',
            'customer_update' => [
                'name' => 'auto',
                'address' => 'auto',
            ],
            'tax_id_collection' => [
                'enabled' => true,
            ],
            'automatic_tax' => [
                'enabled' => $this->stripeAutomaticTaxEnabled,
            ],
            'success_url' => $this->frontendUrl.'/subscription/success?kind=topup&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $this->frontendUrl.'/subscription/cancel',
            'client_reference_id' => (string) $user->getId(),
            'metadata' => [
                'user_id' => (string) $user->getId(),
                'type' => 'topup',
                'topup_eur' => (string) self::AMOUNT_EUR,
            ],
        ]);

        $this->logger->info('Stripe top-up session created', [
            'user_id' => $user->getId(),
            'session_id' => $created['id'],
            'total_eur' => self::AMOUNT_EUR,
        ]);

        return [
            'sessionId' => $created['id'],
            'url' => $created['url'],
            'steps' => 1,
            'total_eur' => self::AMOUNT_EUR,
        ];
    }

    /**
     * What the signed-in user may be told about this checkout.
     *
     * @return array{status: 'credited'|'pending', amount_eur: int}
     */
    public function confirmForUser(User $user, string $sessionId): array
    {
        if (1 !== preg_match('/^cs_[A-Za-z0-9_]+$/', $sessionId)) {
            throw new UsageTopupRejected('That payment could not be checked.', 'TOPUP_NOT_FOUND', 404);
        }

        $loaded = null;
        $this->gateway->retrieve($sessionId, $loaded);
        if (!$loaded instanceof StripeObject || 'topup' !== ($this->metadata($loaded)['type'] ?? null)) {
            throw new UsageTopupRejected('That payment could not be checked.', 'TOPUP_NOT_FOUND', 404);
        }

        $owner = (string) ($loaded->client_reference_id ?? ($this->metadata($loaded)['user_id'] ?? ''));
        if ($owner !== (string) $user->getId()) {
            throw new UsageTopupRejected('That payment could not be checked.', 'TOPUP_NOT_FOUND', 404);
        }

        $amount = $this->amountEur($loaded);
        if ('paid' !== ($loaded->payment_status ?? null) || $amount <= 0) {
            return ['status' => 'pending', 'amount_eur' => (int) round($amount)];
        }

        $this->credit($user, $loaded, $amount);

        return ['status' => 'credited', 'amount_eur' => (int) round($amount)];
    }

    /**
     * Webhook credit. Returns true even when there is nothing to credit, so
     * Stripe stops retrying a session that can never succeed.
     */
    public function recordFromWebhook(StripeObject $session): bool
    {
        $userId = $session->client_reference_id ?? ($this->metadata($session)['user_id'] ?? null);
        $user = $userId ? $this->users->find((int) $userId) : null;
        if (!$user instanceof User) {
            $this->logger->warning('User not found for top-up checkout, acknowledging to stop Stripe retries', [
                'session_id' => $session->id,
                'user_id' => $userId,
            ]);

            return true;
        }

        $amount = $this->amountEur($session);
        if ($amount <= 0) {
            $this->logger->warning('Top-up completed with non-positive amount, acknowledging to stop Stripe retries', [
                'session_id' => $session->id,
                'user_id' => $user->getId(),
            ]);

            return true;
        }

        $this->credit($user, $session, $amount);

        return true;
    }

    private function credit(User $user, StripeObject $session, float $amountEur): void
    {
        $sessionId = (string) $session->id;
        if ($this->topups->existsForSession($sessionId)) {
            $this->logger->info('Top-up already credited for session', [
                'session_id' => $sessionId,
                'user_id' => $user->getId(),
            ]);

            return;
        }

        $topup = new Topup();
        $topup->setUserId((int) $user->getId())
            ->setAmount(number_format($amountEur, 2, '.', ''))
            ->setCurrency('EUR')
            ->setStripeSessionId($sessionId)
            ->setStatus('completed')
            ->setCreated(time());

        if (!empty($session->customer)) {
            $paymentDetails = $user->getPaymentDetails();
            $paymentDetails['stripe_customer_id'] = $session->customer;
            $user->setPaymentDetails($paymentDetails);
        }

        try {
            $this->topups->save($topup, false);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            $this->logger->info('Top-up credit raced a concurrent retry; already credited', [
                'session_id' => $sessionId,
                'user_id' => $user->getId(),
            ]);

            return;
        }

        $this->logger->info('Budget top-up credited', [
            'session_id' => $sessionId,
            'user_id' => $user->getId(),
            'amount_eur' => $amountEur,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(StripeObject $session): array
    {
        $metadata = $session->offsetExists('metadata') ? $session->offsetGet('metadata') : null;

        return $metadata instanceof StripeObject ? $metadata->toArray() : [];
    }

    private function amountEur(StripeObject $session): float
    {
        $metadata = $this->metadata($session);
        if (isset($metadata['topup_eur'])) {
            return (float) $metadata['topup_eur'];
        }

        return ((int) ($session->amount_subtotal ?? 0)) / 100.0;
    }
}
