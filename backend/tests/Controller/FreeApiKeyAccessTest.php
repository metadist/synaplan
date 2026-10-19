<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ApiKey;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Synaplan Demo keys (`freeapi`) through the real kernel, without the private
 * server plugin: every gateway route answers `503 freeapi_paused` before a
 * controller runs (S16), every other route is `403 insufficient_scope` (S2),
 * and a desktop key cannot use the Demo routes (S4).
 */
final class FreeApiKeyAccessTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();
    }

    public function testGatewayRoutesAreClosedWithoutThePlugin(): void
    {
        $key = $this->createKey(['freeapi']);

        $this->send('POST', '/v1/chat/completions', $key, ['model' => 'free-chat', 'messages' => [['role' => 'user', 'content' => 'hi']]]);
        $this->assertOpenAiError(Response::HTTP_SERVICE_UNAVAILABLE, 'freeapi_paused');

        $this->send('GET', '/v1/models', $key);
        $this->assertOpenAiError(Response::HTTP_SERVICE_UNAVAILABLE, 'freeapi_paused');

        $this->send('POST', '/v1/embeddings', $key, ['model' => 'free-embed', 'input' => 'hi']);
        $this->assertOpenAiError(Response::HTTP_SERVICE_UNAVAILABLE, 'freeapi_paused');
    }

    public function testMixedFreeApiKeyIsAlsoClosed(): void
    {
        $key = $this->createKey(['freeapi', 'desktop:messages']);

        $this->send('GET', '/v1/models', $key);
        $this->assertOpenAiError(Response::HTTP_SERVICE_UNAVAILABLE, 'freeapi_paused');
    }

    public function testEveryOtherRouteIsInsufficientScope(): void
    {
        $key = $this->createKey(['freeapi']);

        foreach ([
            ['POST', '/v1/messages'],
            ['GET', '/api/v1/auth/me'],
            ['GET', '/api/v1/files'],
            ['GET', '/api/v1/admin/config/values'],
        ] as [$method, $path]) {
            $this->send($method, $path, $key);
            self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode(), $method.' '.$path);
            self::assertSame('insufficient_scope', $this->json()['code'] ?? null, $method.' '.$path);
        }
    }

    public function testDesktopKeyStillListsModels(): void
    {
        $key = $this->createKey(['desktop:messages']);

        $this->send('GET', '/v1/models', $key);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame('list', $this->json()['object'] ?? null);
    }

    public function testIssuanceIsPublicButAbsentWithoutThePlugin(): void
    {
        $this->client->request('POST', '/v1/free/keys', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function send(string $method, string $path, string $key, ?array $body = null): void
    {
        $this->client->request(
            $method,
            $path,
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$key, 'CONTENT_TYPE' => 'application/json'],
            content: null === $body ? null : (string) json_encode($body),
        );
    }

    private function assertOpenAiError(int $status, string $code): void
    {
        $response = $this->client->getResponse();
        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame($code, $this->json()['error']['code'] ?? null);
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

    /**
     * @param list<string> $scopes
     */
    private function createKey(array $scopes): string
    {
        $user = (new User())
            ->setMail('freeapi-access-'.bin2hex(random_bytes(4)).'@synaplan.internal')
            ->setType('WEB')
            ->setProviderId('freeapi-access-'.uniqid())
            ->setUserLevel('BUSINESS');
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        $secret = 'sk_'.bin2hex(random_bytes(20));
        $this->em->persist((new ApiKey())
            ->setOwner($user)
            ->setKey($secret)
            ->setStatus('active')
            ->setName('Synaplan Demo test key')
            ->setScopes($scopes));
        $this->em->flush();

        return $secret;
    }
}
