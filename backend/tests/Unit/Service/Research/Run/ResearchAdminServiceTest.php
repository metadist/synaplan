<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Service\Iam\AuditLogWriter;
use App\Service\Model\ModelListingService;
use App\Service\Research\Run\ResearchAdminService;
use App\Service\Research\Run\ResearchBudgetStore;
use App\Service\Research\Run\ResearchConfig;
use App\Service\Research\Run\ResearchRejected;
use App\Service\Research\Run\ResearchRunService;
use App\Service\Research\Run\ResearchStateStore;
use PHPUnit\Framework\TestCase;

final class ResearchAdminServiceTest extends TestCase
{
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
        $repo = $this->createMock(ConfigRepository::class);
        $repo->method('getValue')->willReturn(null);
        $repo->expects($this->once())->method('setValue')->with(0, 'RESEARCH', 'ENABLED', '1');
        $audit = $this->createMock(AuditLogWriter::class);
        $audit->expects($this->once())->method('record')->with(
            4,
            'research.settings.update',
            'research',
            'workspace',
            ['enabled' => false],
            '127.0.0.1',
        );
        $service = $this->service($repo, new ResearchConfig($repo, ''), $audit);

        $described = $service->update($this->admin(), ['enabled' => true], '127.0.0.1');

        self::assertFalse($described['envLocked']);
        self::assertSame(2, $described['budgets']['quick']['searches']);
    }

    private function service(ConfigRepository $repo, ResearchConfig $config, ?AuditLogWriter $audit = null): ResearchAdminService
    {
        $models = $this->createStub(ModelListingService::class);
        $models->method('forUser')->willReturn(['models' => ['CHAT' => []]]);
        $store = $this->createStub(ResearchStateStore::class);
        $store->method('runsFor')->willReturn([]);

        return new ResearchAdminService(
            $config,
            new ResearchBudgetStore($repo),
            $repo,
            $models,
            $this->createStub(ResearchRunService::class),
            $store,
            $audit ?? $this->createStub(AuditLogWriter::class),
        );
    }

    private function admin(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(4);

        return $user;
    }
}
