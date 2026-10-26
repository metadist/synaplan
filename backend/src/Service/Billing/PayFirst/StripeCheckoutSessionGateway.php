<?php

declare(strict_types=1);

namespace App\Service\Billing\PayFirst;

use Psr\Log\LoggerInterface;

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
}
