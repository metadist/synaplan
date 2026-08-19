<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\AI\Health\ModelHealthState;
use App\AI\Service\ProviderRegistry;
use App\Entity\Model;
use App\Entity\ModelHealth;
use App\Entity\User;
use App\Model\ModelCatalog;
use App\Tests\Trait\AuthenticatedTestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contract of the admin model status page: the read endpoint reports every
 * catalogued model without ever calling a provider, and the exemption toggle
 * is what an operator uses to overrule the automation.
 */
class AdminModelHealthControllerTest extends WebTestCase
{
    use AuthenticatedTestTrait;

    private KernelBrowser $client;
    private string $token;

    /** @var list<int> */
    private array $createdHealthIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $user = $this->client->getContainer()->get('doctrine')
            ->getRepository(User::class)
            ->findOneBy(['mail' => 'admin@synaplan.com']);

        if (!$user) {
            self::markTestSkipped('Test user admin@synaplan.com not found. Run fixtures first.');
        }

        $this->token = $this->authenticateClient($this->client, $user);
    }

    protected function tearDown(): void
    {
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        foreach ($this->createdHealthIds as $id) {
            $entity = $em->find(ModelHealth::class, $id);
            if ($entity) {
                $em->remove($entity);
            }
        }
        $em->flush();

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $uri, array $payload = []): array
    {
        $this->client->request($method, $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ], [] === $payload ? null : (string) json_encode($payload));

        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function testStatusReportsEveryModelGroupedByProvider(): void
    {
        $data = $this->request('GET', '/api/v1/admin/model-health');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertTrue($data['success']);

        foreach (['total', 'online', 'degraded', 'offline', 'unconfigured', 'unknown', 'needsAttention', 'lastCheck', 'autoDisableEnabled', 'monitoringEnabled'] as $key) {
            self::assertArrayHasKey($key, $data['summary'], $key);
        }

        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $catalogued = (int) $em->getRepository(Model::class)->count([]);
        self::assertSame($catalogued, $data['summary']['total']);

        // Every model has to appear exactly once, whatever its state — a status
        // page that silently drops rows is worse than no status page.
        $listed = 0;
        foreach ($data['providers'] as $provider) {
            self::assertArrayHasKey('name', $provider);
            self::assertArrayHasKey('needsAttention', $provider);
            // The heading has to carry the provider's own spelling. Deriving
            // it in CSS was tried and turns "xAI" into "XAI".
            self::assertNotSame('', $provider['displayName'], $provider['name']);
            $listed += count($provider['models']);

            foreach ($provider['models'] as $model) {
                self::assertContains(
                    $model['state'],
                    array_map(static fn (ModelHealthState $s): string => $s->value, ModelHealthState::cases()),
                    $model['name']
                );
                self::assertIsInt($model['errorRatePercent']);
                self::assertIsBool($model['active']);
            }
        }
        self::assertSame($catalogued, $listed);
    }

    /**
     * The provider registry, not the BSERVICE column, owns how a provider is
     * spelled on screen. xAI is the case that catches a regression: the column
     * says "xAI", any CSS or PHP casing helper would render "XAI", and only
     * reading the registry gives the brand back.
     */
    public function testProviderHeadingsUseTheBrandedName(): void
    {
        $data = $this->request('GET', '/api/v1/admin/model-health');

        $byKey = [];
        foreach ($data['providers'] as $provider) {
            $byKey[$provider['name']] = $provider['displayName'];
        }

        $registry = $this->client->getContainer()->get(ProviderRegistry::class);
        foreach ($byKey as $service => $displayName) {
            $key = ModelCatalog::normalizeProvider($service);
            $provider = $registry->getUniqueProviders()[$key] ?? null;
            if (null === $provider) {
                // Not every catalogued service is a registered provider; those
                // fall back to the raw key rather than showing nothing.
                self::assertSame($service, $displayName);
                continue;
            }

            self::assertSame($provider->getDisplayName(), $displayName, $service);
        }
    }

    public function testExemptingAModelPausesAndResumesTheAutomation(): void
    {
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $model = $em->getRepository(Model::class)->findOneBy([]);
        self::assertNotNull($model, 'The model catalog must not be empty');
        $modelId = (int) $model->getId();

        $granted = $this->request('POST', "/api/v1/admin/model-health/models/{$modelId}/exempt", ['exempt' => true]);
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertTrue($granted['success']);
        self::assertGreaterThan(time(), $granted['exemptUntil']);

        $health = $em->getRepository(ModelHealth::class)->findOneBy(['modelId' => $modelId]);
        self::assertNotNull($health);
        $this->createdHealthIds[] = (int) $health->getId();
        self::assertTrue($health->isSuppressed());

        $revoked = $this->request('POST', "/api/v1/admin/model-health/models/{$modelId}/exempt", ['exempt' => false]);
        self::assertSame(0, $revoked['exemptUntil']);
    }

    public function testExemptRejectsAMissingFlagAndAnUnknownModel(): void
    {
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $model = $em->getRepository(Model::class)->findOneBy([]);
        self::assertNotNull($model);

        $this->request('POST', '/api/v1/admin/model-health/models/'.$model->getId().'/exempt', ['exempt' => 'yes']);
        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());

        $this->request('POST', '/api/v1/admin/model-health/models/99999999/exempt', ['exempt' => true]);
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }
}
