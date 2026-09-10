<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\ModulesListCommand;
use App\Module\Contract\FeatureModuleInterface;
use App\Module\ModuleRegistry;
use App\Module\ModuleStatusPresenter;
use App\Module\Sidecar\TikaModule;
use App\Tests\Unit\Module\Fixture\BuildsAllModules;
use App\Tests\Unit\Module\Fixture\FakeSidecarHealthProbe;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class ModulesListCommandTest extends TestCase
{
    use BuildsAllModules;

    public function testTableListsEveryModuleAndReportsTheConfiguredCount(): void
    {
        $tester = $this->tester(tikaUrl: '');

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();

        foreach (array_keys($this->allModules()) as $id) {
            $this->assertStringContainsString($id, $display);
        }
        $this->assertStringContainsString('12 module(s), 0 configured.', $display);
    }

    public function testJsonOutputHasThePresenterShape(): void
    {
        $tester = $this->tester(tikaUrl: 'http://tika:9998');

        $this->assertSame(Command::SUCCESS, $tester->execute(['--json' => true]));
        $rows = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertCount(12, $rows);
        $tika = array_values(array_filter($rows, static fn (array $row): bool => 'tika' === $row['id']))[0];
        $this->assertSame(['id', 'label_key', 'state', 'configured', 'healthy', 'message', 'details', 'configured_by', 'capabilities', 'docs_anchor', 'mobile_class'], array_keys($tika));
        $this->assertTrue($tika['configured']);
        $this->assertSame('needs_setup', $tika['state'], 'configured but the fake probe says down');
    }

    public function testAssertNoneConfiguredPassesOnAMinimalStack(): void
    {
        $tester = $this->tester(tikaUrl: '');

        $this->assertSame(Command::SUCCESS, $tester->execute(['--assert-none-configured' => true]));
    }

    public function testAssertNoneConfiguredFailsAndNamesTheModule(): void
    {
        $tester = $this->tester(tikaUrl: 'http://tika:9998');

        $this->assertSame(Command::FAILURE, $tester->execute(['--assert-none-configured' => true]));
        $this->assertStringContainsString('found: tika', $tester->getDisplay());
    }

    private function tester(string $tikaUrl): CommandTester
    {
        $modules = $this->allModules();
        $modules[TikaModule::ID] = new TikaModule(new FakeSidecarHealthProbe(), $tikaUrl);

        $factories = [];
        foreach ($modules as $id => $module) {
            $factories[$id] = static fn (): FeatureModuleInterface => $module;
        }
        $registry = new ModuleRegistry(new ServiceLocator($factories));

        return new CommandTester(new ModulesListCommand($registry, new ModuleStatusPresenter($registry)));
    }
}
