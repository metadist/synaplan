<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\Subscription;
use App\Repository\SubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AdminSubscriptionsService
{
    public function __construct(
        private EntityManagerInterface $em,
        private SubscriptionRepository $subscriptionRepository,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSubscriptions(): array
    {
        $subscriptions = $this->subscriptionRepository->findBy([], ['priceMonthly' => 'ASC']);

        return array_map([$this, 'serializeSubscription'], $subscriptions);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function updateSubscription(int $id, array $data): array
    {
        $subscription = $this->subscriptionRepository->find($id);
        if (!$subscription) {
            throw new SubscriptionNotFoundException("Subscription with ID {$id} not found");
        }

        if (array_key_exists('priceMonthly', $data)) {
            $value = (string) $data['priceMonthly'];
            if (!is_numeric($value) || (float) $value < 0) {
                throw new \InvalidArgumentException('priceMonthly must be a number >= 0');
            }
            $subscription->setPriceMonthly(number_format((float) $value, 2, '.', ''));
        }

        if (array_key_exists('priceYearly', $data)) {
            $value = (string) $data['priceYearly'];
            if (!is_numeric($value) || (float) $value < 0) {
                throw new \InvalidArgumentException('priceYearly must be a number >= 0');
            }
            $subscription->setPriceYearly(number_format((float) $value, 2, '.', ''));
        }

        if (array_key_exists('currency', $data)) {
            $currency = strtoupper(trim((string) $data['currency']));
            if (1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
                throw new \InvalidArgumentException('currency must be a 3-letter ISO 4217 code (e.g. EUR, USD)');
            }
            $subscription->setCurrency($currency);
        }

        if (array_key_exists('costBudgetMonthly', $data)) {
            $value = (string) $data['costBudgetMonthly'];
            if ((float) $value < 0) {
                throw new \InvalidArgumentException('costBudgetMonthly must be >= 0');
            }
            $subscription->setCostBudgetMonthly(number_format((float) $value, 2, '.', ''));
        }

        if (array_key_exists('costBudgetYearly', $data)) {
            $value = (string) $data['costBudgetYearly'];
            if ((float) $value < 0) {
                throw new \InvalidArgumentException('costBudgetYearly must be >= 0');
            }
            $subscription->setCostBudgetYearly(number_format((float) $value, 2, '.', ''));
        }

        if (array_key_exists('active', $data)) {
            $subscription->setActive((bool) $data['active']);
        }

        $this->em->flush();

        return $this->serializeSubscription($subscription);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeSubscription(Subscription $subscription): array
    {
        return [
            'id' => $subscription->getId(),
            'name' => $subscription->getName(),
            'level' => $subscription->getLevel(),
            'priceMonthly' => $subscription->getPriceMonthly(),
            'priceYearly' => $subscription->getPriceYearly(),
            'currency' => $subscription->getCurrency(),
            'description' => $subscription->getDescription(),
            'active' => $subscription->isActive(),
            'costBudgetMonthly' => $subscription->getCostBudgetMonthly(),
            'costBudgetYearly' => $subscription->getCostBudgetYearly(),
        ];
    }
}
