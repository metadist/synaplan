<?php

declare(strict_types=1);

namespace App\Tests\Service\Admin;

use App\Entity\Subscription;
use App\Repository\SubscriptionRepository;
use App\Service\Admin\AdminSubscriptionsService;
use App\Service\Admin\SubscriptionNotFoundException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AdminSubscriptionsServiceTest extends TestCase
{
    private AdminSubscriptionsService $service;
    private EntityManagerInterface&MockObject $em;
    private SubscriptionRepository&MockObject $subscriptionRepository;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->subscriptionRepository = $this->createMock(SubscriptionRepository::class);

        $this->service = new AdminSubscriptionsService(
            $this->em,
            $this->subscriptionRepository,
        );
    }

    public function testListSubscriptionsReturnsSerializedArray(): void
    {
        $sub = $this->createRealSubscription(1, 'Pro', 'PRO', '19.99', '199.00', true, '15.00', '150.00');

        $this->subscriptionRepository
            ->method('findBy')
            ->with([], ['priceMonthly' => 'ASC'])
            ->willReturn([$sub]);

        $result = $this->service->listSubscriptions();

        self::assertCount(1, $result);
        self::assertSame(1, $result[0]['id']);
        self::assertSame('Pro', $result[0]['name']);
        self::assertSame('PRO', $result[0]['level']);
        self::assertSame('19.99', $result[0]['priceMonthly']);
        self::assertSame('199.00', $result[0]['priceYearly']);
        self::assertTrue($result[0]['active']);
        self::assertSame('15.00', $result[0]['costBudgetMonthly']);
        self::assertSame('150.00', $result[0]['costBudgetYearly']);
        self::assertArrayNotHasKey('stripeMonthlyId', $result[0]);
        self::assertArrayNotHasKey('stripeYearlyId', $result[0]);
    }

    public function testUpdateBudgetMonthly(): void
    {
        $sub = $this->createRealSubscription(1, 'Pro', 'PRO', '19.99', '199.00', true, '0.00', '0.00');

        $this->subscriptionRepository->method('find')->with(1)->willReturn($sub);
        $this->em->expects(self::once())->method('flush');

        $result = $this->service->updateSubscription(1, ['costBudgetMonthly' => 15.0]);

        self::assertSame('15.00', $result['costBudgetMonthly']);
    }

    public function testUpdateBudgetYearly(): void
    {
        $sub = $this->createRealSubscription(1, 'Pro', 'PRO', '19.99', '199.00', true, '0.00', '0.00');

        $this->subscriptionRepository->method('find')->with(1)->willReturn($sub);
        $this->em->expects(self::once())->method('flush');

        $result = $this->service->updateSubscription(1, ['costBudgetYearly' => 150.0]);

        self::assertSame('150.00', $result['costBudgetYearly']);
    }

    public function testUpdateActiveToggle(): void
    {
        $sub = $this->createRealSubscription(1, 'Pro', 'PRO', '19.99', '199.00', true, '15.00', '150.00');

        $this->subscriptionRepository->method('find')->with(1)->willReturn($sub);
        $this->em->expects(self::once())->method('flush');

        $result = $this->service->updateSubscription(1, ['active' => false]);

        self::assertFalse($result['active']);
    }

    public function testUpdateMultipleFields(): void
    {
        $sub = $this->createRealSubscription(1, 'Pro', 'PRO', '19.99', '199.00', true, '0.00', '0.00');

        $this->subscriptionRepository->method('find')->with(1)->willReturn($sub);
        $this->em->expects(self::once())->method('flush');

        $result = $this->service->updateSubscription(1, [
            'costBudgetMonthly' => 15.0,
            'costBudgetYearly' => 150.0,
            'active' => false,
        ]);

        self::assertSame('15.00', $result['costBudgetMonthly']);
        self::assertSame('150.00', $result['costBudgetYearly']);
        self::assertFalse($result['active']);
    }

    public function testUpdateNonExistentThrows(): void
    {
        $this->subscriptionRepository->method('find')->with(999)->willReturn(null);

        $this->expectException(SubscriptionNotFoundException::class);

        $this->service->updateSubscription(999, ['costBudgetMonthly' => 10.0]);
    }

    public function testUpdateNegativeBudgetMonthlyThrows(): void
    {
        $sub = $this->createRealSubscription(1, 'Pro', 'PRO', '19.99', '199.00', true, '15.00', '150.00');
        $this->subscriptionRepository->method('find')->with(1)->willReturn($sub);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('costBudgetMonthly must be >= 0');

        $this->service->updateSubscription(1, ['costBudgetMonthly' => -5.0]);
    }

    public function testUpdateNegativeBudgetYearlyThrows(): void
    {
        $sub = $this->createRealSubscription(1, 'Pro', 'PRO', '19.99', '199.00', true, '15.00', '150.00');
        $this->subscriptionRepository->method('find')->with(1)->willReturn($sub);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('costBudgetYearly must be >= 0');

        $this->service->updateSubscription(1, ['costBudgetYearly' => -10.0]);
    }

    public function testUpdateWithEmptyDataPreservesValues(): void
    {
        $sub = $this->createRealSubscription(1, 'Pro', 'PRO', '19.99', '199.00', true, '15.00', '150.00');
        $this->subscriptionRepository->method('find')->with(1)->willReturn($sub);
        $this->em->expects(self::once())->method('flush');

        $result = $this->service->updateSubscription(1, []);

        self::assertSame('15.00', $result['costBudgetMonthly']);
        self::assertSame('150.00', $result['costBudgetYearly']);
        self::assertTrue($result['active']);
    }

    private function createRealSubscription(
        int $id,
        string $name,
        string $level,
        string $priceMonthly,
        string $priceYearly,
        bool $active,
        string $costBudgetMonthly,
        string $costBudgetYearly,
    ): Subscription {
        $sub = new Subscription();
        $sub->setName($name)
            ->setLevel($level)
            ->setPriceMonthly($priceMonthly)
            ->setPriceYearly($priceYearly)
            ->setDescription('Test description')
            ->setActive($active)
            ->setCostBudgetMonthly($costBudgetMonthly)
            ->setCostBudgetYearly($costBudgetYearly);

        $ref = new \ReflectionProperty(Subscription::class, 'id');
        $ref->setValue($sub, $id);

        return $sub;
    }
}
