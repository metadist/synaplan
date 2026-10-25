<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Service\Auth\TokenService;
use App\Service\Billing\PayFirst\CheckoutSessionGateway;
use App\Service\Billing\PayFirst\PayFirstCheckout;
use App\Service\Billing\PayFirst\PayFirstConfig;
use App\Tests\Unit\Service\Billing\PayFirst\RecordingGateway;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/v1/checkout/start (pay first, PF1): absent unless switched on;
 * when on, an email and a plan open Checkout, the browser gets the nonce
 * cookie whose hash the session carries, and no account exists afterwards.
 */
final class CheckoutStartControllerTest extends WebTestCase
{
    private const PATH = '/api/v1/checkout/start';
    private const EMAIL = 'buyer@pay-first.synaplan.internal';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private RecordingGateway $gateway;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->gateway = new RecordingGateway();
        static::getContainer()->set(CheckoutSessionGateway::class, $this->gateway);
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM BCONFIG WHERE BOWNERID = 0 AND BGROUP = ? AND BSETTING = ?', [PayFirstConfig::CONFIG_GROUP, PayFirstConfig::KEY]);
        parent::tearDown();
    }

    public function testAbsentUntilSwitchedOn(): void
    {
        $this->start(['email' => self::EMAIL, 'plan' => 'PRO']);

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
        self::assertSame('PAY_FIRST_OFF', $this->json()['code']);
        self::assertSame([], $this->gateway->created);
    }

    public function testAnEmailAndAPlanOpenCheckoutWithoutCreatingAnAccount(): void
    {
        $this->switchOn();

        $this->start(['email' => self::EMAIL, 'plan' => 'PRO']);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        self::assertSame('https://checkout.stripe.test/s/1', $this->json()['checkoutUrl']);

        $cookie = null;
        foreach ($this->client->getResponse()->headers->getCookies() as $candidate) {
            if (PayFirstCheckout::NONCE_COOKIE === $candidate->getName()) {
                $cookie = $candidate;
            }
        }
        self::assertNotNull($cookie, 'the starting browser keeps the nonce');
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame(hash('sha256', (string) $cookie->getValue()), $this->gateway->created[0]['metadata']['start_nonce_hash']);
        self::assertSame(self::EMAIL, $this->gateway->created[0]['customer_email']);
        self::assertSame(0, $this->em->getRepository(User::class)->count(['mail' => self::EMAIL]), 'no account before the payment');
    }

    public function testAStaleSessionCookieDoesNotBlockTheStart(): void
    {
        $this->switchOn();
        $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie(TokenService::ACCESS_COOKIE, 'stale.token.from-before-a-secret-rotation'));

        $this->start(['email' => self::EMAIL, 'plan' => 'PRO']);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
    }

    public function testRefusedInTheNativeApp(): void
    {
        $this->switchOn();

        $this->client->request('POST', self::PATH, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_USER_AGENT' => 'Mozilla/5.0 Synaplan Mobile V4.2.0'], json_encode(['email' => self::EMAIL, 'plan' => 'PRO'], JSON_THROW_ON_ERROR));

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
        self::assertSame('NATIVE_CHANNEL_REQUIRES_IAP', $this->json()['code']);
        self::assertSame([], $this->gateway->created);
    }

    public function testAnInvalidEmailOrPlanIsRefused(): void
    {
        $this->switchOn();

        $this->start(['email' => 'nope', 'plan' => 'PRO']);
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        self::assertSame('PAY_FIRST_EMAIL_INVALID', $this->json()['code']);

        $this->start(['email' => self::EMAIL, 'plan' => 'GOLD']);
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        self::assertSame('PAY_FIRST_PLAN_INVALID', $this->json()['code']);
        self::assertSame([], $this->gateway->created);
    }

    private function switchOn(): void
    {
        static::getContainer()->get(ConfigRepository::class)->setValue(0, PayFirstConfig::CONFIG_GROUP, PayFirstConfig::KEY, 'true');
        $this->em->flush();
    }

    /**
     * @param array<string, string> $body
     */
    private function start(array $body): void
    {
        $this->client->request('POST', self::PATH, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
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
}
