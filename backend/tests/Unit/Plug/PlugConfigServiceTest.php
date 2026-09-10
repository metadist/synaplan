<?php

declare(strict_types=1);

namespace App\Tests\Unit\Plug;

use App\Plug\PlugConfigService;
use App\Repository\ConfigRepository;
use PHPUnit\Framework\TestCase;

final class PlugConfigServiceTest extends TestCase
{
    public function testDefaultsReproduceTodaysFileProcessorAndBrave(): void
    {
        $service = new PlugConfigService($this->repo([]));

        $this->assertSame(['native'], $service->extractionChain('text'));
        $this->assertSame(
            ['structured_office', 'office_convert', 'tika', 'pdf_vision'],
            $service->extractionChain('document'),
        );
        $this->assertSame(['vision'], $service->extractionChain('image'));
        $this->assertSame(['stt_cloud', 'whisper_local'], $service->extractionChain('audio', true));
        $this->assertSame(['whisper_local', 'stt_cloud'], $service->extractionChain('audio', false));
        $this->assertSame(['video_analysis'], $service->extractionChain('video'));
        $this->assertSame(10, $service->qualityMinLength());
        $this->assertSame(3.0, $service->qualityMinEntropy());
        $this->assertSame(['pdf'], $service->qualityApplyTo());
        $this->assertSame('brave', $service->webSearchProvider(null));
        $this->assertSame('', $service->webSearchFallback());
        $this->assertFalse($service->isWebSearchUserOverrideAllowed());
        $this->assertSame(8000, $service->webSearchTimeoutMs());
        $this->assertSame(4000, $service->webSearchMaxContentChars());
        $this->assertFalse($service->isRerankEnabled());
        $this->assertSame(4, $service->rerankCandidatesMultiplier());
        $this->assertSame(800, $service->rerankLatencyBudgetMs());
        $this->assertFalse($service->isRerankLlmFallback());
        $this->assertSame(2000, $service->rerankMaxCandidateChars());
        $this->assertSame([], $service->extraExtractorKeys('document'));
        $this->assertSame([], $service->extraExtractorKeys('text'));
    }

    public function testPerUserWebSearchProviderIsIgnoredWhenOverrideIsOff(): void
    {
        $service = new PlugConfigService($this->repo([
            [0, PlugConfigService::KEY_WEB_SEARCH_PROVIDER, 'brave'],
            [42, PlugConfigService::KEY_WEB_SEARCH_PROVIDER, 'searxng'],
        ]));

        $this->assertSame('brave', $service->webSearchProvider(null));
        $this->assertSame('brave', $service->webSearchProvider(0));
        $this->assertSame('brave', $service->webSearchProvider(42));
        $this->assertSame('brave', $service->webSearchProvider(7));
    }

    public function testPerUserWebSearchProviderWinsOverGlobalWhenAllowed(): void
    {
        $service = new PlugConfigService($this->repo([
            [0, PlugConfigService::KEY_WEB_SEARCH_PROVIDER, 'brave'],
            [0, PlugConfigService::KEY_WEB_SEARCH_USER_OVERRIDE_ALLOWED, '1'],
            [42, PlugConfigService::KEY_WEB_SEARCH_PROVIDER, 'searxng'],
        ]));

        $this->assertSame('brave', $service->webSearchProvider(null));
        $this->assertSame('brave', $service->webSearchProvider(0));
        $this->assertSame('searxng', $service->webSearchProvider(42));
        $this->assertSame('brave', $service->webSearchProvider(7));
    }

    public function testUnknownWebSearchProviderFallsBackToBrave(): void
    {
        $service = new PlugConfigService($this->repo([
            [0, PlugConfigService::KEY_WEB_SEARCH_PROVIDER, 'not-a-provider'],
        ]));

        $this->assertSame('brave', $service->webSearchProvider(null));
    }

    public function testExtraExtractorKeysAreKeysNotInTheBuiltinList(): void
    {
        $service = new PlugConfigService($this->repo([
            [0, PlugConfigService::KEY_CHAIN_DOCUMENT, 'docling,tika,pdf_vision'],
        ]));

        $this->assertSame(['docling'], $service->extraExtractorKeys('document'));
    }

    public function testNonNumericQualityAndRerankValuesFallBackToDefaults(): void
    {
        $service = new PlugConfigService($this->repo([
            [0, PlugConfigService::KEY_QUALITY_MIN_LENGTH, 'ten'],
            [0, PlugConfigService::KEY_QUALITY_MIN_ENTROPY, 'high'],
            [0, PlugConfigService::KEY_RERANK_CANDIDATES_MULTIPLIER, ''],
            [0, PlugConfigService::KEY_RERANK_LATENCY_BUDGET_MS, 'fast'],
        ]));

        $this->assertSame(PlugConfigService::DEFAULT_MIN_LENGTH, $service->qualityMinLength());
        $this->assertSame(PlugConfigService::DEFAULT_MIN_ENTROPY, $service->qualityMinEntropy());
        $this->assertSame(PlugConfigService::DEFAULT_RERANK_MULTIPLIER, $service->rerankCandidatesMultiplier());
        $this->assertSame(PlugConfigService::DEFAULT_RERANK_LATENCY_MS, $service->rerankLatencyBudgetMs());
    }

    public function testNumericQualityOverridesAreKept(): void
    {
        $service = new PlugConfigService($this->repo([
            [0, PlugConfigService::KEY_QUALITY_MIN_LENGTH, '25'],
            [0, PlugConfigService::KEY_QUALITY_MIN_ENTROPY, '4.5'],
        ]));

        $this->assertSame(25, $service->qualityMinLength());
        $this->assertSame(4.5, $service->qualityMinEntropy());
    }

    public function testSetChainPersistsKnownKeys(): void
    {
        $repo = $this->createMock(ConfigRepository::class);
        $repo->expects($this->once())->method('setValue')->with(
            0,
            PlugConfigService::CONFIG_GROUP,
            PlugConfigService::KEY_CHAIN_DOCUMENT,
            'docling,tika,pdf_vision',
        );

        $service = new PlugConfigService($repo);
        $service->setChain('document', ['docling', 'tika', 'pdf_vision'], ['docling', 'tika', 'pdf_vision', 'native']);
    }

    public function testSetChainRejectsUnknownKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown extractor key: nope');

        $service = new PlugConfigService($this->repo([]));
        $service->setChain('document', ['nope'], ['tika']);
    }

    public function testSetChainRejectsEmptyList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be empty');

        $service = new PlugConfigService($this->repo([]));
        $service->setChain('document', [], ['tika']);
    }

    public function testSetChainsValidatesEveryFamilyBeforePersisting(): void
    {
        $repo = $this->createMock(ConfigRepository::class);
        $repo->expects($this->never())->method('setValue');

        $service = new PlugConfigService($repo);

        try {
            $service->setChains(
                [
                    'document' => ['tika'],
                    'text' => [],
                ],
                ['tika', 'native'],
            );
            self::fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('must not be empty', $e->getMessage());
        }
    }

    public function testStoredEmptyChainFallsBackToDefault(): void
    {
        $service = new PlugConfigService($this->repo([
            [0, PlugConfigService::KEY_CHAIN_DOCUMENT, ''],
        ]));

        $this->assertSame(
            ['structured_office', 'office_convert', 'tika', 'pdf_vision'],
            $service->extractionChain('document'),
        );
    }

    /**
     * @param list<array{0: int, 1: string, 2: string}> $rows
     */
    private function repo(array $rows): ConfigRepository
    {
        $map = [];
        foreach ($rows as [$ownerId, $setting, $value]) {
            $map[$ownerId][PlugConfigService::CONFIG_GROUP][$setting] = $value;
        }

        $repo = $this->createMock(ConfigRepository::class);
        $repo->method('getValue')->willReturnCallback(
            static function (int $ownerId, string $group, string $setting) use ($map): ?string {
                return $map[$ownerId][$group][$setting] ?? null;
            },
        );

        return $repo;
    }
}
