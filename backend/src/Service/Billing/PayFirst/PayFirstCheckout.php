<?php

declare(strict_types=1);

namespace App\Service\Billing\PayFirst;

use App\Service\Billing\StripeEntitlement;
use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Starts a Stripe Checkout for a person without an account (pay first, PF1).
 *
 * Nothing is created here: no account and no Stripe customer — Checkout in
 * subscription mode creates the customer only when the payment completes.
 * The session carries the plan and the hash of a nonce the browser keeps in
 * an HttpOnly cookie, so only the browser that started the checkout can be
 * signed in after it (PF2).
 */
final readonly class PayFirstCheckout
{
    public const FLOW = 'pay_first';
    public const NONCE_COOKIE = 'synaplan_checkout_nonce';
    public const NONCE_TTL_SECONDS = 86400;

    private const EMAIL_MAX_LENGTH = 128;
    private const NONCE_BYTES = 32;

    public function __construct(
        private CheckoutSessionGateway $gateway,
        private StripeEntitlement $entitlement,
        private RateLimiterFactoryInterface $checkoutStartIpLimiter,
        private RateLimiterFactoryInterface $checkoutStartEmailLimiter,
        private LoggerInterface $logger,
        private string $frontendUrl,
        private string $stripePaymentMethods,
        private bool $stripeAutomaticTaxEnabled,
    ) {
    }

    /**
     * @throws PayFirstException
     */
    public function start(string $email, string $plan, ?string $clientIp): PayFirstStart
    {
        $email = trim($email);
        if ('' === $email || mb_strlen($email) > self::EMAIL_MAX_LENGTH || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw PayFirstException::emailInvalid();
        }
        $plan = strtoupper(trim($plan));
        $priceId = $this->entitlement->prices()[$plan] ?? '';
        if ('' === $priceId) {
            throw PayFirstException::planInvalid();
        }

        $this->consume($this->checkoutStartIpLimiter, null !== $clientIp && '' !== $clientIp ? $clientIp : 'unknown');
        $this->consume($this->checkoutStartEmailLimiter, hash('sha256', mb_strtolower($email)));

        $nonce = rtrim(strtr(base64_encode(random_bytes(self::NONCE_BYTES)), '+/', '-_'), '=');
        $session = $this->gateway->create($this->sessionParams($email, $plan, $priceId, hash('sha256', $nonce)));

        $this->logger->info('Pay-first checkout started', ['session_id' => $session['id'], 'plan' => $plan]);

        return new PayFirstStart($session['url'], $nonce);
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionParams(string $email, string $plan, string $priceId, string $nonceHash): array
    {
        $frontend = rtrim($this->frontendUrl, '/');

        return [
            'mode' => 'subscription',
            'customer_email' => $email,
            'allowed_payment_method_types' => array_values(array_filter(array_map('trim', explode(',', $this->stripePaymentMethods)))),
            'line_items' => [['price' => $priceId, 'quantity' => 1]],
            'billing_address_collection' => 'required',
            'tax_id_collection' => ['enabled' => true],
            'automatic_tax' => ['enabled' => $this->stripeAutomaticTaxEnabled],
            'allow_promotion_codes' => true,
            'metadata' => ['flow' => self::FLOW, 'plan' => $plan, 'start_nonce_hash' => $nonceHash],
            'subscription_data' => ['metadata' => ['flow' => self::FLOW, 'plan' => $plan]],
            'success_url' => $frontend.'/welcome?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $frontend.'/start?plan='.rawurlencode($plan).'&cancelled=1',
        ];
    }

    private function consume(RateLimiterFactoryInterface $limiter, string $key): void
    {
        $limit = $limiter->create($key)->consume();
        if (!$limit->isAccepted()) {
            throw PayFirstException::rateLimited(max(1, $limit->getRetryAfter()->getTimestamp() - time()));
        }
    }
}
