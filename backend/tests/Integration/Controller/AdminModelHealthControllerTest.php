<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\AI\Health\FailureKind;
use App\AI\Health\ModelHealthState;
use App\AI\Service\ProviderRegistry;
use App\Entity\Model;
use App\Entity\ModelHealth;
use App\Entity\User;
use App\Model\ModelCatalog;
use App\Repository\ModelHealthRepository;
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

    /** @var array<int, array{retiredOn: ?\DateTimeImmutable, successorId: ?int}> model id => retirement fields before the test */
    private array $originalRetirements = [];

    /** @var array<int, int> model id => BACTIVE before the test */
    private array $originalActive = [];

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
        foreach ($this->originalRetirements as $modelId => $original) {
            $model = $em->find(Model::class, $modelId);
            if ($model) {
                $model->setRetiredOn($original['retiredOn'])->setSuccessorId($original['successorId']);
            }
        }
        foreach ($this->originalActive as $modelId => $active) {
            $model = $em->find(Model::class, $modelId);
            if ($model) {
                $model->setActive($active);
            }
        }
        $em->flush();

        parent::tearDown();
    }

    /**
     * A live model (not retired) plus a second live row to stand in as its
     * successor, retired for the duration of the test and restored after.
     *
     * @return array{0: Model, 1: Model}
     */
    private function retireALiveModel(): array
    {
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        /** @var list<Model> $live */
        $live = $em->getRepository(Model::class)->findBy(['retiredOn' => null, 'active' => 1], ['id' => 'ASC'], 2);
        self::assertCount(2, $live, 'The catalog needs two live models for this test');
        [$model, $successor] = $live;

        $this->originalRetirements[(int) $model->getId()] = [
            'retiredOn' => $model->getRetiredOn(),
            'successorId' => $model->getSuccessorId(),
        ];
        $model->setRetiredOn(new \DateTimeImmutable('2026-09-01'))->setSuccessorId((int) $successor->getId());

        return [$model, $successor];
    }

    private function markOffline(int $modelId): ModelHealth
    {
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $health = $em->getRepository(ModelHealth::class)->findOneBy(['modelId' => $modelId]);
        if (null === $health) {
            $health = (new ModelHealth())->setModelId($modelId);
            $em->persist($health);
        }
        $health
            ->setState(ModelHealthState::Offline)
            ->setSource(ModelHealth::SOURCE_PROBE)
            ->setKind(FailureKind::Permanent->value)
            ->setMessage('Provider no longer serves this model.')
            ->setUpdated(time());
        $em->flush();
        $this->createdHealthIds[] = (int) $health->getId();

        return $health;
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

        foreach (['total', 'online', 'degraded', 'offline', 'unconfigured', 'unknown', 'switchedOff', 'retired', 'needsAttention', 'lastCheck', 'autoDisableEnabled', 'monitoringEnabled'] as $key) {
            self::assertArrayHasKey($key, $data['summary'], $key);
        }

        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $catalogued = (int) $em->getRepository(Model::class)->count([]);
        self::assertSame($catalogued, $data['summary']['total']);

        // The tiles partition the catalog: each model is counted exactly once.
        $summary = $data['summary'];
        self::assertSame(
            $catalogued,
            $summary['online'] + $summary['degraded'] + $summary['offline'] + $summary['unconfigured']
                + $summary['unknown'] + $summary['switchedOff'] + $summary['retired'],
        );

        $retiredInCatalog = (int) $em->getRepository(Model::class)->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.retiredOn IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
        self::assertIsArray($data['retired']);
        self::assertCount($retiredInCatalog, $data['retired']);
        self::assertSame($retiredInCatalog, $data['summary']['retired']);

        // Every model has to appear exactly once, whatever its state — a status
        // page that silently drops rows is worse than no status page. Retired
        // rows are the one split: they sit in their own list, never in a
        // provider section.
        $listed = count($data['retired']);
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
                self::assertIsBool($model['needsAttention']);
            }
        }
        self::assertSame($catalogued, $listed);
    }

    /**
     * The evaluator stops checking a model once BRETIREDON is set, so the
     * verdict stored before the retirement is frozen. Reading it as live state
     * is how fifteen long-retired models kept "19 model(s) need attention" on
     * the page and the Operate badge.
     */
    public function testARetiredModelIsListedApartAndNeverNeedsAttention(): void
    {
        [$model, $successor] = $this->retireALiveModel();
        $modelId = (int) $model->getId();

        $model->setRetiredOn(null);
        $this->markOffline($modelId);
        $before = $this->request('GET', '/api/v1/admin/model-health');

        // The request rebooted the kernel; work on a freshly loaded row.
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $model = $em->find(Model::class, $modelId);
        self::assertNotNull($model);
        $model->setRetiredOn(new \DateTimeImmutable('2026-09-01'));
        $em->flush();
        $after = $this->request('GET', '/api/v1/admin/model-health');

        self::assertSame($before['summary']['needsAttention'] - 1, $after['summary']['needsAttention']);
        self::assertSame($before['summary']['offline'] - 1, $after['summary']['offline']);
        self::assertSame($before['summary']['retired'] + 1, $after['summary']['retired']);

        foreach ($after['providers'] as $provider) {
            self::assertNotContains($modelId, array_column($provider['models'], 'id'), 'A retired model must not sit in a provider section');
        }

        $entry = null;
        foreach ($after['retired'] as $retired) {
            if ($modelId === $retired['id']) {
                $entry = $retired;
            }
        }
        self::assertNotNull($entry, 'The retired model must be listed');
        self::assertSame('2026-09-01', $entry['retiredOn']);
        self::assertSame($successor->getName(), $entry['successorName']);
        self::assertSame($model->getProviderId(), $entry['providerId']);
        self::assertNotSame('', $entry['providerDisplayName']);
    }

    /**
     * Switching a model off is the operator's own answer to "this one is
     * broken". It stays listed with its real state so a recovery is visible,
     * but it must stop counting — unless the monitor itself switched it off,
     * because then the failure is still news.
     */
    public function testASwitchedOffModelStaysListedButNeverNeedsAttention(): void
    {
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $model = $em->getRepository(Model::class)->findOneBy(['retiredOn' => null, 'active' => 1], ['id' => 'ASC']);
        self::assertInstanceOf(Model::class, $model, 'The catalog needs a live, active model for this test');
        $modelId = (int) $model->getId();
        $this->originalActive[$modelId] = $model->getActive();

        $this->markOffline($modelId);
        $before = $this->request('GET', '/api/v1/admin/model-health');
        self::assertTrue($this->entryFor($before, $modelId)['needsAttention']);

        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $em->find(Model::class, $modelId)?->setActive(0);
        $em->flush();
        $after = $this->request('GET', '/api/v1/admin/model-health');

        self::assertSame($before['summary']['needsAttention'] - 1, $after['summary']['needsAttention']);
        self::assertSame($before['summary']['offline'] - 1, $after['summary']['offline']);
        self::assertSame($before['summary']['switchedOff'] + 1, $after['summary']['switchedOff']);
        $entry = $this->entryFor($after, $modelId);
        self::assertSame('offline', $entry['state']);
        self::assertFalse($entry['active']);
        self::assertFalse($entry['needsAttention']);

        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $health = $em->getRepository(ModelHealth::class)->findOneBy(['modelId' => $modelId]);
        self::assertNotNull($health);
        $health->setAutoDisabled(true);
        $em->flush();
        $autoDisabled = $this->request('GET', '/api/v1/admin/model-health');

        self::assertSame($before['summary']['needsAttention'], $autoDisabled['summary']['needsAttention']);
        self::assertTrue($this->entryFor($autoDisabled, $modelId)['needsAttention']);
    }

    /**
     * @param array<string, mixed> $snapshot
     *
     * @return array<string, mixed>
     */
    private function entryFor(array $snapshot, int $modelId): array
    {
        foreach ($snapshot['providers'] as $provider) {
            foreach ($provider['models'] as $model) {
                if ($modelId === $model['id']) {
                    return $model;
                }
            }
        }

        self::fail(sprintf('Model %d is not listed under any provider', $modelId));
    }

    public function testPruneRetiredDropsOnlyTheFrozenVerdictsOfRetiredModels(): void
    {
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        [$model, $successor] = $this->retireALiveModel();
        $em->flush();

        $retiredHealthId = (int) $this->markOffline((int) $model->getId())->getId();
        $liveHealthId = (int) $this->markOffline((int) $successor->getId())->getId();

        $repository = $em->getRepository(ModelHealth::class);
        self::assertInstanceOf(ModelHealthRepository::class, $repository);
        self::assertGreaterThanOrEqual(1, $repository->pruneRetired());

        $em->clear();
        self::assertNull($em->find(ModelHealth::class, $retiredHealthId));
        self::assertNotNull($em->find(ModelHealth::class, $liveHealthId));
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

    /**
     * Import listing re-checks store SOURCE_LISTING; the status endpoint must
     * report it verbatim. The OpenAPI enum once omitted it, which made the
     * frontend schema reject the whole snapshot (#2045).
     */
    public function testStatusReportsListingSourceForImportRechecks(): void
    {
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $model = $em->getRepository(Model::class)->findOneBy([]);
        self::assertNotNull($model, 'The model catalog must not be empty');
        $modelId = (int) $model->getId();

        $health = $em->getRepository(ModelHealth::class)->findOneBy(['modelId' => $modelId]);
        if (null === $health) {
            $health = (new ModelHealth())->setModelId($modelId);
            $em->persist($health);
        }
        $health
            ->setState(ModelHealthState::Offline)
            ->setSource(ModelHealth::SOURCE_LISTING)
            ->setKind(FailureKind::Permanent->value)
            ->setMessage('not offered by endpoint')
            ->setUpdated(time());
        $em->flush();
        $this->createdHealthIds[] = (int) $health->getId();

        $data = $this->request('GET', '/api/v1/admin/model-health');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $found = null;
        foreach ($data['providers'] as $provider) {
            foreach ($provider['models'] as $listed) {
                self::assertContains(
                    $listed['source'],
                    [ModelHealth::SOURCE_PROBE, ModelHealth::SOURCE_LISTING, ModelHealth::SOURCE_TRAFFIC],
                    $listed['name']
                );
                if ($modelId === $listed['id']) {
                    $found = $listed;
                }
            }
        }
        self::assertNotNull($found, 'The seeded model must be listed');
        self::assertSame(ModelHealth::SOURCE_LISTING, $found['source']);
        self::assertSame('not offered by endpoint', $found['reason']);
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

    public function testResetClearsATrafficVerdictButLeavesAProbeRetirement(): void
    {
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $model = $em->getRepository(Model::class)->findOneBy([]);
        self::assertNotNull($model);
        $modelId = (int) $model->getId();

        $health = $em->getRepository(ModelHealth::class)->findOneBy(['modelId' => $modelId]);
        if (null === $health) {
            $health = (new ModelHealth())->setModelId($modelId);
            $em->persist($health);
        }
        $health
            ->setState(ModelHealthState::Offline)
            ->setSource(ModelHealth::SOURCE_TRAFFIC)
            ->setKind(FailureKind::Permanent->value)
            ->setMessage('80% of recent calls failed')
            ->setUpdated(time());
        $em->flush();
        $this->createdHealthIds[] = (int) $health->getId();

        $this->request('POST', "/api/v1/admin/model-health/models/{$modelId}/reset");
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $em->clear();
        $reloaded = $em->getRepository(ModelHealth::class)->findOneBy(['modelId' => $modelId]);
        self::assertNotNull($reloaded);
        self::assertSame(ModelHealthState::Unknown, $reloaded->getState());
        self::assertNull($reloaded->getMessage());
        self::assertNull($reloaded->getKind());

        $reloaded
            ->setState(ModelHealthState::Offline)
            ->setSource(ModelHealth::SOURCE_PROBE)
            ->setKind(FailureKind::Permanent->value)
            ->setMessage('Provider no longer serves this model.')
            ->setUpdated(time());
        $em->flush();

        $this->request('POST', "/api/v1/admin/model-health/models/{$modelId}/reset");
        $em->clear();
        $stillRetired = $em->getRepository(ModelHealth::class)->findOneBy(['modelId' => $modelId]);
        self::assertNotNull($stillRetired);
        self::assertSame(ModelHealthState::Offline, $stillRetired->getState());
        self::assertSame(ModelHealth::SOURCE_PROBE, $stillRetired->getSource());
        self::assertSame('Provider no longer serves this model.', $stillRetired->getMessage());
    }

    public function testExemptRejectsANonObjectJsonBody(): void
    {
        $em = $this->client->getContainer()->get('doctrine')->getManager();
        $model = $em->getRepository(Model::class)->findOneBy([]);
        self::assertNotNull($model);

        $this->client->request('POST', '/api/v1/admin/model-health/models/'.$model->getId().'/exempt', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ], '"not-an-object"');

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
    }
}
