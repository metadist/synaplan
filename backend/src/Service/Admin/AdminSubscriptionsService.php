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
            'description' => $subscription->getDescription(),
            'active' => $subscription->isActive(),
            'costBudgetMonthly' => $subscription->getCostBudgetMonthly(),
            'costBudgetYearly' => $subscription->getCostBudgetYearly(),
        ];
    }
}
