<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\AI\Credential\ChatReadinessService;
use App\AI\Credential\OpenAiCompatibleEndpointRegistry;
use App\Entity\User;
use App\Tests\Trait\AuthenticatedTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class AdminOpenAiEndpointsControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private const NAME = 'itest-refresh';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->deleteEndpoint();
    }

    protected function tearDown(): void
    {
        $this->deleteEndpoint();
        parent::tearDown();
    }

    public function testSaveDropsTheProviderAvailabilitySnapshot(): void
    {
        $this->loginAdmin();
        $this->warmAvailabilitySnapshot();

        $this->postJson('/api/v1/admin/openai-endpoints', [
            'name' => self::NAME,
            'base_url' => 'http://127.0.0.1:9/v1',
        ]);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertFalse($this->availabilitySnapshotIsHit());
    }

    public function testDeleteDropsTheProviderAvailabilitySnapshot(): void
    {
        $this->loginAdmin();
        $this->postJson('/api/v1/admin/openai-endpoints', [
            'name' => self::NAME,
            'base_url' => 'http://127.0.0.1:9/v1',
        ]);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $this->warmAvailabilitySnapshot();
        $this->client->request('DELETE', '/api/v1/admin/openai-endpoints/'.self::NAME);

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertFalse($this->availabilitySnapshotIsHit());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postJson(string $uri, array $payload): void
    {
        $this->client->request('POST', $uri, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function loginAdmin(): void
    {
        $this->authenticateClient($this->client, $this->createUser('openai-endpoints-admin@synaplan.internal', 'ADMIN'));
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
            ->setProviderId('openai-endpoints-'.uniqid())
            ->setUserLevel($level);
        $user->setCreated(date('YmdHis'));
        $user->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function warmAvailabilitySnapshot(): void
    {
        static::getContainer()->get(ChatReadinessService::class)->providerAvailability();
        self::assertTrue($this->availabilitySnapshotIsHit());
    }

    private function availabilitySnapshotIsHit(): bool
    {
        /** @var CacheItemPoolInterface $cache */
        $cache = static::getContainer()->get('cache.model_config');

        return $cache->getItem('provider_availability.snapshot')->isHit();
    }

    private function deleteEndpoint(): void
    {
        $this->em->createQuery('DELETE FROM App\Entity\Config c WHERE c.ownerId = 0 AND c.group = :group AND c.setting = :setting')
            ->setParameter('group', OpenAiCompatibleEndpointRegistry::CONFIG_GROUP)
            ->setParameter('setting', 'endpoint.'.self::NAME)
            ->execute();
        $this->em->clear();
    }
}
