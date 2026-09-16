<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\UrlWatch;
use App\Entity\User;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class UrlWatchControllerTest extends WebTestCase
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

    public function testOwnerCanCreateListGetAndDelete(): void
    {
        $owner = $this->createUser('url-watch-owner@synaplan.internal');
        $this->authenticateClient($this->client, $owner);

        $this->client->request('GET', '/api/v1/url-watches');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame([], $this->json()['watches'] ?? null);

        $this->postJson('/api/v1/url-watches', ['url' => 'https://example.com/news']);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $created = $this->json()['watch'] ?? [];
        self::assertIsArray($created);
        self::assertSame('https://example.com/news', $created['url'] ?? null);
        $id = (int) ($created['id'] ?? 0);
        self::assertGreaterThan(0, $id);

        $this->client->request('GET', '/api/v1/url-watches/'.$id);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertArrayHasKey('body', $this->json()['watch'] ?? []);

        $this->client->request('GET', '/api/v1/url-watches');
        self::assertCount(1, $this->json()['watches'] ?? []);

        $this->client->request('DELETE', '/api/v1/url-watches/'.$id);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $this->em->clear();
        self::assertNull($this->em->find(UrlWatch::class, $id));
    }

    public function testInvalidUrlIs400(): void
    {
        $owner = $this->createUser('url-watch-bad@synaplan.internal');
        $this->authenticateClient($this->client, $owner);

        $this->postJson('/api/v1/url-watches', ['url' => 'not-a-url']);

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        self::assertSame('invalid_url', $this->json()['error'] ?? null);
    }

    public function testForeignWatchIs404(): void
    {
        $owner = $this->createUser('url-watch-priv-owner@synaplan.internal');
        $other = $this->createUser('url-watch-priv-other@synaplan.internal');
        $this->authenticateClient($this->client, $owner);
        $this->postJson('/api/v1/url-watches', ['url' => 'https://example.com/private']);
        $id = (int) ($this->json()['watch']['id'] ?? 0);

        $this->authenticateClient($this->client, $other);
        $this->client->request('GET', '/api/v1/url-watches/'.$id);
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
        $this->client->request('DELETE', '/api/v1/url-watches/'.$id);
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
        $this->em->clear();
        self::assertInstanceOf(UrlWatch::class, $this->em->find(UrlWatch::class, $id));
    }

    public function testSameUrlRegistersOnce(): void
    {
        $owner = $this->createUser('url-watch-once@synaplan.internal');
        $this->authenticateClient($this->client, $owner);

        $this->postJson('/api/v1/url-watches', ['url' => 'https://example.com/once/']);
        $first = (int) ($this->json()['watch']['id'] ?? 0);
        $this->postJson('/api/v1/url-watches', ['url' => 'https://EXAMPLE.com/once']);
        $second = (int) ($this->json()['watch']['id'] ?? 0);

        self::assertSame($first, $second);
        self::assertFalse($this->json()['created'] ?? true);
        $this->client->request('GET', '/api/v1/url-watches');
        self::assertCount(1, $this->json()['watches'] ?? []);
    }

    public function testPrivateUrlIsRejectedAtCreate(): void
    {
        $owner = $this->createUser('url-watch-ssrf@synaplan.internal');
        $this->authenticateClient($this->client, $owner);

        $this->postJson('/api/v1/url-watches', ['url' => 'http://127.0.0.1/page.html']);

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        $body = $this->json();
        self::assertSame('blocked_url', $body['error'] ?? null);
        self::assertSame('URL points to a private/blocked address', $body['message'] ?? null);
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
            ->setProviderId('url-watch-'.uniqid())
            ->setUserLevel('NEW');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postJson(string $uri, array $payload): void
    {
        $this->client->request(
            'POST',
            $uri,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
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
}
