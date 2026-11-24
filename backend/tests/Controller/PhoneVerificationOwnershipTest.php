<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * A phone number counts as verified only after the code arrived in a WhatsApp
 * message from that number. No HTTP route may turn the code the API just
 * handed out into a verified number.
 */
final class PhoneVerificationOwnershipTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private const EMAIL = 'phone-ownership@synaplan.internal';
    private const SOMEONE_ELSES_NUMBER = '+4915550001234';

    private const CHANNEL_ON = [
        'WHATSAPP_ENABLED' => 'true',
        'WHATSAPP_ACCESS_TOKEN' => 'phone-ownership-test',
        'WHATSAPP_APP_SECRET' => 'phone-ownership-test-secret',
    ];

    /** @var array<string, array{0: mixed, 1: mixed}> */
    private array $savedEnvironment = [];

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        foreach (self::CHANNEL_ON as $name => $value) {
            $this->savedEnvironment[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();
    }

    protected function tearDown(): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['mail' => self::EMAIL]);
        if ($user instanceof User) {
            $this->em->remove($user);
            $this->em->flush();
        }
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
        parent::tearDown();
    }

    public function testTheIssuedCodeCannotBeConfirmedOverHttp(): void
    {
        $user = $this->createUser();
        $this->authenticateClient($this->client, $user);

        $this->client->jsonRequest('POST', '/api/v1/user/verify-phone/request', [
            'phone_number' => self::SOMEONE_ELSES_NUMBER,
        ]);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $code = json_decode((string) $this->client->getResponse()->getContent(), true)['verification_code'];
        self::assertNotEmpty($code);

        $this->client->jsonRequest('POST', '/api/v1/user/verify-phone/confirm', ['code' => $code]);
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());

        $this->em->clear();
        $details = $this->em->getRepository(User::class)->findOneBy(['mail' => self::EMAIL])?->getUserDetails() ?? [];
        self::assertArrayNotHasKey('phone_verified_at', $details);
        self::assertArrayNotHasKey('phone_number', $details);
    }

    private function createUser(): User
    {
        $user = (new User())
            ->setMail(self::EMAIL)
            ->setType('WEB')
            ->setProviderId('phone-ownership-'.uniqid())
            ->setUserLevel('NEW');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
