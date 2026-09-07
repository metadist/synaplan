<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ApiKey;
use App\Entity\PlatformInstance;
use App\Entity\User;
use App\Repository\PlatformInstanceRepository;
use App\Security\ApiKeyScope;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Outlook add-in connect: the relay allow-list lives server-side in the
 * `outlook-builtin` row and the endpoint is reachable with PLATFORM_LINKS off.
 */
final class AddinConnectControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private const RELAY = 'https://localhost:3000/src/dialog/auth-relay.html';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->ensureOutlookBuiltinRow();
    }

    public function testRequiresSession(): void
    {
        $this->postJson('/api/v1/addin/connect', ['state' => 'n1', 'redirect_uri' => self::RELAY]);
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode());
    }

    public function testMissingStateIsRejectedWithoutMintingAKey(): void
    {
        $user = $this->createUser('addin-nostate@synaplan.internal');
        $this->authenticateClient($this->client, $user);
        $before = $this->em->getRepository(ApiKey::class)->count(['owner' => $user]);

        $this->postJson('/api/v1/addin/connect', ['redirect_uri' => self::RELAY]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        self::assertSame($before, $this->em->getRepository(ApiKey::class)->count(['owner' => $user]));
    }

    public function testAllowListedRelayGetsRedirectWithPayloadFragment(): void
    {
        $user = $this->createUser('addin-relay@synaplan.internal');
        $this->authenticateClient($this->client, $user);

        $this->postJson('/api/v1/addin/connect', [
            'state' => 'nonce-relay',
            'redirect_uri' => self::RELAY,
            'base_url' => 'https://localhost:5174',
        ]);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $body = $this->json();

        self::assertTrue($body['success']);
        self::assertIsString($body['redirect']);
        self::assertStringStartsWith(self::RELAY.'#payload=', $body['redirect']);

        $fragment = (string) parse_url($body['redirect'], \PHP_URL_FRAGMENT);
        parse_str($fragment, $params);
        self::assertIsString($params['payload'] ?? null);
        $decoded = json_decode((string) base64_decode((string) $params['payload'], true), true);
        self::assertIsArray($decoded);
        self::assertSame('nonce-relay', $decoded['state']);
        self::assertSame($user->getMail(), $decoded['email']);
        self::assertSame('https://localhost:5174', $decoded['baseUrl']);
        self::assertStringStartsWith('sk_', $decoded['apiKey']);
        self::assertSame($body['payload'], $decoded);

        $key = $this->em->getRepository(ApiKey::class)->find($decoded['keyId']);
        self::assertInstanceOf(ApiKey::class, $key);
        self::assertSame(ApiKeyScope::addinScopes(), $key->getScopes());
        self::assertStringStartsWith('Outlook Add-in (', $key->getName());
        self::assertSame((int) $user->getId(), $key->getOwnerId());
    }

    public function testForeignRelayGetsNoRedirectButStillAPayload(): void
    {
        $user = $this->createUser('addin-foreign@synaplan.internal');
        $this->authenticateClient($this->client, $user);

        $this->postJson('/api/v1/addin/connect', [
            'state' => 'nonce-foreign',
            'redirect_uri' => 'https://evil.example/relay',
        ]);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $body = $this->json();

        self::assertNull($body['redirect']);
        self::assertSame('nonce-foreign', $body['payload']['state']);
        self::assertStringStartsWith('sk_', $body['payload']['apiKey']);
    }

    public function testUnsafeBaseUrlFallsBackToRequestOrigin(): void
    {
        $user = $this->createUser('addin-baseurl@synaplan.internal');
        $this->authenticateClient($this->client, $user);

        $this->postJson('/api/v1/addin/connect', [
            'state' => 'nonce-base',
            'base_url' => 'javascript:alert(1)',
        ]);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $body = $this->json();

        self::assertNull($body['redirect']);
        self::assertSame('http://localhost', $body['payload']['baseUrl']);
    }

    private function ensureOutlookBuiltinRow(): void
    {
        $repo = static::getContainer()->get(PlatformInstanceRepository::class);
        if ($repo->findOutlookBuiltin() instanceof PlatformInstance) {
            return;
        }
        $repo->save(new PlatformInstance(
            PlatformInstance::CLIENT_OUTLOOK,
            PlatformInstance::OUTLOOK_BUILTIN_ID,
            '*',
            PlatformInstance::OUTLOOK_BUILTIN_REDIRECT_URIS,
            PlatformInstance::STATUS_ACTIVE,
        ));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postJson(string $uri, array $payload): void
    {
        $this->client->request('POST', $uri, server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode($payload));
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

    private function createUser(string $email): User
    {
        $existing = $this->em->getRepository(User::class)->findOneBy(['mail' => $email]);
        if ($existing instanceof User) {
            return $existing;
        }

        $user = (new User())
            ->setMail($email)
            ->setType('WEB')
            ->setProviderId('addin-connect-'.uniqid())
            ->setUserLevel('NEW');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
