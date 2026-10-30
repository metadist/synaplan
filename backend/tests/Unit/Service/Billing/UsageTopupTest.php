<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Billing;

use App\Entity\Topup;
use App\Entity\User;
use App\Repository\TopupRepository;
use App\Repository\UserRepository;
use App\Service\Billing\UsageTopup;
use App\Service\Billing\UsageTopupRejected;
use App\Tests\Unit\Service\Billing\PayFirst\RecordingGateway;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stripe\StripeObject;

final class UsageTopupTest extends TestCase
{
    public function testATopupIsOnlyOneHundredEuros(): void
    {
        $topups = $this->topup();
        $topups->assertSingleStep(null);
        $topups->assertSingleStep(1);

        $this->expectException(UsageTopupRejected::class);
        $topups->assertSingleStep(2);
    }

    public function testCheckoutChargesOneHundredEuros(): void
    {
        $gateway = new RecordingGateway();
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);

        $created = $this->topup($gateway)->start($user, 'cus_7');

        self::assertSame('cs_test_1', $created['sessionId']);
        self::assertSame(1, $created['steps']);
        self::assertSame(100, $created['total_eur']);
        self::assertSame(1, $gateway->created[0]['line_items'][0]['quantity']);
        self::assertSame(10000, $gateway->created[0]['line_items'][0]['price_data']['unit_amount']);
        self::assertSame('100', $gateway->created[0]['metadata']['topup_eur']);
        self::assertSame('topup', $gateway->created[0]['metadata']['type']);
    }

    public function testAPaidSessionIsCreditedOnce(): void
    {
        $gateway = new RecordingGateway();
        $gateway->rawSessions['cs_paid'] = $this->session('cs_paid', 'paid', '7');
        $saved = null;
        $repository = $this->createMock(TopupRepository::class);
        $repository->method('existsForSession')->willReturn(false);
        $repository->expects($this->once())->method('save')->willReturnCallback(
            function (Topup $topup) use (&$saved): void {
                $saved = $topup;
            },
        );
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $result = $this->topup($gateway, $repository, $em)->confirmForUser($this->user(7), 'cs_paid');

        self::assertSame(['status' => 'credited', 'amount_eur' => 100], $result);
        self::assertInstanceOf(Topup::class, $saved);
        self::assertSame('100.00', $saved->getAmount());
        self::assertSame('completed', $saved->getStatus());
        self::assertSame(7, $saved->getUserId());
    }

    public function testAnUnpaidSessionStaysPending(): void
    {
        $gateway = new RecordingGateway();
        $gateway->rawSessions['cs_open'] = $this->session('cs_open', 'unpaid', '7');
        $repository = $this->createMock(TopupRepository::class);
        $repository->expects($this->never())->method('save');

        $result = $this->topup($gateway, $repository)->confirmForUser($this->user(7), 'cs_open');

        self::assertSame(['status' => 'pending', 'amount_eur' => 100], $result);
    }

    public function testSomeoneElsesSessionIsNotConfirmed(): void
    {
        $gateway = new RecordingGateway();
        $gateway->rawSessions['cs_other'] = $this->session('cs_other', 'paid', '9');

        $this->expectException(UsageTopupRejected::class);
        $this->topup($gateway)->confirmForUser($this->user(7), 'cs_other');
    }

    public function testAWebhookForAMissingUserIsAcknowledged(): void
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturn(null);
        $repository = $this->createMock(TopupRepository::class);
        $repository->expects($this->never())->method('save');

        $acknowledged = $this->topup(users: $users, topups: $repository)
            ->recordFromWebhook($this->session('cs_missing', 'paid', '404'));

        self::assertTrue($acknowledged);
    }

    private function topup(
        ?RecordingGateway $gateway = null,
        ?TopupRepository $topups = null,
        ?EntityManagerInterface $em = null,
        ?UserRepository $users = null,
    ): UsageTopup {
        return new UsageTopup(
            $gateway ?? new RecordingGateway(),
            $topups ?? $this->createStub(TopupRepository::class),
            $users ?? $this->createStub(UserRepository::class),
            $em ?? $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
            'https://app.test',
            'card',
            false,
        );
    }

    private function user(int $id): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getPaymentDetails')->willReturn([]);
        $user->method('setPaymentDetails')->willReturnSelf();

        return $user;
    }

    private function session(string $id, string $paymentStatus, string $userId): StripeObject
    {
        return StripeObject::constructFrom([
            'id' => $id,
            'object' => 'checkout.session',
            'customer' => 'cus_'.$userId,
            'client_reference_id' => $userId,
            'payment_status' => $paymentStatus,
            'metadata' => [
                'type' => 'topup',
                'user_id' => $userId,
                'topup_eur' => '100',
            ],
        ]);
    }
}
