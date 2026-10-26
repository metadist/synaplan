<?php

declare(strict_types=1);

namespace App\Service\Billing\PayFirst;

use Psr\Log\LoggerInterface;
use Stripe\StripeObject;

final readonly class StripeCheckoutSessionGateway implements CheckoutSessionGateway
{
    public function __construct(
        private string $stripeSecretKey,
        private LoggerInterface $logger,
    ) {
    }

    public function create(array $params): array
    {
        try {
            \Stripe\Stripe::setApiKey($this->stripeSecretKey);
            $session = \Stripe\Checkout\Session::create($params);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->logger->error('Pay-first checkout session could not be created', [
                'stripe_error' => $e->getMessage(),
                'stripe_code' => $e->getStripeCode(),
            ]);

            throw PayFirstException::unavailable();
        }

        return ['id' => (string) $session->id, 'url' => (string) $session->url];
    }

    public function retrieve(string $sessionId): ?PaidCheckout
    {
        try {
            \Stripe\Stripe::setApiKey($this->stripeSecretKey);
            $session = \Stripe\Checkout\Session::retrieve($sessionId);
        } catch (\Stripe\Exception\InvalidRequestException) {
            return null;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->logger->error('Pay-first checkout session could not be read', ['session_id' => $sessionId, 'stripe_error' => $e->getMessage()]);

            throw PayFirstException::unavailable();
        }

        $metadata = $session->metadata;
        if (!$metadata instanceof StripeObject || PayFirstCheckout::FLOW !== ($metadata['flow'] ?? null)) {
            return null;
        }
        $details = $session->customer_details;
        $email = $details instanceof StripeObject && is_string($details['email'] ?? null) ? $details['email'] : (string) ($session->customer_email ?? '');

        return new PaidCheckout(
            (string) $session->id,
            mb_strtolower(trim($email)),
            strtoupper((string) ($metadata['plan'] ?? '')),
            self::idOf($session->customer),
            self::idOf($session->subscription),
            'complete' === $session->status && 'paid' === $session->payment_status,
            (string) ($metadata['start_nonce_hash'] ?? ''),
        );
    }

    public function subscription(string $subscriptionId): StripeObject
    {
        try {
            \Stripe\Stripe::setApiKey($this->stripeSecretKey);

            return \Stripe\Subscription::retrieve(['id' => $subscriptionId, 'expand' => ['latest_invoice']]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->logger->error('Pay-first subscription could not be read', ['subscription_id' => $subscriptionId, 'stripe_error' => $e->getMessage()]);

            throw PayFirstException::unavailable();
        }
    }

    private static function idOf(mixed $value): ?string
    {
        if ($value instanceof StripeObject) {
            $value = $value->id ?? null;
        }

        return is_string($value) && '' !== $value ? $value : null;
    }
}
