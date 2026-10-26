<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ApiKey;
use App\Entity\User;
use App\Exception\ApiKeyPlanRequiredException;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * API keys are a paid-plan feature while billing is configured (the test
 * suite forces Stripe on). The in-app session is a different door.
 */
final class ApiKeyPlanTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();
    }

    public function testFreeAccountCannotCreateAKey(): void
    {
        $user = $this->user('api-plan-free-create@synaplan.internal', 'NEW');
        $this->authenticateClient($this->client, $user);
        $this->post('/api/v1/apikeys', ['name' => 'Bot']);

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
        self::assertSame(ApiKeyPlanRequiredException::CODE, $this->json()['code']);
        self::assertSame(ApiKeyPlanRequiredException::MESSAGE, $this->json()['message']);
        self::assertSame([], $this->em->getRepository(ApiKey::class)->findBy(['ownerId' => $user->getId()]));
    }

    public function testPaidAccountCanCreateAKey(): void
    {
        $user = $this->user('api-plan-pro-create@synaplan.internal', 'PRO');
        $this->authenticateClient($this->client, $user);
        $this->post('/api/v1/apikeys', ['name' => 'Bot']);

        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        self::assertStringStartsWith('sk_', (string) ($this->json()['api_key']['key'] ?? ''));
    }

    public function testAnUnverifiedAddressGetsNoKeyUntilItIsConfirmed(): void
    {
        // A pay-first buyer is signed in once before anyone opened our mail;
        // a key minted then would outlive the mailbox owner's takeover.
        $user = $this->user('api-plan-unverified@synaplan.internal', 'PRO');
        $user->setEmailVerified(false);
        $this->em->flush();
        $this->authenticateClient($this->client, $user);

        $this->post('/api/v1/apikeys', ['name' => 'Bot']);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
        self::assertSame(ApiKeyPlanRequiredException::CODE_ADDRESS_UNVERIFIED, $this->json()['code']);
        self::assertSame(ApiKeyPlanRequiredException::MESSAGE_ADDRESS_UNVERIFIED, $this->json()['error']);
        self::assertSame([], $this->em->getRepository(ApiKey::class)->findBy(['ownerId' => $user->getId()]));

        $user->setEmailVerified(true);
        $this->em->flush();
        $this->post('/api/v1/apikeys', ['name' => 'Bot']);
        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());
    }

    public function testFreeAccountKeyIsRejected(): void
    {
        $user = $this->user('api-plan-free-use@synaplan.internal', 'NEW');
        $secret = $this->persistKey($user);

        $this->client->request('GET', '/api/v1/auth/me', server: ['HTTP_X_API_KEY' => $secret]);

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
        self::assertSame(ApiKeyPlanRequiredException::CODE, $this->json()['code']);
    }

    public function testPaidAccountKeyAuthenticates(): void
    {
        $user = $this->user('api-plan-pro-use@synaplan.internal', 'PRO');
        $secret = $this->persistKey($user);

        $this->client->request('GET', '/api/v1/auth/me', server: ['HTTP_X_API_KEY' => $secret]);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
    }

    public function testFreeAccountCanStillTurnAKeyOff(): void
    {
        $user = $this->user('api-plan-free-off@synaplan.internal', 'NEW');
        $key = (new ApiKey())
            ->setOwner($user)
            ->setKey('sk_'.bin2hex(random_bytes(20)))
            ->setStatus('active')
            ->setName('Old key')
            ->setScopes(['*']);
        $this->em->persist($key);
        $this->em->flush();

        $this->authenticateClient($this->client, $user);
        $this->client->request(
            'PATCH',
            '/api/v1/apikeys/'.$key->getId(),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['status' => 'inactive']),
        );

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->em->clear();
        $stored = $this->em->getRepository(ApiKey::class)->find($key->getId());
        self::assertInstanceOf(ApiKey::class, $stored);
        self::assertSame('inactive', $stored->getStatus());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(string $uri, array $payload): void
    {
        $this->client->request(
            'POST',
            $uri,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode($payload),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded, 'Response was not JSON: '.$this->client->getResponse()->getContent());

        return $decoded;
    }

    private function user(string $email, string $level): User
    {
        $existing = $this->em->getRepository(User::class)->findOneBy(['mail' => $email]);
        if ($existing instanceof User) {
            $existing->setUserLevel($level);
            $existing->setEmailVerified(true);
            $this->em->flush();

            return $existing;
        }

        $user = (new User())
            ->setMail($email)
            ->setType('WEB')
            ->setProviderId('api-plan-'.uniqid())
            ->setUserLevel($level);
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function persistKey(User $owner): string
    {
        $secret = 'sk_'.bin2hex(random_bytes(20));
        $this->em->persist((new ApiKey())
            ->setOwner($owner)
            ->setKey($secret)
            ->setStatus('active')
            ->setName('Plan test')
            ->setScopes(['*']));
        $this->em->flush();

        return $secret;
    }
}
