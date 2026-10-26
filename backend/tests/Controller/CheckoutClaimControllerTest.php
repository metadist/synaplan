<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Service\Auth\TokenService;
use App\Service\Billing\PayFirst\CheckoutSessionGateway;
use App\Service\Billing\PayFirst\PaidAccountCreator;
use App\Service\Billing\PayFirst\PayFirstCheckout;
use App\Service\Billing\StripeEntitlement;
use App\Service\Iam\UserDeletionService;
use App\Tests\Unit\Service\Billing\PayFirst\RecordingGateway;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Mime\Email;

/**
 * Pay first, PF2: the account exists only after the payment, the browser that
 * started the checkout is signed in once, and nobody else is (journeys
 * J-PF-1, J-PF-4, J-PF-6). The test suite runs with Stripe configured; the
 * gateway stands in for Stripe.
 */
final class CheckoutClaimControllerTest extends WebTestCase
{
    private const PATH = '/api/v1/checkout/claim';
    private const DOMAIN = '@pay-first-claim.synaplan.internal';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private RecordingGateway $gateway;
    private string $ip;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->ip = '10.'.random_int(0, 255).'.'.random_int(0, 255).'.'.random_int(1, 254);
        $this->gateway = new RecordingGateway();
        $this->gateway->priceId = static::getContainer()->get(StripeEntitlement::class)->prices()['PRO'];
        static::getContainer()->set(CheckoutSessionGateway::class, $this->gateway);
        $this->removeTestUsers();
    }

    protected function tearDown(): void
    {
        $this->removeTestUsers();
        parent::tearDown();
    }

    public function testThePayingBrowserGetsItsNewAccountAndIsSignedInOnce(): void
    {
        $this->gateway->paidSession('cs_new', 'ada'.self::DOMAIN, 'browser-nonce');
        $this->client->getCookieJar()->set(new Cookie(PayFirstCheckout::NONCE_COOKIE, 'browser-nonce'));

        $this->claim('cs_new');
        self::assertSame(['success' => true, 'status' => 'ready', 'plan' => 'PRO', 'email' => 'ada'.self::DOMAIN, 'signedIn' => true], $this->json());
        self::assertContains(TokenService::ACCESS_COOKIE, array_map(static fn ($c) => $c->getName(), $this->client->getResponse()->headers->getCookies()));

        $user = $this->user('ada'.self::DOMAIN);
        self::assertSame('PRO', $user->getUserLevel());
        self::assertSame('cus_cs_new', $user->getStripeCustomerId());
        self::assertTrue($user->isActive());
        self::assertFalse($user->isEmailVerified(), 'Stripe does not prove the address; only our own link does.');
        self::assertEmailCount(1);

        // A subscription webhook that loaded the account before the claim
        // writes its older billing snapshot back afterwards.
        $snapshot = $user->getPaymentDetails();
        $this->em->getConnection()->executeStatement('UPDATE BUSER SET BPAYMENTDETAILS = ? WHERE BID = ?', [json_encode($snapshot), $user->getId()]);

        $this->client->getCookieJar()->set(new Cookie(PayFirstCheckout::NONCE_COOKIE, 'browser-nonce'));
        $this->claim('cs_new');
        self::assertFalse($this->json()['signedIn'], 'The sign-in is single use, whatever the billing data says.');
        self::assertSame(1, $this->countUsers('ada'.self::DOMAIN));
    }

    public function testTheSetPasswordMailLeadsToAWorkingLogin(): void
    {
        $this->gateway->paidSession('cs_password', 'gus'.self::DOMAIN, 'nonce');
        $this->claim('cs_password');
        self::assertFalse($this->json()['signedIn'], 'No nonce cookie in this browser yet.');
        self::assertSame('ready', $this->json()['status']);

        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame(1, preg_match('#/reset-password\?token=([0-9a-f]{64})#', (string) $mail->getHtmlBody(), $m));

        $this->post('/api/v1/auth/reset-password', ['token' => $m[1], 'password' => 'Fresh-Pass-2026']);
        self::assertResponseIsSuccessful();
        $this->post('/api/v1/auth/login', ['email' => 'gus'.self::DOMAIN, 'password' => 'Fresh-Pass-2026']);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->user('gus'.self::DOMAIN)->isEmailVerified());

        // The mailbox owner has taken the account over; the buyer's unused
        // nonce from the checkout must not sign anyone in any more.
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set(new Cookie(PayFirstCheckout::NONCE_COOKIE, 'nonce'));
        $this->claim('cs_password');
        self::assertFalse($this->json()['signedIn']);
        self::assertNotContains(TokenService::ACCESS_COOKIE, array_map(static fn ($c) => $c->getName(), $this->client->getResponse()->headers->getCookies()));
    }

    public function testAnotherBrowserSeesTheAccountButIsNotSignedIn(): void
    {
        $this->gateway->paidSession('cs_other', 'bea'.self::DOMAIN, 'the-buyers-nonce');
        $this->client->getCookieJar()->set(new Cookie(PayFirstCheckout::NONCE_COOKIE, 'someone-elses-nonce'));

        $this->claim('cs_other');

        self::assertSame('ready', $this->json()['status']);
        self::assertFalse($this->json()['signedIn']);
        self::assertSame(1, $this->countUsers('bea'.self::DOMAIN));
    }

    public function testAnAddressWithAnAccountGetsThePlanAndNobodyIsSignedIn(): void
    {
        $existing = (new User())->setMail('cleo'.self::DOMAIN)->setType('WEB')->setProviderId('local')->setUserLevel('NEW');
        $existing->setCreated(date('Y-m-d H:i:s'));
        $this->em->persist($existing);
        $this->em->flush();
        $this->gateway->paidSession('cs_existing', 'cleo'.self::DOMAIN, 'nonce');
        $this->client->getCookieJar()->set(new Cookie(PayFirstCheckout::NONCE_COOKIE, 'nonce'));

        $this->claim('cs_existing');

        self::assertSame('existing', $this->json()['status']);
        self::assertFalse($this->json()['signedIn']);
        self::assertSame('PRO', $this->user('cleo'.self::DOMAIN)->getUserLevel());
        self::assertSame(1, $this->countUsers('cleo'.self::DOMAIN));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function liveStatuses(): iterable
    {
        yield 'active' => ['active'];
        yield 'trialing' => ['trialing'];
        yield 'past due' => ['past_due'];
    }

    #[DataProvider('liveStatuses')]
    public function testAnAccountWithAnotherLiveSubscriptionIsLeftAlone(string $status): void
    {
        $existing = (new User())->setMail('fay'.self::DOMAIN)->setType('WEB')->setProviderId('local')->setUserLevel('TEAM');
        $existing->setCreated(date('Y-m-d H:i:s'));
        $existing->setSubscriptionData(['stripe_subscription_id' => 'sub_live', 'status' => $status, 'subscription_end' => time() - 86400]);
        $this->em->persist($existing);
        $this->em->flush();
        $this->gateway->paidSession('cs_conflict', 'fay'.self::DOMAIN, 'nonce');

        $this->claim('cs_conflict');

        self::assertSame('conflict', $this->json()['status']);
        $user = $this->user('fay'.self::DOMAIN);
        self::assertSame('TEAM', $user->getUserLevel());
        self::assertSame('sub_live', $user->getSubscriptionData()['stripe_subscription_id']);
        self::assertNull($user->getStripeCustomerId());
        self::assertEmailCount(0);
    }

    public function testAnUnpaidSessionCreatesNothing(): void
    {
        $this->gateway->paidSession('cs_unpaid', 'dan'.self::DOMAIN, 'nonce', paid: false);

        $this->claim('cs_unpaid');

        self::assertSame('pending', $this->json()['status']);
        self::assertSame(0, $this->countUsers('dan'.self::DOMAIN));
    }

    public function testAnUnknownSessionIsNotFound(): void
    {
        $this->claim('cs_nobody');

        self::assertResponseStatusCodeSame(404);
        self::assertSame('PAY_FIRST_SESSION_NOT_FOUND', $this->json()['code']);
    }

    public function testTheWebhookAndTheBrowserCreateOneAccount(): void
    {
        $this->gateway->paidSession('cs_race', 'eve'.self::DOMAIN, 'nonce');
        static::getContainer()->get(PaidAccountCreator::class)->settleSession('cs_race');
        $this->client->getCookieJar()->set(new Cookie(PayFirstCheckout::NONCE_COOKIE, 'nonce'));

        $this->claim('cs_race');

        self::assertTrue($this->json()['signedIn'], 'The webhook made the account; the paying browser is still signed in.');
        self::assertSame(1, $this->countUsers('eve'.self::DOMAIN));
    }

    private function claim(string $sessionId): void
    {
        $this->post(self::PATH, ['sessionId' => $sessionId]);
    }

    /**
     * @param array<string, string> $body
     */
    private function post(string $path, array $body): void
    {
        $this->client->request('POST', $path, [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->ip], json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($payload);

        return $payload;
    }

    private function user(string $email): User
    {
        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['mail' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function countUsers(string $email): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM BUSER WHERE BMAIL = ?', [$email]);
    }

    private function removeTestUsers(): void
    {
        $this->em->clear();
        $users = $this->em->getRepository(User::class)->createQueryBuilder('u')
            ->where('u.mail LIKE :domain')->setParameter('domain', '%'.self::DOMAIN)
            ->getQuery()->getResult();
        foreach ($users as $user) {
            static::getContainer()->get(UserDeletionService::class)->deleteUser($user);
        }
    }
}
