<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Sidecar;

use App\Module\Sidecar\DoclingModule;
use App\Module\Sidecar\LocalAiModule;
use App\Module\Sidecar\OfficeConvertModule;
use App\Module\Sidecar\PiperTtsModule;
use App\Module\Sidecar\SearxngModule;
use App\Module\Sidecar\TikaModule;
use App\Plug\Extraction\Docling\DoclingClient;
use App\Plug\PlugConfigService;
use App\Plug\WebSearch\Client\SearxngClient;
use App\Service\File\Office\OfficeConverterClient;
use App\Service\File\TikaClient;
use App\Tests\Unit\Module\Fixture\FakeSidecarHealthProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

final class SidecarModulesTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function urlValues(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'disabled' => ['disabled'];
        yield 'DISABLED' => ['DISABLED'];
        yield 'set' => ['http://tika:9998'];
        yield 'set with spaces' => ['  http://tika:9998  '];
    }

    #[DataProvider('urlValues')]
    public function testTikaConfiguredMirrorsTikaClient(string $url): void
    {
        $client = new TikaClient(new MockHttpClient(), new NullLogger(), $url, 1000, 0, 0, null, null);
        $module = new TikaModule(new FakeSidecarHealthProbe(), $url);

        $this->assertSame($client->isEnabled(), $module->isConfigured());
    }

    #[DataProvider('urlValues')]
    public function testDoclingConfiguredMirrorsDoclingClient(string $url): void
    {
        $client = new DoclingClient(new MockHttpClient(), new NullLogger(), $url, 1000);
        $module = new DoclingModule(new FakeSidecarHealthProbe(), $url);

        $this->assertSame($client->isEnabled(), $module->isConfigured());
    }

    #[DataProvider('urlValues')]
    public function testOfficeConvertConfiguredMirrorsOfficeConverterClient(string $url): void
    {
        $client = new OfficeConverterClient(new MockHttpClient(), new NullLogger(), $url, 1000);
        $module = new OfficeConvertModule(new FakeSidecarHealthProbe(), $url);

        $this->assertSame($client->isEnabled(), $module->isConfigured());
    }

    #[DataProvider('urlValues')]
    public function testSearxngConfiguredMirrorsSearxngClient(string $url): void
    {
        $client = new SearxngClient(new MockHttpClient(), $this->createStub(PlugConfigService::class), $url);
        $module = new SearxngModule(new FakeSidecarHealthProbe(), $url);

        $this->assertSame($client->isConfigured(), $module->isConfigured());
    }

    public function testAbsentModulesNeverTouchTheNetwork(): void
    {
        $probe = new FakeSidecarHealthProbe();
        $modules = [
            new TikaModule($probe, ''),
            new DoclingModule($probe, ''),
            new OfficeConvertModule($probe, 'disabled'),
            new SearxngModule($probe, ''),
            new PiperTtsModule($probe, ''),
            new LocalAiModule($probe, ' '),
        ];

        foreach ($modules as $module) {
            $status = $module->status();
            $this->assertFalse($module->isConfigured(), $module->id());
            $this->assertFalse($status->configured, $module->id());
            $this->assertSame('absent', $status->state(), $module->id());
        }

        $this->assertSame([], $probe->reachableCalls);
        $this->assertSame([], $probe->fetchCalls);
    }

    public function testTikaStatusProbesTikaAndVersionWithBasicAuth(): void
    {
        $probe = new FakeSidecarHealthProbe(
            reachable: ['http://tika:9998/tika' => true],
            bodies: ['http://tika:9998/version' => "Apache Tika 2.9.2\n"],
        );
        $module = new TikaModule($probe, 'http://tika:9998/', 'user', 'secret');

        $status = $module->status();

        $this->assertTrue($status->configured);
        $this->assertTrue($status->healthy);
        $this->assertSame('available', $status->state());
        $this->assertSame('Tika is running', $status->message);
        $this->assertSame(['url' => 'http://tika:9998/', 'version' => 'Apache Tika 2.9.2'], $status->details);
        $this->assertSame(['http://tika:9998/tika as user'], $probe->reachableCalls);
    }

    public function testTikaUnreachableSkipsTheVersionCall(): void
    {
        $probe = new FakeSidecarHealthProbe();
        $module = new TikaModule($probe, 'http://tika:9998');

        $status = $module->status();

        $this->assertTrue($status->configured);
        $this->assertFalse($status->healthy);
        $this->assertSame('needs_setup', $status->state());
        $this->assertNull($status->details['version']);
        $this->assertSame([], $probe->fetchCalls);
    }

    /**
     * @return iterable<string, array{callable(FakeSidecarHealthProbe): \App\Module\Contract\FeatureModuleInterface, string}>
     */
    public static function healthEndpoints(): iterable
    {
        yield 'docling' => [static fn (FakeSidecarHealthProbe $p) => new DoclingModule($p, 'http://docling:5001/'), 'http://docling:5001/health'];
        yield 'office_convert' => [static fn (FakeSidecarHealthProbe $p) => new OfficeConvertModule($p, 'http://collabora:9980'), 'http://collabora:9980/hosting/capabilities'];
        yield 'searxng' => [static fn (FakeSidecarHealthProbe $p) => new SearxngModule($p, 'http://searxng:8080'), 'http://searxng:8080/healthz'];
        yield 'piper_tts' => [static fn (FakeSidecarHealthProbe $p) => new PiperTtsModule($p, 'http://synaplan-tts:10200'), 'http://synaplan-tts:10200/health'];
        yield 'local_ai' => [static fn (FakeSidecarHealthProbe $p) => new LocalAiModule($p, 'http://ollama:11434'), 'http://ollama:11434/api/tags'];
    }

    #[DataProvider('healthEndpoints')]
    public function testConfiguredSidecarProbesItsHealthEndpoint(callable $factory, string $expectedUrl): void
    {
        $up = new FakeSidecarHealthProbe(reachable: [$expectedUrl => true]);
        $healthy = $factory($up)->status();
        $this->assertTrue($healthy->configured);
        $this->assertTrue($healthy->healthy);
        $this->assertSame([$expectedUrl], $up->reachableCalls);

        $down = new FakeSidecarHealthProbe();
        $unhealthy = $factory($down)->status();
        $this->assertTrue($unhealthy->configured);
        $this->assertFalse($unhealthy->healthy);
        $this->assertSame('needs_setup', $unhealthy->state());
    }

    public function testPiperReadsTheRawUrlNotTheProviderDefault(): void
    {
        $this->assertFalse((new PiperTtsModule(new FakeSidecarHealthProbe(), ''))->isConfigured());
        $this->assertTrue((new PiperTtsModule(new FakeSidecarHealthProbe(), 'http://synaplan-tts:10200'))->isConfigured());
    }
}
