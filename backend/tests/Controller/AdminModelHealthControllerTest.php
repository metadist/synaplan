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
 * who is not an admin is refused without a write.
 */
final class AdminModelHealthControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;
    use CreatesTestEntities;

    private KernelBrowser $client;
    private int $modelId;
    private ?ModelHealth $previous = null;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $model = $this->em->getRepository(Model::class)->findOneBy([], ['id' => 'ASC']);
        self::assertInstanceOf(Model::class, $model, 'The test database has no model.');
        $this->modelId = (int) $model->getId();
        $existing = $this->health();
        $this->previous = null === $existing ? null : clone $existing;
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $health = $this->health();
        if (null !== $health && null === $this->previous) {
            $this->em->remove($health);
        } elseif (null !== $health) {
            $health->setSuppressUntil($this->previous->getSuppressUntil())->setState($this->previous->getState())->setSource($this->previous->getSource());
        }
        $this->em->flush();
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
        $health = $this->health() ?? (new ModelHealth())->setModelId($this->modelId);
        $health->setSource(ModelHealth::SOURCE_TRAFFIC)->setState(ModelHealthState::Degraded);
        $this->em->persist($health);
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
        self::assertSame($this->previous?->getSuppressUntil() ?? 0, $this->health()?->getSuppressUntil() ?? 0);
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
