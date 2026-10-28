<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Entity\Config;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Service\Iam\AuditLogWriter;
use App\Service\Model\ModelListingService;
use App\Service\Research\Run\ResearchAdminService;
use App\Service\Research\Run\ResearchBudget;
use App\Service\Research\Run\ResearchBudgetConfig;
use App\Service\Research\Run\ResearchBudgetStore;
use App\Service\Research\Run\ResearchConfig;
use App\Service\Research\Run\ResearchDepth;
use App\Service\Research\Run\ResearchRejected;
use App\Service\Research\Run\ResearchRunRef;
use App\Service\Research\Run\ResearchRunService;
use App\Service\Research\Run\ResearchRunState;
use App\Service\Research\Run\ResearchStateStore;
use App\Service\Research\Run\ResearchStoreUnavailable;
use App\Service\Research\Run\Stage\ResearchSearch;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ResearchAdminServiceTest extends TestCase
{
    /** @var array<string, string> */
    private array $values = [];

    public function testTheEnvironmentSwitchCannotBeChangedHere(): void
    {
        $repo = $this->createMock(ConfigRepository::class);
        $repo->expects($this->never())->method('setValue');
        $service = $this->service($repo, new ResearchConfig($repo, '1'));

        $this->expectException(ResearchRejected::class);
        $service->update($this->admin(), ['enabled' => false], '127.0.0.1');
    }

    public function testTurningResearchOnIsStoredAndAudited(): void
    {
        $repo = $this->memoryRepo();
        $audit = $this->createMock(AuditLogWriter::class);
        $audit->expects($this->once())->method('record')->with(
            4,
            'research.settings.update',
            'research',
            'workspace',
            ['changed' => ['enabled'], 'enabledBefore' => false, 'enabled' => true, 'autoEnabledBefore' => false, 'autoEnabled' => false],
            '127.0.0.1',
        );
        $service = $this->service($repo, new ResearchConfig($repo, ''), $audit);

        $described = $service->update($this->admin(), ['enabled' => true], '127.0.0.1');

        self::assertTrue($described['enabled']);
        self::assertFalse($described['envLocked']);
        self::assertSame('1', $this->values['RESEARCH.ENABLED']);
        self::assertSame(2, $described['budgets']['quick']['searches']);
    }

    public function testAutomaticResearchRoundTripsAndIsAuditedWithBothValues(): void
    {
        $repo = $this->memoryRepo();
        $this->values['RESEARCH.ENABLED'] = '1';
        $this->values['RESEARCH.AUTO_ENABLED'] = '1';
        $audit = $this->createMock(AuditLogWriter::class);
        $audit->expects($this->once())->method('record')->with(
            4,
            'research.settings.update',
            'research',
            'workspace',
            ['changed' => ['autoEnabled'], 'enabledBefore' => true, 'enabled' => true, 'autoEnabledBefore' => true, 'autoEnabled' => false],
            '127.0.0.1',
        );
        $service = $this->service($repo, new ResearchConfig($repo, ''), $audit);

        self::assertTrue($service->describe($this->admin())['autoEnabled']);
        $described = $service->update($this->admin(), ['autoEnabled' => false], '127.0.0.1');

        self::assertFalse($described['autoEnabled']);
        self::assertSame('0', $this->values['RESEARCH.AUTO_ENABLED']);
    }

    public function testAutomaticResearchIsRefusedWhileResearchIsOff(): void
    {
        $repo = $this->memoryRepo();
        $service = $this->service($repo, new ResearchConfig($repo, ''));

        try {
            $service->update($this->admin(), ['autoEnabled' => true], '127.0.0.1');
            self::fail('The second switch needs the first.');
        } catch (ResearchRejected $rejected) {
            self::assertSame(409, $rejected->status);
            self::assertSame('research_off', $rejected->errorCode);
            self::assertSame('autoEnabled', $rejected->field);
        }
        self::assertSame([], $this->values);
    }

    public function testTurningResearchOffKeepsTheAutomaticSwitchAsStored(): void
    {
        $repo = $this->memoryRepo();
        $this->values['RESEARCH.ENABLED'] = '1';
        $this->values['RESEARCH.AUTO_ENABLED'] = '1';
        $service = $this->service($repo, new ResearchConfig($repo, ''));

        $described = $service->update($this->admin(), ['enabled' => false], '127.0.0.1');

        self::assertFalse($described['enabled']);
        self::assertTrue($described['autoEnabled'], 'the tab shows the stored switch so it comes back as it was');
        self::assertSame('1', $this->values['RESEARCH.AUTO_ENABLED']);
    }

    public function testTheTabSaysWhenWebSearchIsOff(): void
    {
        $repo = $this->memoryRepo();
        $search = $this->createStub(ResearchSearch::class);
        $search->method('available')->willReturn(false);
        $service = $this->service($repo, new ResearchConfig($repo, ''), null, null, null, null, $search);

        self::assertFalse($service->describe($this->admin())['webSearchAvailable']);
    }

    public function testASaveThatChangesNothingIsNotAudited(): void
    {
        $repo = $this->memoryRepo();
        $audit = $this->createMock(AuditLogWriter::class);
        $audit->expects($this->never())->method('record');
        $service = $this->service($repo, new ResearchConfig($repo, ''), $audit);

        $service->update($this->admin(), ['enabled' => false, 'readingModelId' => 0], '127.0.0.1');
    }

    public function testAValueOutOfRangeIsRefusedAndNothingIsStored(): void
    {
        $repo = $this->memoryRepo();
        $service = $this->service($repo, new ResearchConfig($repo, ''));

        try {
            $service->update($this->admin(), [
                'enabled' => true,
                'budgets' => ['standard' => ['searches' => 500]],
            ], '127.0.0.1');
            self::fail('A value out of range must be refused.');
        } catch (ResearchRejected $rejected) {
            self::assertSame('budgets.standard.searches', $rejected->field);
            self::assertSame(400, $rejected->status);
        }
        self::assertSame([], $this->values);
    }

    public function testAnUnknownModelIsRefusedBeforeTheSwitchIsStored(): void
    {
        $repo = $this->memoryRepo();
        $service = $this->service($repo, new ResearchConfig($repo, ''));

        $this->expectException(ResearchRejected::class);
        try {
            $service->update($this->admin(), ['enabled' => true, 'readingModelId' => 99], '127.0.0.1');
        } finally {
            self::assertArrayNotHasKey('RESEARCH.ENABLED', $this->values);
        }
    }

    public function testAModelThatIsNotReadyIsRefused(): void
    {
        $repo = $this->memoryRepo();
        $service = $this->service($repo, new ResearchConfig($repo, ''));

        try {
            $service->update($this->admin(), ['checkingModelId' => 8], '127.0.0.1');
            self::fail('A model that is not ready must be refused.');
        } catch (ResearchRejected $rejected) {
            self::assertSame('model_unavailable', $rejected->errorCode);
        }
    }

    public function testAPartialBudgetKeepsTheOtherStoredNumbers(): void
    {
        $repo = $this->memoryRepo();
        $this->values['RESEARCH.BUDGET_QUICK'] = '{"searches":3,"sources":5,"minutes":2,"cost_cap":0.2}';
        $service = $this->service($repo, new ResearchConfig($repo, ''));

        $described = $service->update($this->admin(), ['budgets' => ['quick' => ['costCap' => 0.5]]], '127.0.0.1');

        self::assertSame(['searches' => 3, 'sources' => 5, 'minutes' => 2, 'costCap' => 0.5], $described['budgets']['quick']);
    }

    public function testASecondSampleIsRefusedWhileOneIsRunning(): void
    {
        $repo = $this->memoryRepo();
        $this->values['RESEARCH.ENABLED'] = '1';
        $running = $this->researchRun('admin-sample.20261027100000');
        $runs = $this->createMock(ResearchRunService::class);
        $runs->expects($this->never())->method('start');
        $service = $this->service($repo, new ResearchConfig($repo, ''), null, $runs, [$running]);

        try {
            $service->startSample($this->admin(), '127.0.0.1');
            self::fail('A second sample must wait for the first.');
        } catch (ResearchRejected $rejected) {
            self::assertSame('sample_running', $rejected->errorCode);
        }
        self::assertSame(['id' => $running->ref->id, 'status' => 'running', 'sentence' => null], $service->describe($this->admin())['sample']);
    }

    public function testOtherRunsWithTheSameQuestionAreNotSamples(): void
    {
        $repo = $this->memoryRepo();
        $service = $this->service($repo, new ResearchConfig($repo, ''), null, null, [$this->researchRun(null)]);

        self::assertNull($service->describe($this->admin())['sample']);
    }

    public function testTheTabLoadsWhenTheResearchStoreDoesNotAnswer(): void
    {
        $repo = $this->memoryRepo();
        $store = $this->createStub(ResearchStateStore::class);
        $store->method('runsFor')->willThrowException(new ResearchStoreUnavailable());
        $service = $this->service($repo, new ResearchConfig($repo, ''), null, null, null, $store);

        self::assertNull($service->describe($this->admin())['sample']);
    }

    /**
     * @param list<ResearchRunState>|null $runs
     */
    private function service(
        ConfigRepository $repo,
        ResearchConfig $config,
        ?AuditLogWriter $audit = null,
        ?ResearchRunService $runService = null,
        ?array $runs = null,
        ?ResearchStateStore $store = null,
        ?ResearchSearch $search = null,
    ): ResearchAdminService {
        $models = $this->createStub(ModelListingService::class);
        $models->method('forUser')->willReturn(['models' => ['CHAT' => [
            ['id' => 3, 'name' => 'Atlas', 'available' => true],
            ['id' => 8, 'name' => 'Local', 'available' => false, 'unavailableReason' => 'not_pulled'],
        ]]]);
        if (null === $store) {
            $store = $this->createStub(ResearchStateStore::class);
            $store->method('runsFor')->willReturn($runs ?? []);
        }
        if (null === $search) {
            $search = $this->createStub(ResearchSearch::class);
            $search->method('available')->willReturn(true);
        }

        return new ResearchAdminService(
            $config,
            new ResearchBudgetStore($repo),
            $repo,
            $models,
            $runService ?? $this->createStub(ResearchRunService::class),
            $store,
            $audit ?? $this->createStub(AuditLogWriter::class),
            new MockClock('2026-10-27 10:00:00'),
            $search,
        );
    }

    private function memoryRepo(): ConfigRepository
    {
        $repo = $this->createStub(ConfigRepository::class);
        $repo->method('getValue')->willReturnCallback(
            fn (int $owner, string $group, string $setting): ?string => $this->values[$group.'.'.$setting] ?? null,
        );
        $repo->method('setValue')->willReturnCallback(function (int $owner, string $group, string $setting, string $value): Config {
            $this->values[$group.'.'.$setting] = $value;

            return new Config();
        });

        return $repo;
    }

    private function researchRun(?string $idempotencyKey): ResearchRunState
    {
        $now = new \DateTimeImmutable('2026-10-27 10:00:00');
        $budget = ResearchBudget::for(ResearchDepth::Quick, ResearchBudgetConfig::defaults(ResearchDepth::Quick), $now);
        $state = ResearchRunState::open(ResearchRunRef::start(4, ResearchDepth::Quick, 'What is worth knowing about technology this week?', null, $now), $budget);
        $state->idempotencyKey = $idempotencyKey;

        return $state;
    }

    private function admin(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(4);

        return $user;
    }
}
