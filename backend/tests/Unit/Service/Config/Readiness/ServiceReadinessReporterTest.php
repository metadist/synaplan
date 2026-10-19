<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Config\Readiness;

use App\Service\Config\Readiness\ComposeServiceLookup;
use App\Service\Config\Readiness\ReadinessObservation;
use App\Service\Config\Readiness\ServiceReadinessCatalog;
use App\Service\Config\Readiness\ServiceReadinessProbes;
use App\Service\Config\Readiness\ServiceReadinessReporter;
use App\Service\Config\Readiness\ServiceStateTracker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class ServiceReadinessReporterTest extends TestCase
{
    /**
     * @param array<string, ReadinessObservation> $overrides
     */
    private function reporter(array $overrides = [], string $environment = 'prod', string $platform = 'selfhost', string $kubernetesHost = '', bool $containersExist = true): ServiceReadinessReporter
    {
        $observations = [];
        foreach (array_keys(ServiceReadinessCatalog::SERVICES) as $id) {
            $observations[$id] = $overrides[$id] ?? ReadinessObservation::ready();
        }
        $probes = $this->createStub(ServiceReadinessProbes::class);
        $probes->method('observeAll')->willReturn($observations);
        $lookup = $this->createStub(ComposeServiceLookup::class);
        $lookup->method('exists')->willReturn($containersExist);

        return new ServiceReadinessReporter(
            $probes,
            new ServiceStateTracker(new ArrayAdapter(), new MockClock('2026-10-19 12:00:00 UTC')),
            new ArrayAdapter(),
            $lookup,
            $environment,
            $platform,
            $kubernetesHost,
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private function row(array $rows, string $id): array
    {
        foreach ($rows as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        self::fail('No row for '.$id);
    }

    public function testEveryCatalogServiceHasOneRowWithTheFullShape(): void
    {
        $rows = $this->reporter()->rows();

        self::assertSame(array_keys(ServiceReadinessCatalog::SERVICES), array_column($rows, 'id'));
        foreach ($rows as $row) {
            self::assertSame(
                ['id', 'name', 'state', 'unlocks', 'effect', 'recovery', 'since', 'percent', 'model', 'minutes_left', 'profile', 'docs_anchor'],
                array_keys($row),
            );
            self::assertSame('ready', $row['state']);
            self::assertNull($row['effect'], 'a ready service has nothing waiting');
            self::assertNull($row['recovery']);
        }
    }

    public function testADownloadCarriesItsProgress(): void
    {
        $row = $this->row($this->reporter(['ollama' => ReadinessObservation::downloading(42, 'gpt-oss:20b')])->rows(), 'ollama');

        self::assertSame('downloading', $row['state']);
        self::assertSame(42, $row['percent']);
        self::assertSame('gpt-oss:20b', $row['model']);
        self::assertSame('Local AI models are not available.', $row['effect']);
        self::assertNull($row['recovery'], 'progress is not a problem to act on');
    }

    public function testAnOffServiceNamesItsProfileAndTheDevStackGetsTheCommand(): void
    {
        $prod = $this->row($this->reporter(['collabora' => ReadinessObservation::off()])->rows(), 'collabora');
        self::assertSame('off', $prod['state']);
        self::assertSame('office', $prod['profile']);
        self::assertSame('Office files open read-only.', $prod['effect']);
        self::assertNull($prod['recovery'], 'the office profile exists only in the dev compose files');

        $dev = $this->row($this->reporter(['collabora' => ReadinessObservation::off()], 'dev')->rows(), 'collabora');
        self::assertSame('Turn it on with the "office" Compose profile: COMPOSE_PROFILES=office make up', $dev['recovery']);
    }

    public function testAFailedServiceOnAComposeInstallNamesTheCommand(): void
    {
        $row = $this->row($this->reporter(['tika' => ReadinessObservation::broken()])->rows(), 'tika');

        self::assertSame('failed', $row['state']);
        self::assertSame('PDFs and Office files cannot be read.', $row['effect']);
        self::assertSame('Run `docker compose logs tika` to see why; `docker compose restart tika` starts it again.', $row['recovery']);
    }

    public function testKubernetesAndMarketplaceInstallsGetNoCommand(): void
    {
        $broken = ['tika' => ReadinessObservation::broken()];

        self::assertNull($this->row($this->reporter($broken, kubernetesHost: '10.0.0.1')->rows(), 'tika')['recovery']);
        self::assertNull($this->row($this->reporter($broken, platform: 'umbrel')->rows(), 'tika')['recovery']);
        self::assertNull($this->row($this->reporter($broken, platform: 'elestio')->rows(), 'tika')['recovery']);
        self::assertSame('PDFs and Office files cannot be read.', $this->row($this->reporter($broken, platform: 'umbrel')->rows(), 'tika')['effect']);
    }

    public function testADevSidecarWhoseProfileIsOffIsOffNotStarting(): void
    {
        $down = ['docling' => ReadinessObservation::down(), 'qdrant' => ReadinessObservation::down()];

        $rows = $this->reporter($down, 'dev', containersExist: false)->rows();
        $docling = $this->row($rows, 'docling');
        self::assertSame('off', $docling['state']);
        self::assertSame('docling', $docling['profile']);
        self::assertSame('Turn it on with the "docling" Compose profile: COMPOSE_PROFILES=docling make up', $docling['recovery']);
        self::assertSame('starting', $this->row($rows, 'qdrant')['state'], 'a default service without its container is still coming in wave 2');
    }

    public function testASidecarWhoseContainerExistsOrThatRunsOutsideDevIsStarting(): void
    {
        $down = ['docling' => ReadinessObservation::down()];

        self::assertSame('starting', $this->row($this->reporter($down, 'dev')->rows(), 'docling')['state']);
        self::assertSame('starting', $this->row($this->reporter($down, containersExist: false)->rows(), 'docling')['state']);
    }

    public function testADevOnlyServiceGetsACommandOnlyOnTheDevStack(): void
    {
        $broken = ['ollama' => ReadinessObservation::broken()];

        self::assertNull($this->row($this->reporter($broken)->rows(), 'ollama')['recovery']);
        self::assertSame(
            'Run `docker compose logs ollama backend` to see why; the model download resumes on the next `make up`.',
            $this->row($this->reporter($broken, 'dev')->rows(), 'ollama')['recovery'],
        );
    }
}
