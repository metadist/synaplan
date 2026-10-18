<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\AI\Health\ModelHealthState;
use App\Entity\Model;
use App\Entity\ModelHealth;
use App\Entity\User;
use App\Tests\Trait\AuthenticatedTestTrait;
use App\Tests\Trait\CreatesTestEntities;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The model health writes an admin makes: pausing and resuming automatic
 * disabling is stored, a traffic verdict is reset to unknown, and a user
 * who is not an admin is refused without a write. The tests run on a model
 * of their own, so no catalog model's health row is touched.
 */
final class AdminModelHealthControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;
    use CreatesTestEntities;

    private KernelBrowser $client;
    private int $modelId;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $model = (new Model())->setService('test')->setName('Health test model')->setTag('chat')->setProviderId('health-test-'.bin2hex(random_bytes(4)));
        $this->modelId = (int) $this->persistEntity($model)->getId();
    }

    protected function tearDown(): void
    {
        $health = $this->health();
        if (null !== $health) {
            $this->em->remove($health);
            $this->em->flush();
        }
        $this->removeCreatedEntities();
        parent::tearDown();
    }

    public function testPausingAndResumingAutomaticDisablingIsStored(): void
    {
        $this->authenticateClient($this->client, $this->admin());

        $this->postJson('/exempt', ['exempt' => true]);
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(time(), $this->health()?->getSuppressUntil());

        $this->postJson('/exempt', ['exempt' => false]);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->health()?->getSuppressUntil());
    }

    public function testATrafficVerdictIsResetToUnknown(): void
    {
        $this->em->persist((new ModelHealth())->setModelId($this->modelId)->setSource(ModelHealth::SOURCE_TRAFFIC)->setState(ModelHealthState::Degraded));
        $this->em->flush();
        $this->authenticateClient($this->client, $this->admin());

        $this->postJson('/reset', []);

        self::assertResponseIsSuccessful();
        self::assertSame(ModelHealthState::Unknown, $this->health()?->getState());
    }

    public function testAUserWhoIsNotAnAdminIsRefusedWithoutAWrite(): void
    {
        $this->authenticateClient($this->client, $this->createUser('health-user'));

        $this->postJson('/exempt', ['exempt' => true]);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->health());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postJson(string $action, array $body): void
    {
        $this->client->request('POST', '/api/v1/admin/model-health/models/'.$this->modelId.$action, [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($body));
    }

    private function admin(): User
    {
        $admin = $this->createUser('health-admin')->setUserLevel('ADMIN');
        $this->em->flush();

        return $admin;
    }

    private function health(): ?ModelHealth
    {
        $this->em->clear();

        return $this->em->getRepository(ModelHealth::class)->findOneBy(['modelId' => $this->modelId]);
    }
}
