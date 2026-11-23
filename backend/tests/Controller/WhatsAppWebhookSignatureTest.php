<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The WhatsApp webhook acts as the account behind the sender's phone number.
 * Only Meta may name that sender: every call needs Meta's signature of the
 * body, made with the app secret.
 */
final class WhatsAppWebhookSignatureTest extends WebTestCase
{
    private const APP_SECRET = 'signature-test-app-secret';
    private const ENVIRONMENT = [
        'WHATSAPP_ENABLED' => 'true',
        'WHATSAPP_ACCESS_TOKEN' => 'signature-test',
        'WHATSAPP_APP_SECRET' => self::APP_SECRET,
        'WHATSAPP_WEBHOOK_VERIFY_TOKEN' => '',
    ];

    /** @var array<string, array{0: mixed, 1: mixed}> */
    private array $savedEnvironment = [];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->setEnvironment(self::ENVIRONMENT);
        self::ensureKernelShutdown();
        $this->client = static::createClient();
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnvironment as $name => [$env, $server]) {
            if (null === $env) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $env;
            }
            if (null === $server) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }
        }
        $this->savedEnvironment = [];
        parent::tearDown();
    }

    public function testAnUnsignedMessageIsRefusedAndCreatesNoAccount(): void
    {
        $phone = '49160'.random_int(1000000, 9999999);

        $this->post($this->message($phone), []);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
        self::assertSame(['success' => false, 'error' => 'Invalid signature'], $this->json());
        self::assertSame(0, $this->accountsWithPhone($phone));
    }

    public function testASignatureMadeWithAnotherKeyIsRefused(): void
    {
        $phone = '49160'.random_int(1000000, 9999999);
        $body = $this->message($phone);

        foreach (['guessed', '', 'signature-test'] as $key) {
            $this->post($body, ['HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $key)]);
            self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
        }
        self::assertSame(0, $this->accountsWithPhone($phone));
    }

    public function testASignatureForAnotherBodyIsRefused(): void
    {
        $signed = $this->message('491601111111');
        $sent = $this->message('491602222222');

        $this->post($sent, ['HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $signed, self::APP_SECRET)]);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
    }

    public function testMetasSignatureLetsTheCallThrough(): void
    {
        $body = '{"object":"whatsapp_business_account"}';

        $this->post($body, ['HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::APP_SECRET)]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode(), 'past the signature check, refused for its content');
        self::assertSame('Invalid WhatsApp webhook payload', $this->json()['error']);
    }

    public function testWithoutAnAppSecretTheChannelIsOff(): void
    {
        $this->setEnvironment(['WHATSAPP_APP_SECRET' => '']);
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $body = $this->message('491603333333');

        $this->post($body, ['HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, '')]);

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
        self::assertSame('feature_not_configured', $this->json()['error']);
    }

    public function testTheHandshakeDoesNotAcceptAnEmptyVerifyToken(): void
    {
        self::assertSame(Response::HTTP_FORBIDDEN, $this->handshakeStatus('hub.mode=subscribe&hub.verify_token=&hub.challenge=abc'));
        self::assertSame(Response::HTTP_FORBIDDEN, $this->handshakeStatus('hub.mode=subscribe&hub.challenge=abc'));
    }

    private function handshakeStatus(string $query): int
    {
        $this->client->request('GET', '/api/v1/webhooks/whatsapp?'.$query);

        return $this->client->getResponse()->getStatusCode();
    }

    public function testTheHandshakeAnswersTheChallengeForTheConfiguredToken(): void
    {
        $this->setEnvironment(['WHATSAPP_WEBHOOK_VERIFY_TOKEN' => 'handshake-token']);
        self::ensureKernelShutdown();
        $this->client = static::createClient();

        $this->client->request('GET', '/api/v1/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=handshake-token&hub.challenge=abc');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame('abc', $this->client->getResponse()->getContent());

        $this->client->request('GET', '/api/v1/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=other&hub.challenge=abc');
        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @param array<string, string> $values
     */
    private function setEnvironment(array $values): void
    {
        foreach ($values as $name => $value) {
            $this->savedEnvironment[$name] ??= [$_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
    }

    /**
     * @param array<string, string> $server
     */
    private function post(string $body, array $server): void
    {
        $this->client->request('POST', '/api/v1/webhooks/whatsapp', [], [], ['CONTENT_TYPE' => 'application/json'] + $server, $body);
    }

    private function message(string $phone): string
    {
        return json_encode(['entry' => [['id' => '1', 'changes' => [[
            'field' => 'messages',
            'value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => ['display_phone_number' => '15550001234', 'phone_number_id' => '1098765'],
                'messages' => [['from' => $phone, 'id' => 'wamid.'.bin2hex(random_bytes(6)), 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => 'ping']]],
            ],
        ]]]]], JSON_THROW_ON_ERROR);
    }

    private function accountsWithPhone(string $phone): int
    {
        $statement = static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->prepare("SELECT COUNT(*) FROM BUSER WHERE JSON_EXTRACT(BUSERDETAILS, '$.anonymous_phone') = :phone");
        $statement->bindValue('phone', $phone);

        return (int) $statement->executeQuery()->fetchOne();
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 16, JSON_THROW_ON_ERROR);
    }
}
