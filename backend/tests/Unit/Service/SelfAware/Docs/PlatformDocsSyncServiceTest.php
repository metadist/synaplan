<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SelfAware\Docs;

use App\Repository\ConfigRepository;
use App\Service\File\VectorizationService;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use App\Service\SelfAware\Docs\DocsManifest;
use App\Service\SelfAware\Docs\PlatformDocsManifestClient;
use App\Service\SelfAware\Docs\PlatformDocsSyncService;
use App\Service\SelfAware\Docs\PlatformDocsSyncState;
use App\Service\SelfAware\SelfAwareConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PlatformDocsSyncServiceTest extends TestCase
{
    public function testEmptyUrlIsSkipped(): void
    {
        $service = $this->service(manifestUrl: '', client: $this->createMock(PlatformDocsManifestClient::class));
        $result = $service->sync();

        $this->assertSame('skipped', $result->status);
        $this->assertStringContainsString('empty', $result->reason);
    }

    public function testManifestFailureLeavesStateUntouched(): void
    {
        $client = $this->createMock(PlatformDocsManifestClient::class);
        $client->method('fetchManifest')->willThrowException(new \RuntimeException('timeout'));

        $configRepo = $this->createMock(ConfigRepository::class);
        $configRepo->expects($this->never())->method('setValue');

        $service = $this->service(
            manifestUrl: 'https://docs.synaplan.com/docs-manifest.json',
            client: $client,
            configRepo: $configRepo,
        );
        $result = $service->sync();

        $this->assertTrue($result->isFailed());
        $this->assertSame('timeout', $result->reason);
    }

    public function testDryRunDoesNotWrite(): void
    {
        $json = (string) file_get_contents(dirname(__DIR__, 4).'/Fixtures/selfaware/docs-manifest.json');
        $client = $this->createMock(PlatformDocsManifestClient::class);
        $client->method('fetchManifest')->willReturn(DocsManifest::fromJson($json));
        $client->expects($this->never())->method('fetchPage');

        $vector = $this->createMock(VectorStorageFacade::class);
        $vector->expects($this->never())->method('deleteByFile');

        $configRepo = $this->createMock(ConfigRepository::class);
        $configRepo->expects($this->never())->method('setValue');

        $service = $this->service(
            manifestUrl: 'https://docs.synaplan.com/docs-manifest.json',
            client: $client,
            configRepo: $configRepo,
            vectorStorage: $vector,
        );
        $result = $service->sync(dryRun: true);

        $this->assertSame('ok', $result->status);
        $this->assertSame(3, $result->changed);
    }

    private function service(
        string $manifestUrl,
        PlatformDocsManifestClient $client,
        ?ConfigRepository $configRepo = null,
        ?VectorStorageFacade $vectorStorage = null,
    ): PlatformDocsSyncService {
        /** @var ConfigRepository&MockObject $repo */
        $repo = $configRepo ?? $this->createMock(ConfigRepository::class);
        $repo->method('getValue')->willReturnCallback(
            static function (int $owner, string $group, string $setting) use ($manifestUrl): ?string {
                if (SelfAwareConfig::KEY_DOCS_MANIFEST_URL === $setting) {
                    return $manifestUrl;
                }

                return null;
            }
        );

        return new PlatformDocsSyncService(
            new SelfAwareConfig($repo),
            $client,
            new PlatformDocsSyncState($repo),
            $vectorStorage ?? $this->createMock(VectorStorageFacade::class),
            $this->createMock(VectorizationService::class),
            new NullLogger(),
        );
    }
}
