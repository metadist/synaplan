<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Plug\PlugConfigService;
use App\Repository\ConfigRepository;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class UserPlugsControllerTest extends WebTestCase
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

    public function testGetReturnsAllowedFalseByDefault(): void
    {
        $user = $this->createUser('user-plugs-get@synaplan.internal', 'NEW');
        $this->authenticateClient($this->client, $user);
        $this->client->request('GET', '/api/v1/config/plugs/web-search');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $body = $this->json();
        self::assertFalse($body['allowed'] ?? true);
        self::assertSame('brave', $body['active'] ?? null);
        self::assertNotEmpty($body['options'] ?? []);
    }

    public function testPutIsForbiddenWhenOverrideIsOff(): void
    {
        $user = $this->createUser('user-plugs-put@synaplan.internal', 'NEW');
        $this->authenticateClient($this->client, $user);
        $this->client->request(
            'PUT',
            '/api/v1/config/plugs/web-search',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['provider' => 'tavily'], JSON_THROW_ON_ERROR),
        );

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    public function testPutSucceedsWhenOverrideIsAllowed(): void
    {
        $config = static::getContainer()->get(ConfigRepository::class);
        $config->setValue(0, PlugConfigService::CONFIG_GROUP, PlugConfigService::KEY_WEB_SEARCH_USER_OVERRIDE_ALLOWED, '1');

        $user = $this->createUser('user-plugs-allowed@synaplan.internal', 'NEW');
        $this->authenticateClient($this->client, $user);
        $this->client->request(
            'PUT',
            '/api/v1/config/plugs/web-search',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['provider' => 'tavily'], JSON_THROW_ON_ERROR),
        );

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $body = $this->json();
        self::assertTrue($body['allowed'] ?? false);
        self::assertSame('tavily', $body['active'] ?? null);

        $config->setValue(0, PlugConfigService::CONFIG_GROUP, PlugConfigService::KEY_WEB_SEARCH_USER_OVERRIDE_ALLOWED, '0');
        $config->deleteValue((int) $user->getId(), PlugConfigService::CONFIG_GROUP, PlugConfigService::KEY_WEB_SEARCH_PROVIDER);
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
            ->setProviderId('user-plugs-'.uniqid())
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
