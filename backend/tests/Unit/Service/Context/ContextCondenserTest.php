<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Context;

use App\AI\Service\AiFacade;
use App\Entity\Model;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Repository\UserRepository;
use App\Service\Context\CondensedText;
use App\Service\Context\ContextChunker;
use App\Service\Context\ContextCondenser;
use App\Service\Context\ContextFittingConfig;
use App\Service\Context\ModelContextWindow;
use App\Service\Context\TokenEstimator;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ContextCondenserTest extends TestCase
{
    private AiFacade&MockObject $aiFacade;
    private ModelConfigService&MockObject $modelConfigService;
    private ConfigRepository&MockObject $configRepository;
    private ModelRepository&MockObject $modelRepository;

    protected function setUp(): void
    {
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->modelRepository = $this->createMock(ModelRepository::class);

        $this->configRepository->method('getValue')->willReturn(null);
        $this->modelConfigService->method('getProviderForModel')->willReturn('groq');
        $this->modelConfigService->method('getModelName')->willReturn('condenser-model');

        $condenser = new Model();
        $condenser->setJson(['meta' => ['context_window' => 131072, 'max_output' => 32768]]);
        $this->modelRepository->method('find')->willReturn($condenser);
    }

    public function testTextWithinBudgetIsReturnedVerbatim(): void
    {
        $this->aiFacade->expects(self::never())->method('chat');

        $result = $this->condenser()->fit('short text', 'question', 1000, 7);

        self::assertSame(CondensedText::STRATEGY_VERBATIM, $result->strategy);
        self::assertSame('short text', $result->text);
        self::assertNull($result->provenanceNote());
    }

    public function testOneRoundCondensesEveryChunkWithTheQuestionAsLens(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(76);

        $rows = [];
        for ($i = 1; $i <= 1200; ++$i) {
            $rows[] = sprintf('| %d | Region-%d | %d.00 |', $i, $i % 5, $i * 100);
        }
        $text = "## Sheet: Sales\n\n| ID | Region | Revenue |\n| --- | --- | --- |\n".implode("\n", $rows);

        $seenPrompts = [];
        $this->aiFacade->method('chat')->willReturnCallback(static function (array $messages) use (&$seenPrompts): array {
            $seenPrompts[] = $messages[1]['content'];

            return ['content' => 'CONDENSED PART', 'provider' => 'groq', 'model' => 'condenser-model', 'usage' => []];
        });

        // Small condenser chunks (4k tokens ≈ 10k chars of table) so the ~38k-char sheet needs several chunks.
        $configRepository = $this->configRepositoryWith([ContextFittingConfig::KEY_CONDENSER_CHUNK_TOKENS => '4000']);

        $progress = [];
        $result = $this->condenser($configRepository)->fit($text, 'Total revenue per region?', 2000, 7, static function (array $p) use (&$progress): void {
            $progress[] = $p;
        });

        self::assertSame(CondensedText::STRATEGY_CONDENSED, $result->strategy);
        self::assertSame(1, $result->levels);
        self::assertGreaterThan(1, $result->chunksPerLevel[0]);
        self::assertSame($result->chunksPerLevel[0], $result->modelCalls);
        self::assertSame($result->chunksPerLevel[0], count($progress));
        self::assertLessThanOrEqual(2000, $result->finalChars());
        self::assertStringContainsString('condensed', (string) $result->provenanceNote());

        foreach ($seenPrompts as $prompt) {
            self::assertStringContainsString('Total revenue per region?', $prompt);
            self::assertStringContainsString('DOCUMENT PART', $prompt);
        }
        // Continuation chunks carry the column header so the condenser knows what the numbers mean.
        self::assertStringContainsString('| ID | Region | Revenue |', $seenPrompts[1]);
    }

    public function testStacksASecondRoundWhenTheFirstIsStillTooLarge(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(76);

        // Condenser returns a fixed-size blob per chunk; level 1 joins N blobs
        // (still > budget), level 2 condenses those into fewer blobs.
        $this->aiFacade->method('chat')->willReturn([
            'content' => str_repeat('fact ', 200), // 1000 chars
            'provider' => 'groq', 'model' => 'condenser-model', 'usage' => [],
        ]);

        $text = str_repeat("Paragraph with plenty of prose that needs condensing before it fits.\n\n", 4000); // ~280k chars

        $result = $this->condenser()->fit($text, 'What is this about?', 1500, 7);

        self::assertSame(CondensedText::STRATEGY_CONDENSED, $result->strategy);
        self::assertSame(2, $result->levels, 'a second round is stacked on top of the first');
        self::assertCount(2, $result->chunksPerLevel);
        self::assertSame(1, $result->chunksPerLevel[1], 'level 1 output fits a single condenser chunk');
        self::assertLessThanOrEqual(1500, $result->finalChars());
    }

    public function testFallsBackToTrimWhenCondensingIsDisabled(): void
    {
        $configRepository = $this->configRepositoryWith([ContextFittingConfig::KEY_CONDENSE_ENABLED => '0']);
        $this->aiFacade->expects(self::never())->method('chat');

        $text = implode("\n", array_map(static fn (int $i): string => "line $i of the document", range(1, 500)));
        $result = $this->condenser($configRepository)->fit($text, 'q', 600, 7);

        self::assertSame(CondensedText::STRATEGY_TRIMMED, $result->strategy);
        self::assertLessThanOrEqual(600, $result->finalChars());
        self::assertStringContainsString('characters omitted', $result->text);
        self::assertStringStartsWith('line 1 of the document', $result->text);
        self::assertStringEndsWith('line 500 of the document', $result->text);
        self::assertStringContainsString('beginning and end', (string) $result->provenanceNote());
    }

    public function testFallsBackToTrimWhenTheCondenserModelFails(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(76);
        $this->aiFacade->method('chat')->willThrowException(new \RuntimeException('provider down'));

        $result = $this->condenser()->fit(str_repeat("some line\n", 2000), 'q', 800, 7);

        self::assertSame(CondensedText::STRATEGY_TRIMMED, $result->strategy);
        self::assertSame(1, $result->levels);
        self::assertLessThanOrEqual(800, $result->finalChars());
    }

    public function testNoCondenserModelMeansTrim(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);
        $this->aiFacade->expects(self::never())->method('chat');

        $result = $this->condenser()->fit(str_repeat("some line\n", 500), 'q', 300, 7);

        self::assertSame(CondensedText::STRATEGY_TRIMMED, $result->strategy);
        self::assertSame(0, $result->modelCalls);
    }

    /**
     * @param array<string, string> $overrides setting => value in the CONTEXT group
     */
    private function configRepositoryWith(array $overrides): ConfigRepository&MockObject
    {
        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->method('getValue')->willReturnCallback(
            static fn (int $ownerId, string $group, string $setting): ?string => $overrides[$setting] ?? null,
        );

        return $configRepository;
    }

    private function condenser(?ConfigRepository $configRepository = null): ContextCondenser
    {
        $config = new ContextFittingConfig($configRepository ?? $this->configRepository);
        $estimator = new TokenEstimator();

        return new ContextCondenser(
            $this->aiFacade,
            $this->modelConfigService,
            new ModelContextWindow($this->modelRepository, $estimator, $config),
            new ContextChunker(),
            $estimator,
            $config,
            $this->createMock(RateLimitService::class),
            $this->createMock(UserRepository::class),
            new NullLogger(),
        );
    }
}
