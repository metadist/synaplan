<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class AdminPlugsWebSearchControllerTest extends WebTestCase
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

    public function testNonAdminIsForbidden(): void
    {
        $user = $this->createUser('plugs-websearch-user@synaplan.internal', 'NEW');
        $this->authenticateClient($this->client, $user);
        $this->client->request('GET', '/api/v1/admin/plugs/web-search');

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    public function testAdminGetReturnsProvidersAndBraveDefault(): void
    {
        $this->loginAdmin();
        $this->client->request('GET', '/api/v1/admin/plugs/web-search');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $body = $this->json();
        self::assertSame('brave', $body['active'] ?? null);
        self::assertSame('', $body['fallback'] ?? null);
        self::assertFalse($body['userOverrideAllowed'] ?? true);
        $keys = array_column($body['providers'] ?? [], 'key');
        self::assertContains('brave', $keys);
        self::assertContains('searxng', $keys);
        self::assertContains('tavily', $keys);
        self::assertContains('exa', $keys);
        self::assertContains('firecrawl', $keys);
        self::assertContains('perplexity', $keys);
    }

    public function testPutUnknownProviderReturns422(): void
    {
        $this->loginAdmin();
        $this->client->request(
            'PUT',
            '/api/v1/admin/plugs/web-search',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'active' => 'not-a-provider',
                'fallback' => '',
                'userOverrideAllowed' => false,
            ], JSON_THROW_ON_ERROR),
        );

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
    }

    public function testPutKnownProviderAndRestoreDefault(): void
    {
        $this->loginAdmin();
        $this->client->request(
            'PUT',
            '/api/v1/admin/plugs/web-search',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'active' => 'searxng',
                'fallback' => 'brave',
                'userOverrideAllowed' => true,
            ], JSON_THROW_ON_ERROR),
        );

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $body = $this->json();
        self::assertSame('searxng', $body['active'] ?? null);
        self::assertSame('brave', $body['fallback'] ?? null);
        self::assertTrue($body['userOverrideAllowed'] ?? false);

        $this->client->request(
            'PUT',
            '/api/v1/admin/plugs/web-search',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'active' => 'brave',
                'fallback' => '',
                'userOverrideAllowed' => false,
            ], JSON_THROW_ON_ERROR),
        );
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
    }

    private function loginAdmin(): void
    {
        $admin = $this->createUser('plugs-websearch-admin@synaplan.internal', 'ADMIN');
        $this->authenticateClient($this->client, $admin);
    }

    private function createUser(string $email, string $level): User
    {
        $existing = $this->em->getRepository(User::class)->findOneBy(['mail' => $email]);
        if ($existing instanceof User) {
            $existing->setUserLevel($level);
            $this->em->flush();

            return $existing;
        }

        $user = (new User())
            ->setMail($email)
            ->setType('WEB')
            ->setProviderId('plugs-websearch-'.uniqid())
            ->setUserLevel($level);
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }
}
