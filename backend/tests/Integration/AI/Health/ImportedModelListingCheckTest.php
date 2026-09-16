<?php

declare(strict_types=1);

namespace App\Tests\Integration\AI\Health;

use App\AI\Health\ImportedModelListingCheck;
use App\AI\Health\ModelAutoDisabler;
use App\AI\Health\ModelHealthState;
use App\AI\Import\DiscoveredModel;
use App\AI\Import\DiscoveryResult;
use App\AI\Import\ModelDiscovererInterface;
use App\Entity\Model;
use App\Entity\ModelHealth;
use App\Repository\ModelHealthRepository;
use App\Repository\ModelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * PL35 / C7: a successful listing that omits an imported model reports it
 * offline; an unreachable source marks nothing.
 */
final class ImportedModelListingCheckTest extends KernelTestCase
{
    private const SOURCE = 'openai_compatible:itest-listing-ep';
    private const PRESENT = 'itest-listing/present-model';
    private const GONE = 'itest-listing/gone-model';

    private EntityManagerInterface $em;
    private ModelRepository $models;
    private ModelHealthRepository $healthRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get('doctrine')->getManager();
        $this->models = $container->get(ModelRepository::class);
        $this->healthRepository = $container->get(ModelHealthRepository::class);
        $this->deleteTestRows();
        $this->seedImportedModel(self::PRESENT);
        $this->seedImportedModel(self::GONE);
    }

    protected function tearDown(): void
    {
        $this->deleteTestRows();
        parent::tearDown();
    }

    public function testSuccessfulListingReportsMissingModelOffline(): void
    {
        $check = $this->check(DiscoveryResult::listed([
            new DiscoveredModel(self::PRESENT, 'Present', ['chat'], true),
        ]));

        $summary = $check->run();

        self::assertSame(1, $summary['checkedSources']);
        self::assertSame(0, $summary['unreachable']);

        $goneHealth = $this->healthFor(self::GONE);
        self::assertInstanceOf(ModelHealth::class, $goneHealth);
        self::assertSame(ModelHealthState::Offline, $goneHealth->getState());
        self::assertSame(ModelHealth::SOURCE_LISTING, $goneHealth->getSource());
        self::assertSame('not offered by endpoint', $goneHealth->getMessage());

        $presentHealth = $this->healthFor(self::PRESENT);
        self::assertInstanceOf(ModelHealth::class, $presentHealth);
        self::assertSame(ModelHealthState::Online, $presentHealth->getState());
    }

    public function testUnreachableSourceMarksNothing(): void
    {
        $check = $this->check(DiscoveryResult::unreachable('connection refused'));

        $summary = $check->run();

        self::assertSame(0, $summary['checkedSources']);
        self::assertSame(1, $summary['unreachable']);
        self::assertSame(0, $summary['markedOffline']);
        self::assertNull($this->healthFor(self::GONE), 'an unreachable source must not write any verdict');
    }

    private function check(DiscoveryResult $result): ImportedModelListingCheck
    {
        $discoverer = new class($result) implements ModelDiscovererInterface {
            public function __construct(private readonly DiscoveryResult $result)
            {
            }

            public function discover(string $source): DiscoveryResult
            {
                return $this->result;
            }
        };

        return new ImportedModelListingCheck(
            $this->models,
            $discoverer,
            $this->healthRepository,
            static::getContainer()->get(ModelAutoDisabler::class),
            $this->em,
            new NullLogger(),
        );
    }

    private function seedImportedModel(string $providerId): void
    {
        $now = time();
        $model = (new Model())
            ->setService('OpenAICompatible')
            ->setTag('chat')
            ->setProviderId($providerId)
            ->setName('Listing test')
            ->setSelectable(1)
            ->setActive(1)
            ->setIsDefault(0)
            ->setJson(['endpoint' => 'itest-listing-ep', 'meta' => ['import' => ['source' => self::SOURCE, 'importedAt' => $now, 'lastSeenAt' => $now]]]);
        $this->em->persist($model);
        $this->em->flush();
    }

    private function healthFor(string $providerId): ?ModelHealth
    {
        $model = $this->models->findOneBy(['service' => 'OpenAICompatible', 'tag' => 'chat', 'providerId' => $providerId]);
        if (null === $model) {
            return null;
        }

        return $this->em->getRepository(ModelHealth::class)->findOneBy(['modelId' => (int) $model->getId()]);
    }

    private function deleteTestRows(): void
    {
        $models = $this->models->findBy(['service' => 'OpenAICompatible', 'tag' => 'chat']);
        foreach ($models as $model) {
            if (!in_array($model->getProviderId(), [self::PRESENT, self::GONE], true)) {
                continue;
            }
            $this->em->getRepository(ModelHealth::class)
                ->createQueryBuilder('h')->delete()->where('h.modelId = :id')
                ->setParameter('id', (int) $model->getId())->getQuery()->execute();
            $this->em->remove($model);
        }
        $this->em->flush();
        $this->em->clear();
    }
}
