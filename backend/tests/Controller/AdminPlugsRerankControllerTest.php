<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Plug\PlugConfigService;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class AdminPlugsRerankControllerTest extends WebTestCase
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
        $user = $this->createUser('plugs-rerank-user@synaplan.internal', 'NEW');
        $this->authenticateClient($this->client, $user);
        $this->client->request('GET', '/api/v1/admin/plugs/rerank');

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    public function testAdminGetReturnsDisabledDefault(): void
    {
        $this->loginAdmin();
        $this->client->request('GET', '/api/v1/admin/plugs/rerank');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $body = $this->json();
        self::assertFalse($body['enabled'] ?? true);
        self::assertArrayHasKey('modelKey', $body);
        self::assertNull($body['modelKey']);
        self::assertSame(4, $body['multiplier'] ?? null);
        self::assertSame(800, $body['budgetMs'] ?? null);
        self::assertFalse($body['llmFallback'] ?? true);
        $adapterKeys = array_column($body['adapters'] ?? [], 'key');
        self::assertContains('http', $adapterKeys);
        self::assertContains('llm', $adapterKeys);
        $modelKeys = array_column($body['models'] ?? [], 'key');
        self::assertContains('jina:jina-reranker-v2-base-multilingual:rerank', $modelKeys);
    }

    public function testPutEnabledWithoutModelReturns422(): void
    {
        $this->loginAdmin();
        $this->client->request(
            'PUT',
            '/api/v1/admin/plugs/rerank',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'enabled' => true,
                'modelKey' => null,
                'multiplier' => 4,
                'budgetMs' => 800,
                'llmFallback' => false,
            ], JSON_THROW_ON_ERROR),
        );

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
    }

    public function testPutUnknownModelReturns422(): void
    {
        $this->loginAdmin();
        $this->client->request(
            'PUT',
            '/api/v1/admin/plugs/rerank',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'enabled' => true,
                'modelKey' => 'not-a-rerank-model',
                'multiplier' => 4,
                'budgetMs' => 800,
                'llmFallback' => false,
            ], JSON_THROW_ON_ERROR),
        );

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
    }

    public function testPutKeepsDefaultOffWhenDisabled(): void
    {
        $this->loginAdmin();
        $this->client->request(
            'PUT',
            '/api/v1/admin/plugs/rerank',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'enabled' => false,
                'modelKey' => 'jina:jina-reranker-v2-base-multilingual:rerank',
                'multiplier' => 3,
                'budgetMs' => 600,
                'llmFallback' => false,
            ], JSON_THROW_ON_ERROR),
        );

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $body = $this->json();
        self::assertFalse($body['enabled'] ?? true);
        self::assertSame(3, $body['multiplier'] ?? null);
        self::assertSame('jina:jina-reranker-v2-base-multilingual:rerank', $body['modelKey'] ?? null);

        $this->client->request(
            'PUT',
            '/api/v1/admin/plugs/rerank',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'enabled' => false,
                'modelKey' => null,
                'multiplier' => PlugConfigService::DEFAULT_RERANK_MULTIPLIER,
                'budgetMs' => PlugConfigService::DEFAULT_RERANK_LATENCY_MS,
                'llmFallback' => false,
            ], JSON_THROW_ON_ERROR),
        );
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
    }

    private function loginAdmin(): void
    {
        $admin = $this->createUser('plugs-rerank-admin@synaplan.internal', 'ADMIN');
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
            ->setProviderId('plugs-rerank-'.uniqid())
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
